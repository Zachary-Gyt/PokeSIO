import PixelText from '../ui/PixelText.js';
import { TYPE_ROWS } from '../battle/BattleUI.js';
import { loadImages } from '../battle/BattleAssets.js';
import { clickable } from '../ui/Controls.js';
import SummaryScreen from './SummaryScreen.js';
import { api } from './Api.js';

/**
 * LE PC DE STOCKAGE d'Essentials (Graphics/UI/Storage), positions reprises de UI_PokemonStorage.rb.
 *
 *   boîte (6 x 5 cases) au centre, fiche du Pokémon survolé à gauche, la main pour sélectionner,
 *   "Équipe" (fait monter le panneau de l'équipe) et "Quitter" en bas.
 *
 * Sur un Pokémon : Déplacer (la main le prend, on le pose ailleurs ; sur une case occupée ils
 * s'échangent), Résumé, Retirer / Déposer, Relâcher.
 *
 * ui : { say(text), choose(text, labels) -> index|null, yesNo(text) -> bool } (fournis par la scène)
 */
const INFO = { color: '#585850', shadow: '#a8b8b8' };
const INFO_GREY = { color: '#d0d0d0', shadow: '#e0e0e0' };
const BUTTON = { color: '#f8f8f8', shadow: '#505050' };
const BOX_X = 184;
const BOX_Y = 18;
const PARTY_X = 182;
const PARTY_Y = 32;
// Position des icônes dans le panneau de l'équipe (Essentials : PokemonBoxPartySprite)
const PARTY_ICONS = [[18, 2], [90, 18], [18, 66], [90, 82], [18, 130], [90, 146]];

export default class StorageScreen extends Phaser.GameObjects.Container {
    constructor(scene, data, { urls, ui }) {
        super(scene, 0, 0);
        this.pc = data;          // { boxes: [{name, wallpaper, slots: [30]}], party: [...] }
        this.urls = urls;
        this.ui = ui;
        this.box = 0;
        this.area = 'box';         // box | name | buttons | party
        this.index = 0;
        this.held = null;          // Pokémon pris par la main (Déplacer)
        this.partyShown = false;
        this.busy = false;
        this.setDepth(1300);
        scene.add.existing(this);
        this.promise = new Promise((resolve) => (this.resolve = resolve));
    }

    async open() {
        await this.loadIcons();
        this.build();
        this.draw();
        return this.promise;
    }

    async loadIcons() {
        const images = {};
        const all = [...this.pc.party, ...this.pc.boxes.flatMap((b) => b.slots)].filter(Boolean);
        for (const p of all) {
            images[`pkmn_icon_${p.sprites.icon}`] = p.sprites.icon;
            images[`pkmn_${p.sprites.front}`] = p.sprites.front;
        }
        await loadImages(this.scene, images);
    }

    build() {
        const s = this.scene;
        this.add(s.add.image(0, 0, 'storage_bg').setOrigin(0, 0));
        this.boxLayer = s.add.container(BOX_X, BOX_Y);
        this.sides = s.add.image(0, 0, 'storage_overlay_main').setOrigin(0, 0);
        this.info = s.add.container(0, 0);
        this.buttons = s.add.container(0, 0);
        this.partyPanel = s.add.container(PARTY_X, 384);
        this.hand = s.add.container(0, 0);
        this.add([this.boxLayer, this.sides, this.info, this.buttons, this.partyPanel, this.hand]);

        this.handImage = s.add.image(0, 0, 'storage_cursor_point_1').setOrigin(0, 0);
        this.hand.add(this.handImage);
        this.handTimer = s.time.addEvent({ delay: 300, loop: true, callback: () => { this.handFrame = !this.handFrame; this.drawHand(); } });

        // Souris : cases de la boîte, nom de la boîte (flèches), boutons, cases de l'équipe
        for (let i = 0; i < 30; i++) {
            const zone = s.add.zone(BOX_X + 8 + 48 * (i % 6), BOX_Y + 30 + 48 * Math.floor(i / 6), 48, 48).setOrigin(0, 0);
            clickable(zone, {
                enabled: () => !this.busy && !this.partyShown,
                onHover: () => this.moveTo('box', i),
                onClick: () => { this.moveTo('box', i); this.handleKey('ok'); },
            });
            this.add(zone);
        }
        const prev = s.add.zone(BOX_X, BOX_Y, 60, 30).setOrigin(0, 0);
        const next = s.add.zone(BOX_X + 264, BOX_Y, 60, 30).setOrigin(0, 0);
        clickable(prev, { enabled: () => !this.busy, onClick: () => this.changeBox(-1) });
        clickable(next, { enabled: () => !this.busy, onClick: () => this.changeBox(1) });
        const partyButton = s.add.zone(188, 318, 164, 46).setOrigin(0, 0);
        const exitButton = s.add.zone(390, 318, 118, 46).setOrigin(0, 0);
        clickable(partyButton, { enabled: () => !this.busy, onHover: () => this.moveTo('buttons', 0), onClick: () => { this.moveTo('buttons', 0); this.handleKey('ok'); } });
        clickable(exitButton, { enabled: () => !this.busy, onHover: () => this.moveTo('buttons', 1), onClick: () => { this.moveTo('buttons', 1); this.handleKey('ok'); } });
        this.add([prev, next, partyButton, exitButton]);
        // Le panneau et la main passent devant les zones
        this.bringToTop(this.partyPanel);
        this.bringToTop(this.hand);
    }

    // ---------------------------------------------------------------------
    // Dessin
    // ---------------------------------------------------------------------

    draw() {
        this.drawBox();
        this.drawButtons();
        this.drawParty();
        this.drawInfo();
        this.drawHand();
    }

    drawBox() {
        const s = this.scene;
        const box = this.pc.boxes[this.box];
        this.boxLayer.removeAll(true);
        this.boxLayer.add(s.add.image(0, 0, `storage_box_${box.wallpaper % 8}`).setOrigin(0, 0));
        this.boxLayer.add(new PixelText(s, 162, 14 - 2, box.name, { color: '#f8f8f8', shadow: '#283030', align: 'center' }));
        box.slots.forEach((p, i) => {
            if (!p || p === this.held) return;
            this.boxLayer.add(this.icon(p, 8 + 48 * (i % 6), 30 + 48 * Math.floor(i / 6)));
        });
    }

    icon(p, x, y) {
        const key = `pkmn_icon_${p.sprites.icon}`;
        if (!this.scene.textures.exists(key)) return this.scene.add.rectangle(x, y, 1, 1, 0, 0);
        return this.scene.add.image(x, y, key).setOrigin(0, 0).setCrop(0, 0, 64, 64);
    }

    drawButtons() {
        const s = this.scene;
        this.buttons.removeAll(true);
        this.buttons.add(new PixelText(s, 270, 334 - 2 - 4, `Équipe : ${this.pc.party.length}`, { ...BUTTON, align: 'center' }));
        this.buttons.add(new PixelText(s, 446, 334 - 2 - 4, 'Quitter', { ...BUTTON, align: 'center' }));
    }

    drawParty() {
        const s = this.scene;
        this.partyPanel.removeAll(true);
        this.partyPanel.add(s.add.image(0, 0, 'storage_overlay_party').setOrigin(0, 0));
        this.pc.party.forEach((p, i) => {
            if (p === this.held) return;
            this.partyPanel.add(this.icon(p, PARTY_ICONS[i][0], PARTY_ICONS[i][1]));
        });
        this.partyPanel.add(new PixelText(s, 86, 248 - 2 - 4, 'Retour', { ...BUTTON, align: 'center' }));

        // Souris : cases du panneau de l'équipe
        for (let i = 0; i < 7; i++) {
            const [x, y] = i < 6 ? PARTY_ICONS[i] : [36, 238];
            const zone = s.add.zone(x, y, i < 6 ? 64 : 100, i < 6 ? 64 : 34).setOrigin(0, 0);
            clickable(zone, {
                enabled: () => !this.busy && this.partyShown,
                onHover: () => this.moveTo('party', i),
                onClick: () => { this.moveTo('party', i); this.handleKey('ok'); },
            });
            this.partyPanel.add(zone);
        }
    }

    /** La fiche à gauche : le Pokémon tenu, sinon celui sous la main */
    drawInfo() {
        const s = this.scene;
        this.info.removeAll(true);
        const p = this.held ?? this.hovered();
        if (!p) return;

        this.info.add(new PixelText(s, 10, 14 - 2, p.name, INFO));
        // Étape 18 : un Œuf : seulement son image (ni niveau, ni types, ni talent)
        if (p.egg) {
            const egg = s.textures.exists(`pkmn_${p.sprites.front}`) ? `pkmn_${p.sprites.front}` : 'egg_sprite';
            if (s.textures.exists(egg)) this.info.add(s.add.image(90, 134, egg));
            return;
        }
        if (p.gender === 'male') this.info.add(new PixelText(s, 148, 12, '♂', { color: '#1870d8', shadow: '#88a8d0' }));
        if (p.gender === 'female') this.info.add(new PixelText(s, 148, 12, '♀', { color: '#f83820', shadow: '#e09890' }));
        const front = `pkmn_${p.sprites.front}`;
        if (s.textures.exists(front)) this.info.add(s.add.image(90, 134, front));
        if (p.shiny) this.info.add(s.add.image(156, 198, 'battle_shiny').setOrigin(0, 0));
        this.info.add(s.add.image(6, 246, 'storage_lv').setOrigin(0, 0));
        this.info.add(new PixelText(s, 28, 240 - 2, String(p.level), INFO));
        p.types.forEach((type, i) => {
            const x = p.types.length === 1 ? 52 : 18 + 70 * i;
            this.info.add(s.add.image(x, 272, 'battle_types', `t${TYPE_ROWS[type] ?? 0}`).setOrigin(0, 0));
        });
        this.info.add(new PixelText(s, 86, 312 - 2, p.ability?.name ?? '', { ...INFO, align: 'center' }));
        this.info.add(new PixelText(s, 86, 348 - 2, p.item ? p.item.name : 'Aucun objet', { ...(p.item ? INFO : INFO_GREY), align: 'center' }));
    }

    /** Position de la main (Essentials : pbSetArrow / pbPartySetArrow) */
    handPosition() {
        if (this.area === 'box') return [194 + 48 * (this.index % 6), 16 + 48 * Math.floor(this.index / 6)];
        if (this.area === 'name') return [314, -24];
        if (this.area === 'buttons') return this.index === 0 ? [238, 278] : [414, 278];
        if (this.index === 6) return [PARTY_X + 60, PARTY_Y + 206];
        return [PARTY_X + PARTY_ICONS[this.index][0] + 2, PARTY_Y + PARTY_ICONS[this.index][1] - 32];
    }

    drawHand() {
        if (!this.hand) return;
        const [x, y] = this.handPosition();
        this.hand.setPosition(x, y);
        this.handImage.setTexture(this.held ? 'storage_cursor_fist' : (this.handFrame ? 'storage_cursor_point_2' : 'storage_cursor_point_1'));
        // Le Pokémon tenu est dessiné sous la main
        if (this.heldIcon) this.heldIcon.destroy();
        this.heldIcon = null;
        if (this.held) {
            this.heldIcon = this.icon(this.held, -2, 16);
            this.hand.addAt(this.heldIcon, 0);
        }
    }

    hovered() {
        if (this.area === 'box') return this.pc.boxes[this.box].slots[this.index];
        if (this.area === 'party' && this.index < 6) return this.pc.party[this.index];
        return null;
    }

    moveTo(area, index) {
        if (this.area === area && this.index === index) return;
        this.area = area;
        this.index = index;
        this.drawInfo();
        this.drawHand();
    }

    changeBox(delta) {
        this.box = (this.box + delta + this.pc.boxes.length) % this.pc.boxes.length;
        this.drawBox();
        this.drawInfo();
    }

    async showParty(show) {
        this.partyShown = show;
        await new Promise((resolve) => this.scene.tweens.add({ targets: this.partyPanel, y: show ? PARTY_Y : 384, duration: 200, onComplete: resolve }));
        if (show) this.moveTo('party', 0);
        else this.moveTo('buttons', 0);
    }

    // ---------------------------------------------------------------------
    // Clavier
    // ---------------------------------------------------------------------

    handleKey(action) {
        if (this.busy) return true;

        if (action === 'cancel') {
            if (this.held) { this.held = null; this.draw(); }
            else if (this.partyShown) this.showParty(false);
            else this.close();
            return true;
        }
        if (action === 'ok') {
            this.select();
            return true;
        }

        if (this.area === 'party') {
            const moves = { left: -1, right: 1, up: -2, down: 2 };
            let i = this.index;
            if (i === 6) i = action === 'up' ? 4 : action === 'down' ? 0 : 6;
            else {
                i += moves[action];
                if (i > 5) i = 6;
                if (i < 0) i = 6;
            }
            this.moveTo('party', i);
            return true;
        }

        if (this.area === 'box') {
            const col = this.index % 6;
            const row = Math.floor(this.index / 6);
            if (action === 'left') this.moveTo('box', row * 6 + (col + 5) % 6);
            if (action === 'right') this.moveTo('box', row * 6 + (col + 1) % 6);
            if (action === 'up') row === 0 ? this.moveTo('name', 0) : this.moveTo('box', this.index - 6);
            if (action === 'down') row === 4 ? this.moveTo('buttons', col < 3 ? 0 : 1) : this.moveTo('box', this.index + 6);
        } else if (this.area === 'name') {
            if (action === 'left') this.changeBox(-1);
            if (action === 'right') this.changeBox(1);
            if (action === 'down') this.moveTo('box', 2);
            if (action === 'up') this.moveTo('buttons', 0);
        } else if (this.area === 'buttons') {
            if (action === 'left' || action === 'right') this.moveTo('buttons', 1 - this.index);
            if (action === 'up') this.moveTo('box', 24 + (this.index === 0 ? 1 : 4));
            if (action === 'down') this.moveTo('name', 0);
        }
        return true;
    }

    /** Action sur la case sous la main */
    async select() {
        if (this.area === 'buttons') {
            if (this.index === 0) return this.showParty(true);
            if (this.held) return this.message('Vous tenez un Pokémon !');
            return this.close();
        }
        if (this.area === 'party' && this.index === 6) return this.showParty(false);
        if (this.area === 'name') return;

        const target = this.area === 'box'
            ? { location: 'box', box: this.box + 1, slot: this.index + 1 }
            : { location: 'party', slot: this.index + 1 };

        // La main tient un Pokémon : on le pose ici (échange si la case est occupée)
        if (this.held) {
            const held = this.held;
            return this.request(this.urls.pcMove, { pokemon_id: held.id, ...target }, () => { this.held = null; });
        }

        const p = this.hovered();
        if (!p) return;

        const inParty = this.area === 'party';
        const commands = ['Déplacer', 'Résumé', inParty ? 'Déposer' : 'Retirer', 'Relâcher', 'Annuler'];
        this.busy = true;
        const choice = await this.ui.choose(`Que faire avec ${p.name} ?`, commands);
        this.busy = false;

        if (choice === 0) {
            this.held = p;
            this.draw();
        } else if (choice === 1) {
            const list = inParty ? this.pc.party : this.pc.boxes[this.box].slots.filter(Boolean);
            this.busy = true;
            this.scene.focusStack?.push(this.scene.focus);
            const summary = new SummaryScreen(this.scene, list, list.indexOf(p));
            this.scene.focus = summary;
            await summary.open();
            this.scene.focus = this;
            this.busy = false;
        } else if (choice === 2) {
            const body = inParty ? { pokemon_id: p.id, location: 'box', box: this.box + 1 } : { pokemon_id: p.id, location: 'party' };
            await this.request(this.urls.pcMove, body);
        } else if (choice === 3) {
            this.busy = true;
            const sure = await this.ui.yesNo(`Voulez-vous vraiment relâcher ${p.name} ?`);
            this.busy = false;
            if (sure) {
                await this.request(this.urls.pcRelease, { pokemon_id: p.id }, null, [`${p.name} a été relâché.`, `Bye-bye, ${p.name} !`]);
            }
        }
    }

    /** Appel au serveur ; en cas de refus, le message est affiché et rien ne change */
    async request(url, body, onSuccess = null, messages = []) {
        this.busy = true;
        const result = await api.post(url, body);
        this.busy = false;
        if (!result.ok) {
            this.held = null;
            this.draw();
            return this.message(result.data.message ?? 'Impossible.');
        }
        onSuccess?.();
        this.pc = result.data;
        await this.loadIcons();
        this.draw();
        for (const m of messages) await this.message(m);
    }

    async message(text) {
        this.busy = true;
        await this.ui.say(text);
        this.busy = false;
    }

    close() {
        this.handTimer.remove();
        this.destroy();
        this.resolve();
    }
}
