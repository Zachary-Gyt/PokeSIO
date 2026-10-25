import PixelText from '../ui/PixelText.js';
import { TYPE_ROWS } from '../battle/BattleUI.js';
import { loadImages } from '../battle/BattleAssets.js';
import { clickable } from '../ui/Controls.js';
import { ballName, itemIconKey, itemIconUrl } from './MenuAssets.js';

/**
 * L'écran RÉSUMÉ d'Essentials (Graphics/UI/Summary), positions reprises de UI_Summary.rb :
 *   page 1 INFOS, page 2 MÉMO DRESSEUR, page 3 STATS, page 4 CAPACITÉS (+ détail d'une capacité).
 *
 *   await new SummaryScreen(scene, party, index).open();                       // consulter
 *   const slot = await new SummaryScreen(scene, [pokemon], 0, { learn: move }).open();
 *        // apprentissage : 0..3 = capacité à oublier, 4 ou null = ne pas apprendre
 *
 * Touches : gauche/droite = page, haut/bas = Pokémon suivant, Action (page 4) = détail, Retour = fermer.
 */

// Couleurs d'Essentials
const WHITE = { color: '#f8f8f8', shadow: '#686868' };
const DARK = { color: '#404040', shadow: '#b0b0b0' };
const RED = { color: '#f83820', shadow: '#e09890' };
const GREY = { color: '#c0c8d0', shadow: '#d0d8e0' };
const PP_COLORS = [DARK, { color: '#f8c000', shadow: '#906800' }, { color: '#f88820', shadow: '#904818' }, { color: '#f84848', shadow: '#883030' }];

const PAGE_TITLES = ['INFOS', 'MÉMO DRESSEUR', 'STATS', 'CAPACITÉS'];
const STAT_ROWS = [['attack', 'Attaque', 126], ['defense', 'Défense', 158], ['sp_attack', 'Atq. Spé.', 190], ['sp_defense', 'Déf. Spé.', 222], ['speed', 'Vitesse', 254]];
const CATEGORY_ROWS = { physical: 0, special: 1, status: 2 };

// Essentials écrit ses textes un peu plus bas que notre PixelText : on compense de 2 pixels
const T = (y) => y - 2;

export default class SummaryScreen extends Phaser.GameObjects.Container {
    constructor(scene, party, index = 0, options = {}) {
        super(scene, 0, 0);
        this.party = party;
        this.index = index;
        this.learnMove = options.learn ?? null;
        this.page = this.learnMove ? 4 : 1;
        this.moveIndex = 0;
        this.mode = this.learnMove ? 'learn' : 'view'; // view | detail | learn
        this.setDepth(1500);
        scene.add.existing(this);
        this.promise = new Promise((resolve) => (this.resolve = resolve));
    }

    async open() {
        await this.loadAssets();
        this.draw();
        return this.promise;
    }

    get pokemon() {
        return this.party[this.index];
    }

    async loadAssets() {
        const images = {};
        for (const p of this.party) {
            if (p.sprites.front) images[`pkmn_${p.sprites.front}`] = p.sprites.front;
            if (p.sprites.icon) images[`pkmn_icon_${p.sprites.icon}`] = p.sprites.icon;
            // Étape 16 : l'icône de l'objet tenu
            if (p.item?.icon) images[itemIconKey(p.item.icon)] = itemIconUrl(this.scene.registry.get('config').basePath, p.item.icon);
        }
        await loadImages(this.scene, images);
    }

    // ---------------------------------------------------------------------

    draw() {
        this.removeAll(true);
        const p = this.pokemon;
        const detail = this.mode === 'detail' || this.mode === 'learn';
        // Étape 18 : un Œuf a sa propre page (Essentials : bg_egg, "Suivi de l'Œuf")
        if (p.egg && !detail) {
            this.add(this.scene.add.image(0, 0, this.scene.textures.exists('summary_bg_egg') ? 'summary_bg_egg' : 'summary_bg_2').setOrigin(0, 0));
            this.drawEgg();
            return;
        }
        const bg = this.mode === 'learn' ? 'summary_bg_learnmove' : this.mode === 'detail' ? 'summary_bg_movedetail' : `summary_bg_${this.page}`;
        this.add(this.scene.add.image(0, 0, bg).setOrigin(0, 0));

        if (detail) {
            this.drawMoveSelection();
        } else {
            this.drawCommon();
            [null, this.drawInfo, this.drawMemo, this.drawSkills, this.drawMoves][this.page].call(this);
            this.drawTabs();
        }
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

    /** Ce qui est sur toutes les pages : sprite, Ball, nom, niveau, sexe, objet tenu */
    drawCommon() {
        const p = this.pokemon;
        this.text(26, 22, PAGE_TITLES[this.page - 1], WHITE);
        if (this.scene.textures.exists(`pkmn_${p.sprites.front}`)) {
            this.add(this.scene.add.image(104, 206, `pkmn_${p.sprites.front}`));
        }
        const ball = `summary_ball_${ballName(p.ball)}`;
        if (this.scene.textures.exists(ball)) this.image(14, 60, ball);
        if (p.shiny) this.image(2, 134, 'battle_shiny');

        this.text(46, 68, p.name, WHITE);
        this.text(46, 98, String(p.level), DARK);
        if (p.gender === 'male') this.text(178, 68, '♂', { color: '#1870d8', shadow: '#88a8d0' });
        if (p.gender === 'female') this.text(178, 68, '♀', { color: '#f83820', shadow: '#e09890' });

        this.text(66, 324, 'Objet', WHITE);
        if (p.item) this.text(16, 358, p.item.name, DARK);
        // Étape 16 : son icône dans le petit cadre (Essentials : ItemIconSprite centré en 30, 320)
        if (p.item?.icon && this.scene.textures.exists(itemIconKey(p.item.icon))) {
            this.image(30, 320, itemIconKey(p.item.icon)).setOrigin(0.5, 0.5);
        }
        else this.text(16, 358, 'Aucun', GREY);
    }

    /**
     * Étape 18 : la page d'un ŒUF (Essentials : drawPageOneEgg). Sprite de l'Œuf, Ball, "Œuf",
     * puis le mémo : date, d'où il vient, et le "Suivi de l'Œuf" (s'il est loin d'éclore ou pas).
     */
    drawEgg() {
        const p = this.pokemon;
        this.text(26, 22, 'MÉMO DRESSEUR', WHITE);
        const sprite = this.scene.textures.exists(`pkmn_${p.sprites.front}`) ? `pkmn_${p.sprites.front}` : 'egg_sprite';
        if (this.scene.textures.exists(sprite)) this.add(this.scene.add.image(104, 206, sprite));
        const ball = `summary_ball_${ballName(p.ball)}`;
        if (this.scene.textures.exists(ball)) this.image(14, 60, ball);
        this.text(46, 68, p.name, WHITE);
        this.text(66, 324, 'Objet', WHITE);
        this.text(16, 358, 'Aucun', GREY);

        let y = 86;
        if (p.met?.date) { this.text(232, y, `Le ${p.met.date}`, DARK); y += 32; }
        y += 32 * this.wrapped(232, y, 268, 2, 'Un mystérieux Œuf de Pokémon reçu de :', DARK);
        this.text(232, y, p.met?.location ?? 'Lieu inconnu', RED); y += 32;
        this.text(232, y, '« Suivi de l\'Œuf »', DARK); y += 32;
        this.wrapped(232, y, 268, 3, p.eggState ?? '', DARK);
    }

    drawTypes(types, x1, x2, y) {
        types.forEach((type, i) => {
            const x = types.length === 1 ? x1 : x2 + (x1 === 402 ? 66 : 70) * i;
            this.image(x, y, 'battle_types', `t${TYPE_ROWS[type] ?? 0}`);
        });
    }

    /** Page 1 : INFOS */
    drawInfo() {
        const p = this.pokemon;
        this.text(238, 86, 'N° Pokédex', WHITE);
        this.text(435, 86, String(p.dex).padStart(3, '0'), p.shiny ? RED : DARK, { align: 'center' });
        this.text(238, 118, 'Espèce', WHITE);
        this.text(435, 118, p.speciesName, DARK, { align: 'center' });
        this.text(238, 150, 'Type', WHITE);
        this.drawTypes(p.types, 402, 370, 146);
        this.text(238, 182, 'D.O.', WHITE);
        this.text(435, 182, p.ot.name, { color: '#4070e8', shadow: '#88a8d0' }, { align: 'center' });
        this.text(238, 214, 'N° ID', WHITE);
        this.text(435, 214, String(p.ot.id).padStart(5, '0'), DARK, { align: 'center' });
        this.text(238, 246, 'Points Exp.', WHITE);
        this.text(488, 278, p.exp.toLocaleString('fr-FR'), DARK, { align: 'right' });
        this.text(238, 310, 'N. suivant', WHITE);
        this.text(488, 342, Math.max(0, p.nextLevelExp - p.exp).toLocaleString('fr-FR'), DARK, { align: 'right' });

        if (p.level < 100) {
            const span = Math.max(1, p.nextLevelExp - p.levelExp);
            const w = Math.round((128 * Math.max(0, p.exp - p.levelExp) / span) / 2) * 2;
            if (w > 0) this.image(362, 372, 'summary_exp').setCrop(0, 0, w, 6);
        }
    }

    /** Page 2 : MÉMO DRESSEUR */
    drawMemo() {
        const p = this.pokemon;
        const lines = [
            [`Nature ${p.nature.name ?? '???'}.`, RED],
            [p.met.date ? `Le ${p.met.date}` : '', DARK],
            [p.met.location ?? 'Lieu lointain', RED],
            [p.met.level ? `Rencontré au N. ${p.met.level}.` : '', DARK],
        ];
        lines.forEach(([value, colors], i) => {
            if (value) this.text(232, 86 + i * 32, value, colors);
        });
    }

    /** Page 3 : STATS (la nature colore la stat augmentée en rouge, la stat baissée en bleu) */
    drawSkills() {
        const p = this.pokemon;
        const shadowOf = (stat) => (p.nature.up === stat ? '#886048' : p.nature.down === stat ? '#407898' : '#686868');

        this.text(292, 82, 'PV', { color: '#f8f8f8', shadow: shadowOf('hp') }, { align: 'center' });
        this.text(462, 82, `${p.hp}/${p.maxHp}`, DARK, { align: 'right' });
        for (const [stat, label, y] of STAT_ROWS) {
            this.text(248, y, label, { color: '#f8f8f8', shadow: shadowOf(stat) });
            this.text(456, y, String(p.stats[stat]), DARK, { align: 'right' });
        }
        this.text(224, 290, 'Talent', WHITE);
        this.text(362, 290, p.ability.name ?? '', DARK);
        this.wrapped(224, 322, 282, 2, p.ability.description ?? '', DARK);

        if (p.hp > 0) {
            let w = Math.max(1, (96 * p.hp) / p.maxHp);
            w = Math.round(w / 2) * 2;
            const zone = p.hp <= p.maxHp / 4 ? 2 : p.hp <= p.maxHp / 2 ? 1 : 0;
            this.image(360, 110, 'summary_hp', zone).setCrop(0, 0, w, 6);
        }
    }

    /** Page 4 : CAPACITÉS */
    drawMoves() {
        this.drawMoveList(this.pokemon.moves, 104, false);
        // Clic sur une capacité : son détail
        this.pokemon.moves.forEach((m, i) => {
            const zone = this.scene.add.zone(240, 92 + i * 64, 252, 74).setOrigin(0, 0);
            clickable(zone, { onClick: () => { this.moveIndex = i; this.mode = 'detail'; this.draw(); } });
            this.add(zone);
        });
    }

    drawMoveList(moves, startY, withNew) {
        let y = startY;
        for (let i = 0; i < (withNew ? 5 : 4); i++) {
            const move = i === 4 ? this.learnMove : moves[i];
            if (i === 4) y += 20;
            if (move) {
                this.image(248, y - 4, 'battle_types', `t${TYPE_ROWS[move.type] ?? 0}`);
                this.text(316, y, move.name, DARK);
                this.text(342, y + 32, 'PP', DARK);
                const fraction = move.pp === 0 ? 3 : move.pp * 4 <= move.maxPp ? 2 : move.pp * 2 <= move.maxPp ? 1 : 0;
                this.text(460, y + 32, `${move.pp}/${move.maxPp}`, PP_COLORS[fraction], { align: 'right' });
            } else {
                this.text(316, y, '-', DARK);
                this.text(442, y + 32, '--', DARK, { align: 'right' });
            }
            y += 64;
        }
    }

    /** Détail d'une capacité (ou choix de la capacité à oublier) */
    drawMoveSelection() {
        const p = this.pokemon;
        const learning = this.mode === 'learn';
        const moves = p.moves;
        const startY = learning ? 104 - 76 : 104;
        const count = learning ? 5 : moves.length;
        this.moveIndex = Math.min(this.moveIndex, count - 1);

        this.text(26, 22, 'CAPACITÉS', WHITE);
        this.text(20, 128, 'CATÉGORIE', WHITE);
        this.text(20, 160, 'PUISSANCE', WHITE);
        this.text(20, 192, 'PRÉCISION', WHITE);

        // Icône du Pokémon et ses types
        const iconKey = `pkmn_icon_${p.sprites.icon}`;
        if (this.scene.textures.exists(iconKey)) {
            this.add(this.scene.add.image(46, 92, iconKey).setCrop(0, 0, 64, 64).setOrigin(0.25, 0.5));
        }
        p.types.forEach((type, i) => {
            this.image(p.types.length === 1 ? 130 : 96 + 70 * i, 78, 'battle_types', `t${TYPE_ROWS[type] ?? 0}`);
        });

        // Curseur rouge sur la capacité choisie (la 5e, à apprendre, est 20 pixels plus bas)
        const cursorY = (learning ? 92 - 76 : 92) + this.moveIndex * 64 + (learning && this.moveIndex === 4 ? 20 : 0);
        this.image(240, cursorY, 'summary_cursor_move', 0);

        this.drawMoveList(moves, startY, learning);

        // Détail de la capacité sélectionnée
        const move = this.moveIndex === 4 ? this.learnMove : moves[this.moveIndex];
        if (move) {
            this.image(166, 124, 'ui_category', CATEGORY_ROWS[move.category] ?? 2);
            const power = move.category === 'status' ? '---' : (move.power ?? '???');
            this.text(216, 154, String(power), DARK, { align: 'right' });
            this.text(216, 186, move.accuracy ? `${move.accuracy}%` : '---', DARK, { align: 'right' });
            this.wrapped(4, 224, 230, 5, move.description ?? '', DARK);
        }

        // Souris : clic sur une capacité = la choisir
        for (let i = 0; i < count; i++) {
            const y = (learning ? 92 - 76 : 92) + i * 64 + (learning && i === 4 ? 20 : 0);
            const zone = this.scene.add.zone(240, y, 252, 74).setOrigin(0, 0);
            clickable(zone, {
                onHover: () => { if (this.moveIndex !== i) { this.moveIndex = i; this.draw(); } },
                onClick: () => { this.moveIndex = i; this.handleKey('ok'); },
            });
            this.add(zone);
        }
    }

    /** Onglets des pages en haut à droite : cliquables */
    drawTabs() {
        for (let page = 1; page <= 4; page++) {
            const zone = this.scene.add.zone(226 + (page - 1) * 56, 0, 54, 50).setOrigin(0, 0);
            clickable(zone, { onClick: () => { this.page = page; this.draw(); } });
            this.add(zone);
        }
    }

    /** Texte sur plusieurs lignes (32 px), coupé à la largeur donnée */
    wrapped(x, y, width, maxLines, value, colors) {
        const words = value.split(' ');
        const lines = [];
        let line = '';
        for (const word of words) {
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
        return Math.min(lines.length, maxLines);
    }

    // ---------------------------------------------------------------------

    handleKey(action) {
        if (this.mode === 'learn' || this.mode === 'detail') {
            const count = this.mode === 'learn' ? 5 : this.pokemon.moves.length;
            if (action === 'up' || action === 'down') {
                this.moveIndex = (this.moveIndex + (action === 'up' ? count - 1 : 1)) % count;
                this.draw();
            } else if (action === 'ok' && this.mode === 'learn') {
                this.close(this.moveIndex);
            } else if (action === 'cancel') {
                if (this.mode === 'learn') this.close(null);
                else { this.mode = 'view'; this.draw(); }
            }
            return true;
        }

        // Étape 18 : un Œuf n'a qu'une page
        if ((action === 'left' || action === 'right') && this.pokemon.egg) return true;
        if (action === 'left' && this.page > 1) { this.page--; this.draw(); }
        else if (action === 'right' && this.page < 4) { this.page++; this.draw(); }
        else if ((action === 'up' || action === 'down') && this.party.length > 1) {
            this.index = (this.index + (action === 'up' ? this.party.length - 1 : 1)) % this.party.length;
            this.draw();
        } else if (action === 'ok' && this.page === 4 && this.pokemon.moves.length > 0) {
            this.mode = 'detail';
            this.moveIndex = 0;
            this.draw();
        } else if (action === 'cancel') {
            this.close(null);
        }
        return true;
    }

    /** La molette fait défiler les Pokémon */
    get wheel() {
        return this.mode === 'view';
    }

    close(value) {
        this.destroy();
        this.resolve(value);
    }
}
