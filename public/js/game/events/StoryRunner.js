import { api } from '../menu/Api.js';

/**
 * Étape 24 : joue les ÉVÉNEMENTS du scénario sur la carte (fichiers resources/scenario/*.txt).
 *
 * C'est le SERVEUR qui exécute le script (App\Scenario\EventRunner) : il donne les objets, les Pokémon,
 * lève les drapeaux... et renvoie une liste d'ÉTAPES à montrer, dans l'ordre :
 *   say (texte), me / se / music / cry (sons), move / approach / face (déplacements), exclaim ("!"),
 *   hide / show, wait, fade (fondu), shake, nickname (surnom), party, bike, teleport.
 * Puis, s'il le faut, ce qu'il attend : une réponse (question, menu) ou un combat. On le montre au
 * joueur et on renvoie la réponse au serveur (POST evenements/suite), qui continue l'événement.
 *
 * Qui déclenche un événement (propriété Tiled "event") :
 *   - un PNJ (calque npcs) ou un panneau (calque signs) : quand on lui parle ;
 *   - une zone du calque "events" : trigger = step (on marche dessus, par défaut),
 *     action (on la regarde et on appuie sur Action) ou auto (en arrivant sur la carte).
 * Les propriétés show_if / hide_if (une condition) montrent ou cachent un objet selon le scénario.
 */
const DIRS = { down: { x: 0, y: 1 }, left: { x: -1, y: 0 }, right: { x: 1, y: 0 }, up: { x: 0, y: -1 } };

export default class StoryRunner {
    constructor(scene) {
        this.scene = scene;
        this.running = false;
        this.npc = null;            // le PNJ de l'événement ("ce" dans le script)
        this.waitingBattle = false; // un combat de l'événement est en cours
        this.dark = false;          // l'écran a été mis au noir (fondu noir)
        this.hidden = new Set();
        this.ready = false;         // l'état (objets cachés) est arrivé du serveur
    }

    get urls() {
        return this.scene.registry.get('config').menu;
    }

    // ------------------------------------------------------------------
    // Arrivée sur la carte
    // ------------------------------------------------------------------

    /** Les objets cachés pour ce joueur, puis les événements "auto" de la carte */
    async load() {
        const scene = this.scene;
        const inBattle = Boolean(scene.registry.get('config').battle?.active);   // page rechargée en plein combat
        const r = await api.get(`${this.urls.events}?map=${encodeURIComponent(scene.mapKey)}`);
        if (!scene.sys.isActive() && !scene.sys.isSleeping() && !scene.sys.isPaused()) return;
        this.applyHidden(r.ok ? r.data.hidden ?? [] : []);
        this.ready = true;
        if (r.ok && r.data.battle) {
            // Page rechargée pendant le combat d'un événement : l'événement reprendra à la fin du combat
            this.running = true;
            this.waitingBattle = true;
            return;
        }
        if (inBattle) return;
        for (const zone of scene.eventZones.filter((z) => z.props.trigger === 'auto')) {
            if (this.isHidden(zone)) continue;
            await this.waitUntilFree();
            await this.start(zone.id);
        }
    }

    /** Attend que le joueur soit libre (fin du fondu d'arrivée, d'une boîte de dialogue...) */
    waitUntilFree() {
        return new Promise((resolve) => {
            const check = () => {
                const s = this.scene;
                if (!s.sys.isActive()) return;   // carte quittée : on abandonne
                if (!s.cutscene && !s.isMoving && !s.dialog.isOpen() && !this.running) resolve();
                else s.time.delayedCall(100, check);
            };
            check();
        });
    }

    /** Un objet du scénario (show_if / hide_if) est-il caché ? */
    isHidden(obj) {
        return obj?.id != null && this.hidden.has(obj.id);
    }

    /** Montre / cache les PNJ et les zones qui ont une condition (show_if / hide_if) */
    applyHidden(ids) {
        this.hidden = new Set(ids);
        for (const npc of this.scene.npcs) {
            if (npc.conditional) npc.setHidden(this.hidden.has(npc.objectId));
        }
    }

    // ------------------------------------------------------------------
    // Un événement
    // ------------------------------------------------------------------

    /** Lance l'événement de l'objet Tiled n° objectId (npc = le PNJ à qui l'on parle, s'il y en a un) */
    async start(objectId, npc = null) {
        if (this.running) return;
        const scene = this.scene;
        this.running = true;
        this.npc = npc;
        scene.cutscene = true;
        scene.wasMoving = false;
        scene.stand();
        const data = await scene.postJson(this.urls.eventStart, { map: scene.mapKey, object: objectId });
        await this.handle(data);
    }

    /** Une réponse du serveur : les étapes à jouer, puis ce qu'il attend */
    async handle(data) {
        if (!data || data.message || !Array.isArray(data.steps)) {
            if (data?.message) await this.say(data.message, '');
            return this.finish();
        }
        for (const step of data.steps) {
            if (await this.play(step) === 'teleport') {
                this.running = false;   // la carte va redémarrer
                return;
            }
        }
        if (data.hidden) this.applyHidden(data.hidden);
        if (data.wait) return this.waitFor(data.wait);
        this.finish();
    }

    async waitFor(wait) {
        const scene = this.scene;
        if (wait.type === 'question') {
            const yes = await scene.askChoice(wait.text, ['Oui', 'Non'], 1);
            return this.resume({ choice: yes === 0 ? 0 : 1 });
        }
        if (wait.type === 'menu') {
            const options = wait.options ?? [];
            const index = await scene.askChoice(wait.text, options, options.length - 1);
            return this.resume({ choice: index ?? options.length - 1 });
        }
        if (wait.type === 'battle') {
            this.waitingBattle = true;
            // Un dresseur de la carte : il ne défiera plus le joueur s'il est battu
            scene.battleNpc = wait.trainer ? scene.npcs.find((n) => n.trainerSlug === wait.trainer) ?? null : null;
            scene.startBattle({ events: wait.events ?? [], battle: wait.battle });
            return;
        }
        this.finish();
    }

    /** Fin du combat de l'événement (appelé par la carte quand elle reprend la main) */
    afterBattle() {
        if (!this.waitingBattle) return false;
        this.waitingBattle = false;
        this.scene.cutscene = true;
        this.resume({});
        return true;
    }

    async resume(body) {
        const data = await this.scene.postJson(this.urls.eventResume, body);
        await this.handle(data);
    }

    finish() {
        const scene = this.scene;
        if (this.dark) {
            this.dark = false;
            scene.cameras.main.fadeIn(300, 0, 0, 0);
        }
        this.running = false;
        this.npc = null;
        scene.cutscene = false;
        scene.input.keyboard.resetKeys();   // la touche qui a fermé le dernier texte n'agit pas sur la carte
    }

    // ------------------------------------------------------------------
    // Les étapes
    // ------------------------------------------------------------------

    async play(step) {
        const scene = this.scene;
        const audio = scene.audio;
        switch (step.type) {
            case 'say':
                await Promise.all([step.me ? this.jingle(step.me) : null, this.say(step.text, step.name ?? '')]);
                return null;
            case 'me':
                if (step.wait === false) this.jingle(step.name);
                else await this.jingle(step.name);
                return null;
            case 'se':
                this.effect(step.name);
                return null;
            case 'music':
                if (!step.name) scene.playMapMusic();
                else if (audio) {
                    const url = audio.files.bgm?.[step.name.toLowerCase()] ?? audio.events.bgm?.[step.name];
                    if (url) audio.playMusic(url);
                    else console.warn(`Scénario : musique "${step.name}" introuvable dans public/game/audio/bgm.`);
                }
                return null;
            case 'cry':
                audio?.cry(step.url);
                await scene.delay(400);
                return null;
            case 'move':
                await this.move(step.target, step.path);
                return null;
            case 'approach':
                await this.approach(step.target, step.to);
                return null;
            case 'face':
                this.face(step.target, step.dir ?? this.directionTo(step.target, step.toward));
                await scene.delay(80);
                return null;
            case 'exclaim': {
                const who = this.find(step.target);
                if (who) await new Promise((resolve) => scene.showExclamation(who, resolve));
                return null;
            }
            case 'hide':
            case 'show': {
                const who = this.find(step.target);
                if (who?.setHidden) who.setHidden(step.type === 'hide');
                return null;
            }
            case 'wait':
                await scene.delay(step.ms ?? 0);
                return null;
            case 'fade':
                await this.fade(step.to === 'black');
                return null;
            case 'shake':
                scene.cameras.main.shake(450, 0.008);
                await scene.delay(450);
                return null;
            case 'nickname':
                await scene.openOverlayAsync({ type: 'nickname', pokemon: step.pokemon });
                scene.cutscene = true;
                return null;
            case 'party':
                await scene.refreshField();
                return null;
            case 'bike':
                scene.field.bike = true;
                return null;
            case 'teleport':
                scene.scriptTeleport(step);
                return 'teleport';
            default:
                console.warn('Scénario : étape inconnue', step);
                return null;
        }
    }

    say(text, name) {
        return new Promise((resolve) => this.scene.dialog.open(text, name, resolve));
    }

    /** Jingle : un moment de config/audio.php (item, heal...) ou un nom de fichier (dossiers ME, BGM, SE) */
    jingle(name) {
        const audio = this.scene.audio;
        if (!audio || !name) return Promise.resolve();
        if (audio.events.me?.[name]) return audio.me(name);
        const key = String(name).toLowerCase();
        const url = audio.files.me?.[key] ?? audio.files.bgm?.[key] ?? audio.files.se?.[key];
        if (!url) {
            console.warn(`Scénario : jingle "${name}" introuvable dans public/game/audio.`);
            return Promise.resolve();
        }
        audio.events.me[`file:${key}`] = url;   // me() lit un moment : on en crée un pour ce fichier
        return audio.me(`file:${key}`);
    }

    effect(name) {
        const audio = this.scene.audio;
        if (!audio || !name) return;
        if (audio.events.se?.[name]) return audio.se(name);
        const key = String(name).toLowerCase();
        const url = audio.files.se?.[key] ?? audio.files.me?.[key];
        if (!url) return console.warn(`Scénario : son "${name}" introuvable dans public/game/audio/se.`);
        audio.events.se[`file:${key}`] = url;
        audio.se(`file:${key}`);
    }

    fade(toBlack) {
        const cam = this.scene.cameras.main;
        this.dark = toBlack;
        return new Promise((resolve) => {
            if (toBlack) {
                cam.fadeOut(400, 0, 0, 0);
                cam.once('camerafadeoutcomplete', resolve);
            } else {
                cam.fadeIn(400, 0, 0, 0);
                cam.once('camerafadeincomplete', resolve);
            }
        });
    }

    // ------------------------------------------------------------------
    // Déplacements : "joueur", "ce" (le PNJ de l'événement) ou le nom d'un objet Tiled
    // ------------------------------------------------------------------

    /** Le PNJ (ou le joueur) désigné dans le script */
    find(target) {
        const scene = this.scene;
        if (target === 'joueur') return { isPlayer: true, sprite: scene.player, tile: scene.tile };
        if (target === 'ce') return this.npc;
        const npc = scene.npcs.find((n) => n.objectName === target);
        if (!npc) console.warn(`Scénario : PNJ "${target}" introuvable (le nom de l'objet dans Tiled).`);
        return npc ?? null;
    }

    async move(target, path) {
        const who = this.find(target);
        if (!who) return;
        for (const dir of path) {
            if (who.isPlayer) await this.scene.scriptedStep(dir);
            else await new Promise((resolve) => who.stepTo(dir, resolve));
        }
        if (!who.isPlayer) who.stopWalking();
    }

    /** Marche en ligne droite (horizontale puis verticale) jusqu'à la case voisine d'une autre cible */
    async approach(target, to) {
        const who = this.find(target);
        const goal = this.find(to);
        if (!who || !goal) return;
        const from = who.isPlayer ? this.scene.tile : who.tile;
        const dx = goal.tile.x - from.x;
        const dy = goal.tile.y - from.y;
        const path = [];
        // On s'arrête juste à côté : sur l'axe le plus long, une case avant
        if (Math.abs(dx) >= Math.abs(dy)) {
            for (let i = 0; i < Math.abs(dy); i++) path.push(dy > 0 ? 'down' : 'up');
            for (let i = 0; i < Math.abs(dx) - 1; i++) path.push(dx > 0 ? 'right' : 'left');
        } else {
            for (let i = 0; i < Math.abs(dx); i++) path.push(dx > 0 ? 'right' : 'left');
            for (let i = 0; i < Math.abs(dy) - 1; i++) path.push(dy > 0 ? 'down' : 'up');
        }
        await this.move(target, path);
        this.face(target, this.directionTo(target, to));
        this.face(to, this.directionTo(to, target));
    }

    face(target, dir) {
        const who = this.find(target);
        if (!who || !DIRS[dir]) return;
        if (who.isPlayer) this.scene.scriptedFace(dir);
        else who.face(dir);
    }

    /** La direction de la cible a vers la cible b */
    directionTo(a, b) {
        const from = this.find(a);
        const to = this.find(b);
        if (!from || !to) return 'down';
        const dx = to.tile.x - from.tile.x;
        const dy = to.tile.y - from.tile.y;
        if (Math.abs(dx) > Math.abs(dy)) return dx > 0 ? 'right' : 'left';
        return dy > 0 ? 'down' : 'up';
    }
}

