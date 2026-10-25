import { ChoiceWindow } from '../battle/BattleUI.js';
import { loadImages } from '../battle/BattleAssets.js';
import { api } from './Api.js';

// Étape 16 : décalage vertical des textes du Pokédex (ils étaient trop hauts dans leurs cadres)
const TEXT_DY = 6;
import PixelText from '../ui/PixelText.js';

/**
 * LE POKÉDEX (étape 13), avec les écrans d'Essentials (Graphics/UI/Pokedex) :
 *
 *   couverture  bg_menu      "Vus" / "Capturés", puis Pokédex / Recherche / Quitter
 *   liste       bg_list      les 151 ; bleu (bg_listsearch) quand une recherche est active
 *   fiche       bg_info      image, cri, types, catégorie, taille, poids, texte du Pokédex
 *   zone        bg_area      les lieux où le trouver (tables de rencontres)
 *
 * Touches : haut / bas = Pokémon précédent / suivant ; gauche / droite = 8 d'un coup dans la liste,
 * ou Infos <-> Zone dans la fiche ; Action = ouvrir ; Retour = revenir.
 *
 *   await new PokedexScreen(menuScene).open();
 */
const TEXT = { color: '#505058', shadow: '#c8c8c0' };       // texte sombre des cadres blancs
const WHITE = { color: '#f8f8f8', shadow: '#383838' };      // texte des bandeaux de couleur
const ROWS = 8;                                              // lignes visibles dans la liste
const ROW_H = 40;
const LIST_TOP = 56;

export default class PokedexScreen extends Phaser.GameObjects.Container {
    constructor(scene) {
        super(scene, 0, 0);
        this.urls = scene.registry.get('config').pokedex;
        this.audio = scene.registry.get('audio');
        this.setDepth(1500);
        scene.add.existing(this);
        this.layer = null;    // ce qui est dessiné pour l'écran en cours (détruit à chaque changement)
        this.filter = null;   // { label, test(row) } : recherche active
        this.index = 0;
        this.top = 0;
    }

    async open() {
        const r = await api.get(this.urls.index);
        if (!r.ok) return;
        this.dex = r.data;
        this.typeNames = Object.fromEntries((r.data.types ?? []).map((t) => [t.slug, t]));
        this.rows = this.dex.list;

        return new Promise((resolve) => {
            this.resolve = resolve;
            this.showCover();
        });
    }

    close() {
        this.scene.focus = this.previousFocus ?? null;
        this.destroy();
        this.resolve?.();
    }

    /** Remplace l'écran affiché */
    newLayer(background) {
        this.layer?.destroy(true);
        this.layer = this.scene.add.container(0, 0);
        this.add(this.layer);
        // '__BASE' : l'image entière (bg_info et bg_area ont aussi un petit cadre "band", voir drawDetail)
        if (background) this.layer.add(this.scene.add.image(0, 0, background, '__BASE').setOrigin(0, 0));
        return this.layer;
    }

    /**
     * Un texte. Étape 16 : les positions y sont celles d'Essentials (haut de la ligne de 32 px) ;
     * TEXT_DY descend le texte pour qu'il soit bien centré dans les cadres.
     */
    text(x, y, value, style = TEXT, options = {}) {
        const t = new PixelText(this.scene, x, y + TEXT_DY, value, { ...style, ...options });
        this.layer.add(t);
        return t;
    }

    // =====================================================================
    // Couverture : Vus / Capturés, puis le choix
    // =====================================================================

    async showCover() {
        const s = this.scene;
        this.newLayer('dex_bg_menu');
        // Les pointillés du fond d'Essentials : "Vus" (gauche) et "Capturés" (droite)
        const light = { color: '#f8f8f8', shadow: '#902020' };
        this.text(326, 120 - TEXT_DY, 'Vus', light, { align: 'center', font: 'small' });
        this.text(421, 120 - TEXT_DY, 'Capturés', light, { align: 'center', font: 'small' });
        this.text(326, 150 - TEXT_DY, String(this.dex.seen), light, { align: 'center' });
        this.text(421, 150 - TEXT_DY, String(this.dex.caught), light, { align: 'center' });

        this.previousFocus ??= s.focus;
        const win = new ChoiceWindow(s, [{ label: 'Pokédex' }, { label: 'Recherche' }, { label: 'Quitter' }], {
            x: 128, y: 212, width: 256, cancelValue: 2,
        }).setDepth(1501);
        s.focus = win;
        const choice = await win.promise;
        win.destroy();
        if (choice === null || choice === 2) return this.close();
        if (choice === 1) return this.search();
        this.filter = null;
        this.showList();
    }

    // =====================================================================
    // Recherche
    // =====================================================================

    async search() {
        const s = this.scene;
        const options = ['Par nom', 'Par type', 'Capturés', 'Vus, pas capturés', 'Tout afficher', 'Retour'];
        const win = new ChoiceWindow(s, options.map((label) => ({ label })), { x: 128, y: 120, width: 256, cancelValue: 5 }).setDepth(1502);
        s.focus = win;
        const choice = await win.promise;
        win.destroy();

        if (choice === 0) {
            const name = await this.askText('Nom (ou début du nom) :');
            if (name) {
                const n = normalize(name);
                this.filter = { label: `« ${name} »`, test: (r) => r.name && normalize(r.name).includes(n) };
            }
        } else if (choice === 1) {
            const types = this.dex.types ?? [];
            const tw = new ChoiceWindow(s, types.map((t) => ({ label: t.name })), {
                x: 128, y: 40, width: 256, cancelValue: null, maxRows: 8,
            }).setDepth(1502);
            s.focus = tw;
            const t = await tw.promise;
            tw.destroy();
            if (t !== null && types[t]) this.filter = { label: types[t].name, test: (r) => r.types?.includes(types[t].slug) };
        } else if (choice === 2) {
            this.filter = { label: 'Capturés', test: (r) => r.state === 'caught' };
        } else if (choice === 3) {
            this.filter = { label: 'Vus', test: (r) => r.state === 'seen' };
        } else if (choice === 4) {
            this.filter = null;
        } else {
            return this.showCover();
        }

        this.index = 0;
        this.top = 0;
        this.showList();
    }

    /** Une petite zone de saisie (HTML, comme le chat) ; renvoie le texte, ou null (Échap) */
    askText(label) {
        return new Promise((resolve) => {
            const box = document.createElement('div');
            box.className = 'dialog';
            box.style.cssText = 'position:absolute;left:calc(64*var(--gp));top:calc(150*var(--gp));width:calc(384*var(--gp));'
                + 'padding:calc(14*var(--gp)) calc(20*var(--gp));z-index:20;font-family:"Power Green",monospace;font-size:calc(26*var(--gp));color:#505058;';
            const title = document.createElement('div');
            title.textContent = label;
            const input = document.createElement('input');
            input.maxLength = 20;
            input.style.cssText = 'width:100%;margin-top:calc(6*var(--gp));font:inherit;color:#505058;background:#fff;border:calc(2*var(--gp)) solid #a0a0a8;padding:0 calc(6*var(--gp));';
            box.append(title, input);
            document.getElementById('game-ui').appendChild(box);
            const game = this.scene.game;
            game.input.keyboard.enabled = false;
            const finish = (value) => {
                box.remove();
                game.input.keyboard.enabled = true;
                resolve(value);
            };
            input.addEventListener('keydown', (e) => {
                e.stopPropagation();   // rien ne va au jeu pendant la saisie
                if (e.key === 'Enter') finish(input.value.trim() || null);
                if (e.key === 'Escape') finish(null);
            });
            setTimeout(() => input.focus(), 0);
        });
    }

    // =====================================================================
    // Liste
    // =====================================================================

    get visibleRows() {
        return this.filter ? this.rows.filter((r) => this.filter.test(r)) : this.rows;
    }

    showList() {
        const s = this.scene;
        this.shown = this.visibleRows;
        if (!this.shown.length) {
            this.filter = null;
            this.shown = this.rows;
            this.flash = 'Aucun Pokémon ne correspond.';
        }
        this.index = Math.min(this.index, this.shown.length - 1);
        this.drawList();
        s.focus = {
            wheel: true,
            handleKey: (a) => {
                const n = this.shown.length;
                if (a === 'up') this.index = (this.index - 1 + n) % n;
                if (a === 'down') this.index = (this.index + 1) % n;
                if (a === 'left') this.index = Math.max(0, this.index - ROWS);
                if (a === 'right') this.index = Math.min(n - 1, this.index + ROWS);
                if (a === 'cancel') return this.showCover(), true;
                if (a === 'ok') {
                    const row = this.shown[this.index];
                    if (row.state === 'none') {
                        this.audio?.se('buzzer');
                        return false;   // pas de son "valider" : rien à ouvrir
                    }
                    this.showInfo(row);
                    return true;
                }
                this.drawList();
                return true;
            },
        };
    }

    drawList() {
        const s = this.scene;
        this.newLayer(this.filter ? 'dex_bg_listsearch' : 'dex_bg_list');
        const list = this.shown;
        if (this.index < this.top) this.top = this.index;
        if (this.index >= this.top + ROWS) this.top = this.index - ROWS + 1;

        this.text(16, -4, this.filter ? `Recherche : ${this.filter.label}` : 'Pokédex', WHITE);

        // La ligne choisie : le curseur d'Essentials
        this.layer.add(s.add.image(222, LIST_TOP + (this.index - this.top) * ROW_H - 4, 'dex_cursor_list').setOrigin(0, 0));
        for (let r = 0; r < ROWS; r++) {
            const row = list[this.top + r];
            if (!row) break;
            const y = LIST_TOP + r * ROW_H;
            if (row.state !== 'none') this.layer.add(s.add.image(238, y + 4, row.state === 'caught' ? 'dex_icon_own' : 'dex_icon_seen').setOrigin(0, 0));
            this.text(272, y - 4, `${String(row.number).padStart(3, '0')}  ${row.state === 'none' ? '-----' : row.name}`, TEXT, { font: 'narrow' });
        }

        // Barre de défilement : la petite boîte bleue d'icon_slider descend le long de la piste
        const knobRange = 316 - 80;
        const ratio = list.length > 1 ? this.index / (list.length - 1) : 0;
        const knob = s.add.image(468, 80 + Math.round(ratio * knobRange), 'dex_icon_slider').setOrigin(0, 0);
        knob.setCrop(40, 0, 40, 40);
        knob.x -= 40;
        this.layer.add(knob);

        // À gauche : le Pokémon choisi (s'il a été vu), son nom, puis Vus / Capturés
        const current = list[this.index];
        this.text(112, 46, current.state === 'none' ? '?????' : current.name, TEXT, { align: 'center' });
        if (current.state !== 'none' && current.sprite) this.drawSprite(current.sprite, 112, 196, 0.85);
        this.text(24, 300, 'Vus', TEXT);
        this.text(200, 300, String(this.dex.seen), TEXT, { align: 'right' });
        this.text(24, 332, 'Capturés', TEXT);
        this.text(200, 332, String(this.dex.caught), TEXT, { align: 'right' });

        if (this.flash) {
            this.text(256, 352, this.flash, WHITE, { align: 'center', font: 'small' });
            this.flash = null;
        }
    }

    /** L'image de face d'un Pokémon, chargée à la demande, centrée en (x, y) */
    async drawSprite(url, x, y, scale = 1) {
        const key = `pkmn_${url}`;
        const layer = this.layer;
        await loadImages(this.scene, { [key]: url });
        if (layer !== this.layer || !this.scene.textures.exists(key)) return;   // l'écran a changé entre-temps
        layer.add(this.scene.add.image(x, y, key).setScale(scale));
    }

    // =====================================================================
    // Fiche : Infos et Zone
    // =====================================================================

    async showInfo(row, page = 'info') {
        const r = await api.get(this.urls.show.replace('SPECIES', row.id));
        if (!r.ok) return;
        this.detail = r.data;
        this.page = page;
        this.audio?.cry(r.data.cry);
        this.drawDetail();
        this.scene.focus = {
            handleKey: (a) => {
                if (a === 'cancel') return this.showList(), true;
                if (a === 'left' || a === 'right') {
                    this.page = this.page === 'info' ? 'area' : 'info';
                    this.drawDetail();
                    return true;
                }
                if (a === 'up' || a === 'down') {
                    // Pokémon vu précédent / suivant dans la liste
                    const step = a === 'up' ? -1 : 1;
                    for (let i = this.index + step; i >= 0 && i < this.shown.length; i += step) {
                        if (this.shown[i].state !== 'none') {
                            this.index = i;
                            this.showInfo(this.shown[i], this.page);
                            return true;
                        }
                    }
                    return false;
                }
                if (a === 'ok') this.audio?.cry(this.detail.cry);
                return true;
            },
        };
    }

    drawDetail() {
        const s = this.scene;
        const d = this.detail;
        const info = this.page === 'info';
        this.newLayer(info ? 'dex_bg_info' : 'dex_bg_area');

        // Onglets en français, dessinés par-dessus "INFO AREA FORMS" du fond d'Essentials :
        // on recouvre le texte avec un morceau du bandeau sans texte (x = 400 à 464), répété
        for (const key of ['dex_bg_info', 'dex_bg_area']) {
            const texture = s.textures.get(key);
            if (!texture.has('band')) texture.add('band', 0, 400, 0, 64, 34);
        }
        const band = s.add.tileSprite(8, 0, 340, 34, info ? 'dex_bg_info' : 'dex_bg_area', 'band').setOrigin(0, 0);
        this.layer.add(band);
        this.text(40, -4, 'INFOS', info ? WHITE : { color: '#d8d8d8', shadow: '#383838' });
        this.text(150, -4, 'ZONE', info ? { color: '#d8d8d8', shadow: '#383838' } : WHITE);
        this.text(500, -2, '< >', WHITE, { align: 'right', font: 'small' });

        if (info) this.drawInfo(d);
        else this.drawArea(d);
    }

    drawInfo(d) {
        const s = this.scene;
        if (d.sprite) this.drawSprite(d.sprite, 104, 136, 1);

        this.text(222, 34, `${String(d.number).padStart(3, '0')}  ${d.name}`, WHITE);
        this.text(222, 66, d.category ? `Pokémon ${d.category}`.replace('Pokémon Pokémon', 'Pokémon') : 'Pokémon ?????', TEXT, { font: 'narrow' });

        // Types : pastilles de couleur avec le nom français
        d.types.forEach((t, i) => {
            const x = 290 + i * 104;
            const g = s.add.graphics();
            g.fillStyle(Phaser.Display.Color.HexStringToColor(t.color || '#a8a878').color, 1).fillRoundedRect(x, 116, 96, 30, 6);
            g.lineStyle(2, 0x383838, 0.6).strokeRoundedRect(x, 116, 96, 30, 6);
            this.layer.add(g);
            this.text(x + 48, 110, t.name, WHITE, { align: 'center', font: 'small' });
        });

        // Empreinte (Pokémon capturé)
        if (d.footprint) {
            const key = `foot_${d.footprint}`;
            const layer = this.layer;
            loadImages(s, { [key]: d.footprint }).then(() => {
                if (layer === this.layer && s.textures.exists(key)) layer.add(s.add.image(241, 153, key).setScale(1.5));
            });
        }

        const num = (v, unit) => (v === null || v === undefined ? `??? ${unit}` : `${String(v.toFixed(1)).replace('.', ',')} ${unit}`);
        this.text(302, 152, 'Taille', TEXT);
        this.text(486, 152, num(d.height, 'm'), TEXT, { align: 'right' });
        this.text(302, 186, 'Poids', TEXT);
        this.text(486, 186, num(d.weight, 'kg'), TEXT, { align: 'right' });

        // Texte du Pokédex (seulement si capturé), coupé en lignes
        const entry = d.caught ? (d.entry || 'Pas encore de description pour ce Pokémon.') : 'Capture ce Pokémon pour en savoir plus.';
        const fit = PixelText.fit(entry, 430, 4, 30);
        this.text(42, 240, fit.lines.join('\n'), TEXT, { font: fit.font, lineHeight: fit.lineHeight });
    }

    drawArea(d) {
        this.text(250, -4, d.name, WHITE);   // dans le bandeau, à droite des onglets
        if (!d.areas.length) {
            this.text(256, 180, 'Lieu inconnu.', TEXT, { align: 'center' });
            return;
        }
        // Deux lignes par lieu : nom et niveaux, puis comment le trouver
        d.areas.slice(0, 5).forEach((a, i) => {
            const y = 72 + i * 58;
            this.text(32, y, a.name, TEXT);
            this.text(482, y, a.levels, TEXT, { align: 'right' });
            const how = a.methods.join(', ') + (a.times.length ? ` (${a.times.join(', ')})` : '');
            this.text(48, y + 26, how, { color: '#808088', shadow: '#d8d8d0' }, { font: 'small' });
        });
        if (d.areas.length > 5) this.text(482, 350, `+ ${d.areas.length - 5} autres lieux`, TEXT, { align: 'right', font: 'small' });
    }
}

/** "Salamèche" -> "salameche" : recherche sans accents ni majuscules */
function normalize(text) {
    return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
