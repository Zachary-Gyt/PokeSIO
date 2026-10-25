import PixelText from '../ui/PixelText.js';
import { loadImages } from '../battle/BattleAssets.js';
import { clickable } from '../ui/Controls.js';

/**
 * La CARTE DRESSEUR d'Essentials (Graphics/UI/Trainer Card), positions reprises de UI_TrainerCard.rb :
 * nom, n° ID, argent, Pokédex, temps de jeu, date de début, sprite du dresseur et les 8 badges.
 * Plus de 8 badges (Kanto puis Johto) : une page de 8 badges par région, ← → pour changer de page.
 *
 *   await new TrainerCardScreen(scene, card, basePath).open();
 */
const TEXT = { color: '#484848', shadow: '#a0a0a0' };   // baseColor (72,72,72) / shadowColor (160,160,160)
const T = (y) => y - 2;                                  // y d'Essentials -> y de PixelText

export default class TrainerCardScreen extends Phaser.GameObjects.Container {
    constructor(scene, card, basePath) {
        super(scene, 0, 0);
        this.card = card;
        this.basePath = basePath;
        this.setDepth(1500);
        scene.add.existing(this);
    }

    async open() {
        const s = this.scene;
        const c = this.card;
        const female = c.gender === 'female';
        const avatar = c.avatar ?? (female ? 'leaf' : 'red');   // étape 17
        const spriteKey = `trainer_battle_player_${avatar}`;
        await loadImages(s, { [spriteKey]: `${this.basePath}/sprites/trainers/battle/player_${avatar}.png` });

        // Fond (motif de 8 x 8 répété) puis la carte
        this.add(s.add.tileSprite(0, 0, 512, 384, female ? 'card_bg_f' : 'card_bg').setOrigin(0, 0));
        this.add(s.add.image(0, 0, female ? 'card_f' : 'card').setOrigin(0, 0));

        // Sprite du dresseur : bas aligné sur une case de 128 x 128 dont le coin est (336, 112)
        if (s.textures.exists(spriteKey)) {
            const img = s.textures.get(spriteKey).getSourceImage();
            this.add(s.add.image(336 - (img.width - 128) / 2, 112 - (img.height - 128), spriteKey).setOrigin(0, 0));
        }

        const hours = Math.floor(c.playTime / 3600);
        const minutes = Math.floor(c.playTime / 60) % 60;
        const time = hours > 0 ? `${hours} h ${String(minutes).padStart(2, '0')}` : `${minutes} min`;

        const rows = [
            ['Nom', c.name, 70],
            ['Argent', `${formatMoney(c.money)} ₽`, 118],
            ['Pokédex', `${c.caught}/${c.seen}`, 166],
            ['Temps', time, 214],
            ['Début', c.started ?? '', 262],
        ];
        for (const [label, value, y] of rows) {
            this.add(new PixelText(s, 34, T(y), label, TEXT));
            this.add(new PixelText(s, 302, T(y), value, { ...TEXT, align: 'right' }));
        }
        this.add(new PixelText(s, 332, T(70), 'N° ID', TEXT));
        this.add(new PixelText(s, 468, T(70), c.trainerId, { ...TEXT, align: 'right' }));

        // Badges : une page de 8 par région (ordres 1-8 = Kanto, 9-16 = Johto...), case = (ordre - 1) % 8
        // icon_badges.png = 8 icônes de 32 x 32 par ligne (une ligne par région)
        this.pages = Math.max(1, Math.ceil(Math.max(0, ...c.badges.map((b) => b.order)) / 8));
        this.page = 0;
        this.badgeLayer = s.add.container(0, 0);
        this.add(this.badgeLayer);
        if (this.pages > 1) {
            this.regionLabel = new PixelText(s, 468, T(30), '', { color: '#f8f8f8', shadow: '#385888', align: 'right' });   // à droite du titre
            this.add(this.regionLabel);
            const left = s.add.sprite(10, 304, 'ui_left_arrow', 0).setOrigin(0, 0);
            const right = s.add.sprite(462, 304, 'ui_right_arrow', 0).setOrigin(0, 0);
            this.add([left, right]);
            clickable(left, { onClick: () => this.handleKey('left') });
            clickable(right, { onClick: () => this.handleKey('right') });
        }
        this.drawBadges();

        this.scene.cameras.main.fadeIn(200, 0, 0, 0);
        return new Promise((resolve) => (this.resolve = resolve));
    }

    drawBadges() {
        const s = this.scene;
        this.badgeLayer.removeAll(true);
        const regions = ['Kanto', 'Johto', 'Hoenn', 'Sinnoh'];
        this.regionLabel?.setText(`${regions[this.page] ?? `Page ${this.page + 1}`}  (${this.page + 1}/${this.pages})`);
        for (const badge of this.card.badges) {
            const slot = badge.order - 1 - this.page * 8;
            if (!badge.owned || slot < 0 || slot > 7) continue;
            const icon = s.add.image(0, 0, 'card_badges').setOrigin(0, 0);
            const col = badge.icon % 8;
            const row = Math.floor(badge.icon / 8);
            icon.setCrop(col * 32, row * 32, 32, 32);
            icon.setPosition(72 + slot * 48 - col * 32, 310 - row * 32);
            this.badgeLayer.add(icon);
        }
    }

    get clickAnywhere() {
        return true;
    }

    handleKey(action) {
        if ((action === 'left' || action === 'right') && this.pages > 1) {
            this.page = (this.page + (action === 'left' ? this.pages - 1 : 1)) % this.pages;
            this.drawBadges();
            return true;
        }
        if (action === 'ok' || action === 'cancel') {
            this.destroy();
            this.resolve?.();
        }
        return true;
    }
}

/** 12345 -> "12 345" */
export function formatMoney(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}
