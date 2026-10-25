import PixelText from '../ui/PixelText.js';
import { TYPE_ROWS } from '../battle/BattleUI.js';
import { loadImages } from '../battle/BattleAssets.js';
import { clickable } from '../ui/Controls.js';

/**
 * Étape 19 : l'écran du MAÎTRE DES CAPACITÉS (fond d'Essentials : Graphics/UI/Move Reminder,
 * positions reprises de UI_MoveReminder.rb).
 *
 * À gauche, toutes les capacités que le Pokémon peut connaître ; celles qu'il connaît portent leur numéro (1 à 4).
 * Action : ajouter / retirer une capacité (elle prend la place suivante). On choisit ainsi les 4 capacités
 * ET leur ordre. Retour : terminer (MenuScene demande s'il faut enregistrer).
 *
 *   const screen = new MoveTutorScreen(scene, pokemon, moves);
 *   const ids = await screen.open();   // ids choisis dans l'ordre, ou null
 */
const VISIBLE = 4;
const WHITE = { color: '#f8f8f8', shadow: '#000000' };
const DARK = { color: '#404040', shadow: '#b0b0b0' };
const TITLE = { color: '#585850', shadow: '#a8b8b8' };
const SLOT = { color: '#f83820', shadow: '#e09890' };
const CATEGORY_ROWS = { physical: 0, special: 1, status: 2 };
const T = (y) => y - 2;   // même décalage que le Résumé

export default class MoveTutorScreen extends Phaser.GameObjects.Container {
    constructor(scene, pokemon, moves) {
        super(scene, 0, 0);
        this.pokemon = pokemon;
        this.moves = moves;
        this.selected = moves.filter((m) => m.slot).sort((a, b) => a.slot - b.slot).map((m) => m.id);
        this.index = 0;
        this.top = 0;
        this.notice = null;
        this.setDepth(1500);
        scene.add.existing(this);
    }

    async open() {
        const icon = this.pokemon.sprites?.icon;
        if (icon) await loadImages(this.scene, { [`pkmn_icon_${icon}`]: icon });
        this.draw();
        return new Promise((resolve) => (this.resolve = resolve));
    }

    /** Les capacités choisies ont-elles changé (contenu ou ordre) ? */
    get changed() {
        const before = this.moves.filter((m) => m.slot).sort((a, b) => a.slot - b.slot).map((m) => m.id);
        return before.join(',') !== this.selected.join(',');
    }

    text(x, y, value, colors, options = {}) {
        const t = new PixelText(this.scene, x, T(y), value, { ...colors, ...options });
        this.add(t);
        return t;
    }

    image(x, y, key, frame) {
        const i = this.scene.add.image(x, y, key, frame).setOrigin(0, 0);
        this.add(i);
        return i;
    }

    draw() {
        this.removeAll(true);
        const s = this.scene;
        this.add(s.add.image(0, 0, s.textures.exists('tutor_bg') ? 'tutor_bg' : 'summary_bg_movedetail').setOrigin(0, 0));
        this.text(16, 14, this.notice ?? `Choisissez les capacités (${this.selected.length}/4)`, TITLE);

        // Le Pokémon et ses types (en haut à droite)
        const iconKey = `pkmn_icon_${this.pokemon.sprites?.icon}`;
        if (s.textures.exists(iconKey)) this.add(s.add.image(320, 84, iconKey).setCrop(0, 0, 64, 64).setOrigin(0.25, 0.5));
        const types = this.pokemon.types ?? [];
        types.forEach((type, i) => this.image(types.length === 1 ? 400 : 366 + 70 * i, 70, 'battle_types', `t${TYPE_ROWS[type] ?? 0}`));

        // La liste (4 lignes visibles)
        for (let i = 0; i < VISIBLE; i++) {
            const move = this.moves[this.top + i];
            if (!move) continue;
            const y = 88 + i * 64;
            this.image(12, y - 4, 'battle_types', `t${TYPE_ROWS[move.type] ?? 0}`);
            this.text(80, y, move.name, WHITE);
            this.text(112, y + 32, 'PP', DARK);
            this.text(230, y + 32, `${move.pp}/${move.maxPp}`, DARK, { align: 'right' });
            const order = this.selected.indexOf(move.id);
            if (order >= 0) this.text(16, y + 32, `n° ${order + 1}`, SLOT);
            else if (move.hm) this.text(16, y + 32, 'CS', DARK);

            const zone = s.add.zone(0, 78 + i * 64, 258, 64).setOrigin(0, 0);
            clickable(zone, {
                onHover: () => { if (this.index !== this.top + i) { this.index = this.top + i; this.draw(); } },
                onClick: () => { this.index = this.top + i; this.handleKey('ok'); },
            });
            this.add(zone);
        }
        if (s.textures.exists('tutor_cursor')) {
            this.image(0, 78 + (this.index - this.top) * 64, 'tutor_cursor').setCrop(0, 0, 258, 72);
        }

        // Détail de la capacité sous le curseur
        const move = this.moves[this.index];
        if (move) {
            this.text(272, 120, 'CATÉGORIE', WHITE);
            this.image(436, 116, 'ui_category', CATEGORY_ROWS[move.category] ?? 2);
            this.text(272, 152, 'PUISSANCE', WHITE);
            this.text(468, 152, move.category === 'status' || !move.power ? '---' : String(move.power), DARK, { align: 'center' });
            this.text(272, 184, 'PRÉCISION', WHITE);
            this.text(468, 184, move.accuracy ? `${move.accuracy}%` : '---', DARK, { align: 'center' });
            this.wrapped(272, 216, 230, 4, move.description ?? '', DARK);
        }

        // Flèches : encore des capacités en dessous / au-dessus
        if (s.textures.exists('tutor_buttons')) {
            if (this.index < this.moves.length - 1) {
                this.arrow(48, 0, () => this.handleKey('down'));
            }
            if (this.index > 0) {
                this.arrow(134, 76, () => this.handleKey('up'));
            }
        }
    }

    arrow(x, frameX, onClick) {
        const img = this.image(x, 350, 'tutor_buttons').setCrop(frameX, 0, 76, 32);
        img.x = x - frameX;
        const zone = this.scene.add.zone(x, 350, 76, 32).setOrigin(0, 0);
        clickable(zone, { onClick });
        this.add(zone);
    }

    wrapped(x, y, width, maxLines, value, colors) {
        const lines = [];
        let line = '';
        for (const word of value.split(' ')) {
            const attempt = line ? `${line} ${word}` : word;
            if (PixelText.measure(attempt) > width && line) {
                lines.push(line);
                line = word;
            } else {
                line = attempt;
            }
        }
        if (line) lines.push(line);
        lines.slice(0, maxLines).forEach((l, i) => this.text(x, y + i * 32, l, colors));
    }

    /** Un petit message à la place du titre, pendant 1,5 s */
    say(text) {
        this.notice = text;
        this.draw();
        this.scene.time.delayedCall(1500, () => {
            if (this.notice === text && this.active) { this.notice = null; this.draw(); }
        });
    }

    handleKey(action) {
        const count = this.moves.length;
        if (action === 'up' || action === 'down') {
            this.index = (this.index + (action === 'up' ? count - 1 : 1)) % count;
            if (this.index < this.top) this.top = this.index;
            if (this.index >= this.top + VISIBLE) this.top = this.index - VISIBLE + 1;
            this.draw();
        } else if (action === 'ok') {
            const id = this.moves[this.index].id;
            const at = this.selected.indexOf(id);
            if (at >= 0) {
                if (this.selected.length === 1) return this.say('Il faut garder au moins une capacité !'), true;
                this.selected.splice(at, 1);
            } else if (this.selected.length >= 4) {
                return this.say('4 capacités au plus : retirez-en une.'), true;
            } else {
                this.selected.push(id);
            }
            this.draw();
        } else if (action === 'cancel') {
            this.close(this.selected);
        }
        return true;
    }

    get wheel() {
        return true;
    }

    close(value) {
        this.destroy();
        this.resolve(value);
    }
}
