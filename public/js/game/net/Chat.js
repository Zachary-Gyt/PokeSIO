/**
 * Le CHAT textuel, quatre canaux (voir app/Http/Controllers/ChatController.php) :
 *
 *   Message               [Local]   les joueurs de la même carte
 *   /all Message          [Global]  tous les joueurs connectés
 *   /msg Pseudo Message   [MP]      un joueur (/r Message : répondre au dernier)
 *   /g Message            [Groupe]  mon groupe ; les arrivées et départs des membres s'y affichent aussi
 *
 *   T          ouvrir la zone de saisie
 *   Entrée     envoyer
 *   Échap      fermer sans envoyer
 *
 * En HTML, par-dessus le jeu (comme la boîte de dialogue), avec le cadre et la police d'Essentials.
 * Pendant la saisie, le clavier du jeu est coupé : taper "z" n'avance pas le personnage.
 * Le message part à Laravel (ChatController), qui le vérifie et l'envoie à toute la carte par Reverb.
 *
 * Les autres commandes (/inviter, /quitter, /aide... et celles des administrateurs) répondent par "reply",
 * affiché seulement chez celui qui l'a tapée.
 */
const CHANNELS = {
    local: { tag: 'Local', css: 'chat-tag-local' },
    global: { tag: 'Global', css: 'chat-tag-global' },
    group: { tag: 'Groupe', css: 'chat-tag-group' },
    private: { tag: 'MP', css: 'chat-tag-private' },
};
const LEAVE_DELAY = 6000;   // un membre du groupe qui recharge sa page ne "part" pas vraiment
const VISIBLE_SECONDS = 20;   // un message disparaît du journal après ce délai (sauf pendant la saisie)
const MAX_LINES = 6;

export default class Chat {
    constructor(game, multi, config) {
        this.game = game;
        this.multi = multi;
        this.urls = config.multiplayer ?? {};
        this.me = config.character;
        this.typing = false;
        this.mapKey = null;
        this.lines = [];

        const ui = document.getElementById('game-ui');
        this.log = document.createElement('div');
        this.log.className = 'chat-log';
        this.form = document.createElement('form');
        this.form.className = 'chat-input';
        this.form.hidden = true;
        this.input = document.createElement('input');
        this.input.maxLength = 160;   // les commandes (/msg Pseudo) s'ajoutent au message de 120 caractères
        this.input.setAttribute('aria-label', 'Message');
        // Ce qu'on voit : le texte tapé, recopié avec la police du jeu, et un curseur clignotant
        this.display = document.createElement('div');
        this.display.className = 'chat-text';
        this.form.append(this.display, this.input);
        this.input.addEventListener('input', () => this.drawInput());
        ui.append(this.log, this.form);

        this.form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.send();
        });
        this.input.addEventListener('keydown', (event) => {
            event.stopPropagation();          // rien ne va au jeu pendant la saisie
            if (event.key === 'Escape') this.close();
        });
        window.addEventListener('keydown', (event) => {
            if (!this.typing && (event.key === 't' || event.key === 'T') && this.canOpen()) {
                event.preventDefault();
                this.open();
            }
        });

        multi.on('chat', (message) => this.add(message));
        // Mon groupe : changements (arrivée, départ, nouveau chef...) et membres qui se connectent
        multi.on('group', (data) => {
            this.groupChangedAt = Date.now();   // l'arrivée dans le canal qui suit n'est pas une "connexion"
            if (data.text) this.add({ name: '', text: data.text, channel: 'group', system: true });
        });
        this.leaving = new Map();
        this.groupMembers = new Set();   // les membres de mon groupe vus en ligne (leurs connexions s'affichent en [Groupe])
        multi.on('group_presence', ({ type, member }) => {
            if (member) this.groupMembers.add(member.id);
            if (type !== 'here') this.presence('group', type, member);
        });
        multi.on('group', () => this.groupMembers.clear());
        // Tous les joueurs : "Ondine s'est connecté." (sauf les membres de mon groupe, déjà annoncés en [Groupe]).
        // Un membre rejoint le canal "online" juste avant celui du groupe : on attend un peu avant de décider.
        multi.on('online_presence', ({ type, member }) => {
            if (!member) return;
            setTimeout(() => { if (!this.groupMembers.has(member.id)) this.presence('online', type, member); }, 2000);
        });
        this.addStyles();
        setInterval(() => this.render(), 1000);
    }

    /**
     * Un joueur se connecte ou se déconnecte. scope : 'group' (membre de mon groupe) ou 'online' (tout joueur).
     * Un départ suivi d'un retour rapide (page rechargée) n'est pas annoncé.
     */
    presence(scope, type, member) {
        if (!member || member.id === this.me.id) return;
        const key = `${scope}:${member.id}`;
        const line = (text) => this.add(scope === 'group'
            ? { name: '', text, channel: 'group', system: true }
            : { name: '', text, info: true });
        if (type === 'leaving') {
            clearTimeout(this.leaving.get(key));
            this.leaving.set(key, setTimeout(() => {
                this.leaving.delete(key);
                line(`${member.name} s'est déconnecté.`);
            }, LEAVE_DELAY));
        } else if (type === 'joining') {
            if (scope === 'group' && Date.now() - (this.groupChangedAt ?? 0) < 8000) return;   // il vient d'entrer dans le groupe
            if (this.leaving.has(key)) {
                clearTimeout(this.leaving.get(key));
                this.leaving.delete(key);
                return;
            }
            line(`${member.name} s'est connecté.`);
        }
    }

    /** Les couleurs des canaux (une seule fois) */
    addStyles() {
        if (document.getElementById('chat-channel-styles')) return;
        const style = document.createElement('style');
        style.id = 'chat-channel-styles';
        style.textContent = `
            .chat-tag { margin-right: 4px; }
            .chat-tag-local { color: #d8d8d8; }
            .chat-tag-global { color: #f8a838; }
            .chat-tag-group { color: #70e070; }
            .chat-tag-private { color: #f080f0; }
            .chat-line.chat-private .chat-text-body { color: #f8c8f8; }
            .chat-line.chat-group .chat-text-body { color: #c8f8c8; }
            .chat-line.chat-info { color: #a8c8f0; font-style: italic; }
        `;
        document.head.appendChild(style);
    }

    /** Pas de chat sans Reverb, ni par-dessus une boîte de dialogue de PNJ */
    canOpen() {
        return this.multi.connected && !this.game.registry.get('dialog').isOpen();
    }

    /** Arrivée sur une carte : on affiche les derniers messages de cette carte */
    async enterMap(mapKey) {
        if (this.mapKey === mapKey) return;
        this.mapKey = mapKey;
        this.lines = [];
        if (!this.urls.chat) return;
        try {
            const response = await fetch(`${this.urls.chat}?map=${encodeURIComponent(mapKey)}`, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            // Les anciens messages sont affichés, mais déjà "vieux" : ils ne restent pas à l'écran
            for (const m of data.messages ?? []) this.lines.push({ ...m, shownAt: 0 });
            this.render();
        } catch (error) {
            // pas d'historique : tant pis
        }
    }

    /** Recopie le texte tapé dans la zone visible */
    drawInput() {
        const value = this.input.value;
        if (!value) {
            this.display.innerHTML = '<span class="chat-caret">_</span><span class="chat-placeholder"> Message... (/all, /g, /msg Pseudo, /aide)</span>';
            return;
        }
        const text = document.createElement('span');
        text.textContent = value;
        const caret = document.createElement('span');
        caret.className = 'chat-caret';
        caret.textContent = '_';
        this.display.replaceChildren(text, caret);
        this.display.scrollLeft = this.display.scrollWidth; // les longs messages défilent vers la gauche
    }

    open() {
        this.typing = true;
        this.form.hidden = false;
        this.input.value = '';
        this.drawInput();
        // Le clavier du jeu est coupé pendant la saisie (et les touches enfoncées sont relâchées)
        this.game.input.keyboard.enabled = false;
        for (const scene of this.game.scene.getScenes(true)) scene.input?.keyboard?.resetKeys();
        setTimeout(() => this.input.focus(), 0);
        this.render();
    }

    close() {
        this.typing = false;
        this.form.hidden = true;
        this.input.blur();
        this.game.input.keyboard.enabled = true;
        this.render();
    }

    async send() {
        const text = this.input.value.trim();
        this.close();
        if (!text || !this.mapKey) return;
        try {
            const response = await fetch(this.urls.chatSend, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ map: this.mapKey, text }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.add({ name: '', text: data.message ?? 'Message non envoyé.', system: true });
            } else if (data.reply) {
                // Réponse à une commande d'administrateur : affichée seulement ici
                for (const line of data.reply) this.add({ name: '', text: line, system: true });
            }
        } catch (error) {
            this.add({ name: '', text: 'Message non envoyé (connexion perdue).', system: true });
        }
    }

    add(message) {
        this.lines.push({ ...message, shownAt: Date.now() });
        this.lines = this.lines.slice(-20);
        this.render();
    }

    /** Le journal : les messages récents (tous les derniers pendant la saisie) */
    render() {
        const now = Date.now();
        const visible = this.lines
            .filter((l) => this.typing || now - l.shownAt < VISIBLE_SECONDS * 1000)
            .slice(-(this.typing ? MAX_LINES * 2 : MAX_LINES));   // plus de lignes pendant la saisie (/aide)

        this.log.replaceChildren(...visible.map((l) => {
            const line = document.createElement('div');
            const channel = CHANNELS[l.channel] ? l.channel : (l.system || l.info ? null : 'local');
            line.className = (l.info ? 'chat-line chat-info' : l.system ? 'chat-line chat-system' : 'chat-line') + (channel ? ` chat-${channel}` : '');
            // [Local] [Global] [Groupe] [MP]
            if (channel) {
                const tag = document.createElement('span');
                tag.className = `chat-tag ${CHANNELS[channel].css}`;
                tag.textContent = `[${CHANNELS[channel].tag}]`;
                line.appendChild(tag);
            }
            if (l.name) {
                const name = document.createElement('span');
                // Les titres : [Admin] (administrateur), [Professeur] (grade Professeur, sans privilège)
                const title = l.admin ? 'Admin' : l.professor ? 'Professeur' : null;
                const mine = l.id === this.me.id;
                name.className = (mine ? 'chat-name chat-me' : 'chat-name') + (l.admin ? ' chat-admin' : l.professor ? ' chat-professor' : '');
                // Message privé : "À Ondine" (envoyé) ou "De Sacha" (reçu)
                const who = channel === 'private' ? (mine ? `À ${l.to}` : `De ${l.name}`) : l.name;
                name.textContent = title && !(channel === 'private' && mine) ? `[${title}] ${who} : ` : `${who} : `;
                line.appendChild(name);
            }
            const body = document.createElement('span');
            body.className = 'chat-text-body';
            body.textContent = l.text;   // textContent : jamais de HTML injecté
            line.appendChild(body);
            return line;
        }));
        this.log.hidden = visible.length === 0;
    }
}
