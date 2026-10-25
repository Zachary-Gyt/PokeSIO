import PixelText, { COLORS } from '../ui/PixelText.js';
import { createWindow } from '../ui/WindowSkin.js';
import { clickable, textEntry } from '../ui/Controls.js';
import { loadImages } from '../battle/BattleAssets.js';

/**
 * Étape 19 : l'écran de SURNOM (fond d'Essentials : Graphics/UI/Naming/bg.png).
 *
 *   const name = await new NamingScreen(scene, pokemon).open();
 *   // le texte tapé ('' = pas de surnom), ou null si le joueur annule
 *
 * On tape au clavier : un <input> invisible reçoit les lettres (accents compris, et le clavier
 * du téléphone s'ouvre). Entrée : valider ; Échap : annuler. Les boutons OK / ANNULER marchent à la souris.
 * Pendant la saisie, les touches du jeu sont coupées (textEntry.active).
 */
const MAX = 12;
const CELL = 26;
const ENTRY_X = 104;
const ALLOWED = /[\p{L}\p{N} .'’\-!?♂♀]/u;

export default class NamingScreen extends Phaser.GameObjects.Container {
    /** pokemon : { name, speciesName, nickname, sprites: { icon } } */
    constructor(scene, pokemon, { max = MAX, depth = 2100 } = {}) {
        super(scene, 0, 0);
        this.pokemon = pokemon;
        this.max = max;
        this.value = pokemon.nickname ?? '';
        this.setDepth(depth);
        scene.add.existing(this);
    }

    async open() {
        const s = this.scene;
        const icon = this.pokemon.sprites?.icon;
        if (icon) await loadImages(s, { [`pkmn_icon_${icon}`]: icon });

        this.add(s.add.image(0, 0, s.textures.exists('naming_bg') ? 'naming_bg' : 'ui_dialog').setOrigin(0, 0));
        if (icon && s.textures.exists(`pkmn_icon_${icon}`)) {
            this.icon = s.add.image(58, 48, `pkmn_icon_${icon}`).setCrop(0, 0, 64, 64).setOrigin(0.25, 0.5);
            this.add(this.icon);
        }
        this.add(new PixelText(s, ENTRY_X, 10, `Surnom de ${this.pokemon.speciesName} ?`, COLORS.dark));
        this.cells = [];
        for (let i = 0; i < this.max; i++) {
            const x = ENTRY_X + i * CELL;
            const line = s.add.rectangle(x + 2, 80, CELL - 4, 4, 0x8890a0).setOrigin(0, 0);
            const letter = new PixelText(s, x + CELL / 2, 44, '', { ...COLORS.dark, align: 'center' });
            this.add([line, letter]);
            this.cells.push({ line, letter });
        }

        // Aide, et boutons OK / ANNULER
        const help = createWindow(s, 'ui_choice', 480, 112).setPosition(16, 150);
        this.add(help);
        this.add(new PixelText(s, 36, 164, 'Tapez le surnom au clavier.', COLORS.dark));
        this.add(new PixelText(s, 36, 196, 'Entrée : valider   Échap : annuler', COLORS.dark));
        this.add(new PixelText(s, 36, 228, `Vide : garder le nom « ${this.pokemon.speciesName} »`, { ...COLORS.dark, font: 'narrow' }));
        this.button(16, 290, 'OK', () => this.finish(this.value.trim()));
        this.button(264, 290, 'ANNULER', () => this.finish(null));

        this.blink = s.time.addEvent({ delay: 400, loop: true, callback: () => { this.caretOn = !this.caretOn; this.draw(); } });
        this.caretOn = true;
        this.draw();
        this.startTyping();
        return new Promise((resolve) => (this.resolve = resolve));
    }

    button(x, y, label, onClick) {
        const s = this.scene;
        const win = createWindow(s, 'ui_choice', 232, 64).setPosition(x, y);
        const text = new PixelText(s, x + 116, y + 16, label, { ...COLORS.dark, align: 'center' });
        clickable(win, { onClick });
        this.add([win, text]);
    }

    /** Le texte, une lettre par case ; la case suivante clignote */
    draw() {
        const chars = [...this.value];
        this.cells.forEach((cell, i) => {
            cell.letter.setText(chars[i] ?? '');
            const caret = i === chars.length && this.caretOn;
            cell.line.setFillStyle(caret ? 0x384050 : 0x8890a0);
        });
    }

    /** Un <input> invisible reçoit le texte (et ouvre le clavier d'un téléphone) */
    startTyping() {
        const game = this.scene.game;
        textEntry.active = true;
        game.input.keyboard.enabled = false;
        for (const scene of game.scene.getScenes(true)) scene.input?.keyboard?.resetKeys();

        const input = document.createElement('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.value = this.value;
        input.setAttribute('aria-label', 'Surnom');
        input.style.cssText = 'position:fixed;left:0;top:0;width:1px;height:1px;opacity:0;border:0;padding:0;';
        document.body.appendChild(input);
        this.input = input;

        input.addEventListener('input', () => {
            const clean = [...input.value].filter((c) => ALLOWED.test(c)).slice(0, this.max).join('');
            if (clean !== input.value) input.value = clean;
            this.value = clean;
            this.caretOn = true;
            this.draw();
        });
        input.addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Enter') { event.preventDefault(); this.finish(this.value.trim()); }
            if (event.key === 'Escape') { event.preventDefault(); this.finish(null); }
        });
        // Étape 29 : avec la manette tactile, A valide et B annule
        textEntry.touch = (action) => this.finish(action === 'ok' ? this.value.trim() : null);
        // Un clic dans le jeu ne doit pas faire perdre la saisie (et sur téléphone, il rouvre le clavier :
        // focus() tout de suite, pendant l'appui du doigt, sinon l'iPhone refuse d'afficher le clavier)
        this.refocus = () => {
            this.input?.focus();
            setTimeout(() => this.input?.focus(), 0);
        };
        this.scene.input.on('pointerdown', this.refocus);
        input.addEventListener('blur', this.refocus);
        setTimeout(() => input.focus(), 0);
    }

    finish(value) {
        if (!this.input) return;
        const input = this.input;
        this.input = null;
        input.removeEventListener('blur', this.refocus);
        input.remove();
        this.scene.input.off('pointerdown', this.refocus);
        textEntry.active = false;
        textEntry.touch = null;
        const game = this.scene.game;
        game.input.keyboard.enabled = true;
        for (const scene of game.scene.getScenes(true)) scene.input?.keyboard?.resetKeys();
        this.blink.remove();
        this.scene.registry.get('audio')?.se(value === null ? 'cancel' : 'decision');
        this.destroy();
        this.resolve(value);
    }
}
