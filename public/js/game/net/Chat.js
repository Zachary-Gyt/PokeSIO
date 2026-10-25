/**
 * Le CHAT textuel : les messages des joueurs présents sur la même carte.
 *
 *   T          ouvrir la zone de saisie
 *   Entrée     envoyer
 *   Échap      fermer sans envoyer
 *
 * En HTML, par-dessus le jeu (comme la boîte de dialogue), avec le cadre et la police d'Essentials.
 * Pendant la saisie, le clavier du jeu est coupé : taper "z" n'avance pas le personnage.
 * Le message part à Laravel (ChatController), qui le vérifie et l'envoie à toute la carte par Reverb.
 *
 * Étape 10 : un texte qui commence par "/" est une commande d'administrateur (/aide pour la liste).
 * La réponse du serveur ("reply") s'affiche seulement chez celui qui l'a tapée.
 */
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
        this.input.maxLength = 120;
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
        setInterval(() => this.render(), 1000);
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
            this.display.innerHTML = '<span class="chat-caret">_</span><span class="chat-placeholder"> Message... (Entrée : envoyer, Échap : fermer)</span>';
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
            line.className = l.system ? 'chat-line chat-system' : 'chat-line';
            if (l.name) {
                const name = document.createElement('span');
                // Les titres : [Admin] (administrateur), [Professeur] (grade Professeur, sans privilège)
                const title = l.admin ? 'Admin' : l.professor ? 'Professeur' : null;
                name.className = (l.id === this.me.id ? 'chat-name chat-me' : 'chat-name') + (l.admin ? ' chat-admin' : l.professor ? ' chat-professor' : '');
                name.textContent = title ? `[${title}] ${l.name} : ` : `${l.name} : `;
                line.appendChild(name);
            }
            line.appendChild(document.createTextNode(l.text));   // textContent : jamais de HTML injecté
            return line;
        }));
        this.log.hidden = visible.length === 0;
    }
}
