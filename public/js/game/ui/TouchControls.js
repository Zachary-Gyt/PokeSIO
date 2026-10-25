import { textEntry } from './Controls.js';

/**
 * Étape 29 : les COMMANDES TACTILES, façon console portable.
 *
 *   - à gauche : la croix directionnelle (on peut glisser le pouce d'une direction à l'autre),
 *     et les raccourcis Vélo / Pêche ;
 *   - à droite : A (Action), B (Retour ; maintenu sur la carte = courir, comme dans les jeux),
 *     et les raccourcis Menu / Chat.
 *
 * En portrait, la manette est SOUS l'écran ; en paysage, de chaque côté.
 *
 * Le principe : chaque bouton imite une TOUCHE DU CLAVIER (un faux événement "keydown" / "keyup").
 * Le jeu n'a donc rien à changer : la carte (Phaser) et les menus (Controls.js) reçoivent exactement
 * ce qu'ils recevraient d'un clavier.
 *
 * Elle s'affiche seule sur un écran tactile. Le bouton « Manette » de la barre du haut l'affiche
 * ou la cache, partout (le choix est retenu par le navigateur).
 */
const STORAGE_KEY = 'pokesio.touch';

// action -> touche imitée : [key, code, keyCode]
const KEYS = {
    up: ['ArrowUp', 'ArrowUp', 38],
    down: ['ArrowDown', 'ArrowDown', 40],
    left: ['ArrowLeft', 'ArrowLeft', 37],
    right: ['ArrowRight', 'ArrowRight', 39],
    a: [' ', 'Space', 32],
    run: ['Shift', 'ShiftLeft', 16],
    cancel: ['Backspace', 'Backspace', 8],
    menu: ['Escape', 'Escape', 27],
    bike: ['b', 'KeyB', 66],
    fish: ['f', 'KeyF', 70],
};

// Dans les menus, une direction maintenue se répète (comme une touche du clavier)
const REPEAT_DELAY = 380;
const REPEAT_EVERY = 110;

export default class TouchControls {
    constructor(game) {
        this.game = game;
        this.held = new Set();      // les touches imitées actuellement enfoncées
        this.direction = null;      // la direction de la croix
        this.repeatTimer = null;
        this.build();
        this.setVisible(this.preference());
        // Une rotation de l'écran change la place de la manette : le jeu se redimensionne
        window.addEventListener('orientationchange', () => setTimeout(() => this.refreshScale(), 300));
        // L'application passe en arrière-plan (appel, autre onglet) : on relâche tout
        document.addEventListener('visibilitychange', () => { if (document.hidden) this.releaseAll(); });
        window.addEventListener('blur', () => this.releaseAll());
    }

    // =====================================================================
    // Affichage
    // =====================================================================

    /** Affichée ou non : le choix du joueur, sinon oui sur un écran tactile */
    preference() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'on') return true;
            if (saved === 'off') return false;
        } catch (error) {
            // navigation privée stricte : pas de mémoire, on devine
        }

        return TouchControls.isTouchDevice();
    }

    static isTouchDevice() {
        return window.matchMedia?.('(pointer: coarse)').matches || navigator.maxTouchPoints > 0;
    }

    setVisible(visible, remember = false) {
        this.visible = visible;
        document.body.classList.toggle('touch-on', visible);
        this.left.hidden = !visible;
        this.right.hidden = !visible;
        this.toggleButton.classList.toggle('active', visible);
        if (remember) {
            try {
                localStorage.setItem(STORAGE_KEY, visible ? 'on' : 'off');
            } catch (error) {
                // tant pis
            }
        }
        this.releaseAll();
        this.refreshScale();
    }

    /** La taille de l'écran de jeu a changé (sans que la fenêtre change) : Phaser recalcule son échelle */
    refreshScale() {
        requestAnimationFrame(() => {
            const scale = this.game.scale;
            if (!scale) return;
            scale.getParentBounds();   // relit la taille du cadre (sinon Phaser garde l'ancienne)
            scale.refresh();
        });
    }

    // =====================================================================
    // Construction (HTML)
    // =====================================================================

    build() {
        const stage = document.querySelector('.stage');
        const screen = stage.querySelector('.screen');

        // --- À gauche : la croix, puis Vélo / Pêche
        this.left = el('div', 'touch-side touch-left');
        this.dpad = el('div', 'touch-dpad');
        for (const dir of ['up', 'left', 'right', 'down']) this.dpad.append(el('div', `touch-arrow arrow-${dir}`));
        this.dpad.append(el('div', 'touch-center'));
        const leftExtras = el('div', 'touch-extras');
        leftExtras.append(this.pill('VÉLO', () => this.tap('bike')), this.pill('PÊCHE', () => this.tap('fish')));
        this.left.append(this.dpad, leftExtras);

        // --- À droite : A et B, puis Menu / Chat
        this.right = el('div', 'touch-side touch-right');
        const buttons = el('div', 'touch-ab');
        this.buttonA = el('div', 'touch-button touch-a', 'A');
        this.buttonB = el('div', 'touch-button touch-b', 'B');
        buttons.append(this.buttonB, this.buttonA);
        const rightExtras = el('div', 'touch-extras');
        rightExtras.append(this.pill('MENU', () => this.pressMenu()));
        if (this.game.registry.get('chat')) rightExtras.append(this.pill('CHAT', () => this.pressChat()));
        this.right.append(buttons, rightExtras);

        stage.insertBefore(this.left, screen);
        stage.append(this.right);

        // --- Dans la barre du haut : Plein écran (si le navigateur sait le faire) et Manette
        const nav = document.querySelector('.bar nav');
        if (document.fullscreenEnabled || document.webkitFullscreenEnabled) {
            this.fullscreenButton = el('button', 'touch-bar-button', 'Plein écran');
            this.fullscreenButton.type = 'button';
            this.fullscreenButton.addEventListener('click', () => this.toggleFullscreen());
            nav.prepend(this.fullscreenButton);
        }
        this.toggleButton = el('button', 'touch-bar-button', 'Manette');
        this.toggleButton.type = 'button';
        this.toggleButton.title = 'Afficher / cacher les commandes tactiles';
        this.toggleButton.addEventListener('click', () => this.setVisible(!this.visible, true));
        nav.prepend(this.toggleButton);

        this.bindDpad();
        this.bindButton(this.buttonA, () => this.pressA(), () => this.release('a'));
        this.bindButton(this.buttonB, () => this.pressB(), () => this.releaseB());

        // Un appui long sur la manette ne doit ni sélectionner du texte, ni ouvrir un menu du navigateur
        for (const side of [this.left, this.right]) {
            side.addEventListener('contextmenu', (event) => event.preventDefault());
        }
    }

    pill(label, onPress) {
        const button = el('div', 'touch-pill', label);
        this.bindButton(button, onPress, () => {});
        return button;
    }

    /** Appui / relâchement d'un bouton, au doigt comme à la souris */
    bindButton(button, onDown, onUp) {
        button.addEventListener('pointerdown', (event) => {
            event.preventDefault();   // garde le clavier du téléphone ouvert pendant le chat
            button.setPointerCapture?.(event.pointerId);
            button.classList.add('pressed');
            vibrate();
            onDown();
        });
        const up = () => {
            if (!button.classList.contains('pressed')) return;
            button.classList.remove('pressed');
            onUp();
        };
        button.addEventListener('pointerup', up);
        button.addEventListener('pointercancel', up);
        button.addEventListener('lostpointercapture', up);
    }

    /** La croix : la direction suit le pouce, même s'il glisse d'une flèche à l'autre */
    bindDpad() {
        const pad = this.dpad;
        let pointer = null;
        const update = (event) => {
            const r = pad.getBoundingClientRect();
            const dx = event.clientX - (r.left + r.width / 2);
            const dy = event.clientY - (r.top + r.height / 2);
            // Une petite zone morte au centre : le pouce posé au milieu ne fait rien
            if (Math.hypot(dx, dy) < r.width * 0.12) return this.setDirection(null);
            this.setDirection(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up'));
        };
        pad.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            pointer = event.pointerId;
            pad.setPointerCapture?.(event.pointerId);
            update(event);
        });
        pad.addEventListener('pointermove', (event) => {
            if (event.pointerId === pointer) update(event);
        });
        const end = (event) => {
            if (event.pointerId !== pointer) return;
            pointer = null;
            this.setDirection(null);
        };
        pad.addEventListener('pointerup', end);
        pad.addEventListener('pointercancel', end);
        pad.addEventListener('lostpointercapture', end);
    }

    // =====================================================================
    // Ce que fait chaque bouton
    // =====================================================================

    setDirection(dir) {
        if (dir === this.direction) return;
        if (this.direction) {
            this.release(this.direction);
            this.dpad.classList.remove(`dir-${this.direction}`);
        }
        clearTimeout(this.repeatTimer);
        clearInterval(this.repeatTimer);
        this.direction = dir;
        if (!dir) return;
        this.dpad.classList.add(`dir-${dir}`);
        vibrate();
        if (textEntry.active) return;   // saisie d'un surnom : les flèches ne servent à rien
        this.press(dir);
        // Dans un menu, la flèche maintenue se répète ; sur la carte, le joueur marche tant qu'elle est enfoncée
        this.repeatTimer = setTimeout(() => {
            this.repeatTimer = setInterval(() => {
                if (this.direction === dir && !this.worldActive()) fire('keydown', dir, true);
            }, REPEAT_EVERY);
        }, REPEAT_DELAY);
    }

    pressA() {
        // Saisie d'un surnom : A valide
        if (textEntry.active) return textEntry.touch?.('ok');
        const chat = this.chat();
        if (chat?.typing) return chat.input.value.trim() ? chat.send() : chat.close();

        return this.press('a');
    }

    /** B : sur la carte, maintenu = courir ; ailleurs, Retour */
    pressB() {
        if (textEntry.active) return textEntry.touch?.('cancel');
        const chat = this.chat();
        if (chat?.typing) return chat.close();
        this.press('run');
        if (!this.worldActive()) this.tap('cancel');
    }

    releaseB() {
        this.release('run');
    }

    pressMenu() {
        if (textEntry.active) return;
        const chat = this.chat();
        if (chat?.typing) chat.close();
        this.tap('menu');
    }

    /** Le chat : l'ouvrir DIRECTEMENT (pas par une fausse touche T) pour que le clavier du téléphone apparaisse */
    pressChat() {
        const chat = this.chat();
        if (!chat || textEntry.active) return;
        if (chat.typing) {
            if (chat.input.value.trim()) chat.send();
            else chat.close();
            return;
        }
        if (this.worldActive() && chat.canOpen()) {
            this.releaseAll();
            chat.open();
            chat.input.focus();   // tout de suite, pendant l'appui du doigt (obligatoire sur iPhone)
        }
    }

    async toggleFullscreen() {
        try {
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                await (document.exitFullscreen ?? document.webkitExitFullscreen).call(document);
            } else {
                const root = document.documentElement;
                await (root.requestFullscreen ?? root.webkitRequestFullscreen).call(root);
                // Sur téléphone, on essaie de passer en paysage (refusé sans erreur par certains navigateurs)
                await screen.orientation?.lock?.('landscape').catch(() => {});
            }
        } catch (error) {
            // refusé par le navigateur
        }
        this.refreshScale();
    }

    // =====================================================================
    // Les fausses touches
    // =====================================================================

    press(action) {
        if (this.held.has(action)) return;
        this.held.add(action);
        fire('keydown', action);
    }

    release(action) {
        if (!this.held.delete(action)) return;
        fire('keyup', action);
    }

    /** Un appui bref (enfoncé puis relâché) */
    tap(action) {
        fire('keydown', action);
        setTimeout(() => fire('keyup', action), 60);
    }

    releaseAll() {
        clearTimeout(this.repeatTimer);
        clearInterval(this.repeatTimer);
        if (this.direction) this.dpad?.classList.remove(`dir-${this.direction}`);
        this.direction = null;
        for (const action of [...this.held]) this.release(action);
        document.querySelectorAll('.touch-side .pressed').forEach((b) => b.classList.remove('pressed'));
    }

    /** La carte est-elle au premier plan (pas de menu, pas de combat) ? */
    worldActive() {
        return this.game.scene.isActive('World');
    }

    chat() {
        return this.game.registry.get('chat') ?? null;
    }
}

function el(tag, className, text) {
    const e = document.createElement(tag);
    e.className = className;
    if (text) e.textContent = text;
    return e;
}

function fire(type, action, repeat = false) {
    const [key, code, keyCode] = KEYS[action];
    const event = new KeyboardEvent(type, { key, code, repeat, bubbles: true, cancelable: true });
    // Phaser lit encore l'ancien "keyCode", que le navigateur ne laisse pas choisir : on le définit à la main
    Object.defineProperty(event, 'keyCode', { get: () => keyCode });
    Object.defineProperty(event, 'which', { get: () => keyCode });
    window.dispatchEvent(event);
}

function vibrate() {
    try {
        navigator.vibrate?.(8);
    } catch (error) {
        // pas de vibration
    }
}
