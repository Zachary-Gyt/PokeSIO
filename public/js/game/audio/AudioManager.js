/**
 * LA MUSIQUE ET LES SONS du jeu (étape 12).
 *
 * Trois sortes de sons, comme dans RPG Maker / Essentials :
 *   BGM : la musique de fond, en boucle (une seule à la fois ; elle change en fondu)
 *   ME  : un jingle (soin, badge, victoire...) : la musique se met en pause, puis reprend
 *   SE  : un effet court (curseur, coup, porte...) ; plusieurs peuvent se superposer
 *
 * Les adresses viennent du serveur (config/audio.php -> GameController) :
 *   config.audio.events.bgm.battle_wild = "http://.../Battle wild.ogg"
 *   config.audio.files.bgm["route 1"]   = "http://.../Route 1.ogg"   (pour la propriété Tiled "bgm")
 * Un son absent est simplement ignoré.
 *
 * On utilise les balises <audio> du navigateur : simple, et indépendant des scènes Phaser.
 * Les navigateurs refusent de jouer un son avant le premier clic ou la première touche :
 * la musique demandée avant est mémorisée et démarre à la première action du joueur.
 *
 *   const audio = registry.get('audio');
 *   audio.bgm('battle_wild');        audio.mapBgm('Route 1');
 *   await audio.me('heal');          audio.se('cursor');
 *
 * Volumes (Options) : enregistrés dans le navigateur (localStorage), propres à chaque joueur.
 */
const STORAGE_KEY = 'pokesio.audio';
const FADE_MS = 400;
const RETURN_FADE_MS = 1200;   // la musique de la carte qui revient après un jingle (victoire...) : fondu plus doux

export default class AudioManager {
    constructor(config = {}) {
        this.files = config.files ?? { bgm: {}, me: {}, se: {} };
        this.events = config.events ?? { bgm: {}, me: {}, se: {} };
        this.music = null;        // { url, el } : la musique en cours
        this.mePlaying = false;
        this.currentMe = null;     // le jingle en cours (on peut l'arrêter en fondu : victoire)
        this.victorySeconds = config.victorySeconds ?? 4;
        this.unlocked = false;
        this.lastSe = {};

        const saved = this.load();
        this.musicVolume = saved.music ?? config.musicVolume ?? 0.6;
        this.effectsVolume = saved.effects ?? config.effectsVolume ?? 0.8;

        // Première action du joueur : le navigateur autorise enfin le son
        const unlock = () => {
            if (this.unlocked) return;
            this.unlocked = true;
            if (this.music && this.music.el.paused && !this.mePlaying) this.music.el.play().catch(() => {});
        };
        window.addEventListener('keydown', unlock);
        window.addEventListener('pointerdown', unlock);
    }

    // ------------------------------------------------------------------
    // Musique
    // ------------------------------------------------------------------

    /** Musique d'un moment du jeu (config/audio.php) : 'battle_wild', 'surf'... null = silence */
    bgm(event) {
        return this.playMusic(event ? this.events.bgm?.[event] : null);
    }

    /** Musique d'une carte : propriété Tiled "bgm" (nom de fichier), sinon la musique par défaut */
    mapBgm(name) {
        const url = (name && this.files.bgm?.[String(name).toLowerCase()]) || this.events.bgm?.map_default || null;
        if (name && !this.files.bgm?.[String(name).toLowerCase()]) {
            console.warn(`Musique "${name}" introuvable dans public/game/audio/bgm (propriété "bgm" de la carte).`);
        }
        return this.playMusic(url);
    }

    /** Change de musique en fondu (rien ne change si c'est déjà celle-là) */
    playMusic(url) {
        if (this.music?.url === url) return;
        const old = this.music;
        this.music = null;
        if (old) this.fade(old.el, 0, FADE_MS, () => old.el.pause());
        if (!url) return;

        const el = new Audio(url);
        el.loop = true;
        el.volume = 0;
        this.music = { url, el };
        if (!this.mePlaying) this.startMusic();
        // Un jingle "interruptible" (victoire) laisse la place en fondu à la nouvelle musique
        else if (this.currentMe?.interruptible) this.currentMe.fadeOut(600);
    }

    startMusic(fadeMs = FADE_MS) {
        const m = this.music;
        if (!m) return;
        m.el.play().then(() => this.fade(m.el, this.musicVolume, fadeMs)).catch(() => {
            // Pas encore autorisé (aucune action du joueur) : on réessaiera au premier clic / à la première touche
            m.el.volume = this.musicVolume;
        });
    }

    stopMusic() {
        this.playMusic(null);
    }

    // ------------------------------------------------------------------
    // Jingles et effets
    // ------------------------------------------------------------------

    /** Un jingle : la musique s'arrête pendant ce temps, puis reprend. Renvoie une promesse (fin du jingle). */
    /**
     * maxSeconds : coupe le jingle au bout de ce temps, avec un fondu (les victoires d'Essentials sont longues)
     * interruptible : une nouvelle musique demandée pendant le jingle le remplace en fondu (retour sur la carte)
     */
    me(event, { resume = true, maxSeconds = null, interruptible = false } = {}) {
        const url = this.events.me?.[event];
        if (!url || !this.unlocked) return Promise.resolve();
        this.currentMe?.fadeOut(200);   // un jingle chasse le précédent
        return new Promise((resolve) => {
            const before = this.music?.url;
            const el = new Audio(url);
            el.volume = this.musicVolume;
            this.mePlaying = true;
            this.music?.el.pause();
            let finished = false;
            let timer = null;
            const done = () => {
                if (finished) return;
                finished = true;
                clearTimeout(timer);
                // Un autre jingle a pris la suite : c'est lui qui relancera la musique
                if (this.currentMe && this.currentMe.el !== el) return resolve();
                this.currentMe = null;
                this.mePlaying = false;
                // La musique reprend en fondu ; ou, si une AUTRE musique a été demandée pendant le jingle, elle démarre
                if (this.music && (resume || this.music.url !== before)) this.startMusic(RETURN_FADE_MS);
                resolve();
            };
            const fadeOut = (ms) => {
                if (finished) return;
                this.fade(el, 0, ms, () => {
                    el.pause();
                    done();
                });
            };
            this.currentMe = { el, interruptible, fadeOut };
            if (maxSeconds) timer = setTimeout(() => fadeOut(1000), Math.max(0, maxSeconds * 1000 - 1000));
            el.addEventListener('ended', done, { once: true });
            el.addEventListener('error', done, { once: true });
            el.play().catch(done);
        });
    }

    /** Un effet court. Le même effet n'est pas rejoué plus de 12 fois par seconde (curseur maintenu...). */
    se(event, { volume = 1 } = {}) {
        const url = this.events.se?.[event];
        if (!url || this.effectsVolume <= 0) return;
        const now = performance.now();
        if (now - (this.lastSe[event] ?? 0) < 80) return;
        this.lastSe[event] = now;
        const el = new Audio(url);
        el.volume = Math.min(1, this.effectsVolume * volume);
        el.play().catch(() => {});
        return el;   // étape 20 : pour pouvoir l'arrêter (la balle de la roulette)
    }

    /** Un cri de Pokémon (adresse complète), au volume des effets */
    cry(url) {
        if (!url || this.effectsVolume <= 0) return;
        const el = new Audio(url);
        el.volume = Math.min(1, this.effectsVolume * 0.8);
        el.play().catch(() => {});
    }

    // ------------------------------------------------------------------
    // Volumes (menu Options)
    // ------------------------------------------------------------------

    setMusicVolume(v) {
        this.musicVolume = Math.max(0, Math.min(1, v));
        if (this.music) this.music.el.volume = this.musicVolume;
        this.save();
    }

    setEffectsVolume(v) {
        this.effectsVolume = Math.max(0, Math.min(1, v));
        this.save();
    }

    load() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') ?? {};
        } catch (e) {
            return {};
        }
    }

    save() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ music: this.musicVolume, effects: this.effectsVolume }));
        } catch (e) {
            // stockage indisponible (navigation privée...) : les volumes valent pour cette visite seulement
        }
    }

    /** Fondu du volume de "el" jusqu'à "to" en "ms" millisecondes */
    fade(el, to, ms, onDone) {
        clearInterval(el.__fade);
        const from = el.volume;
        const start = performance.now();
        el.__fade = setInterval(() => {
            const t = Math.min(1, (performance.now() - start) / ms);
            el.volume = Math.max(0, Math.min(1, from + (to - from) * t));
            if (t >= 1) {
                clearInterval(el.__fade);
                onDone?.();
            }
        }, 30);
    }
}
