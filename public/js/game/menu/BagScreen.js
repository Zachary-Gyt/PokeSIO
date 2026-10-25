import PixelText from '../ui/PixelText.js';
import { loadImages } from '../battle/BattleAssets.js';
import { clickable } from '../ui/Controls.js';
import { itemIconKey, itemIconUrl } from './MenuAssets.js';

/**
 * LE SAC d'Essentials (Graphics/UI/Bag), positions reprises de UI_Bag.rb :
 * 8 poches, image du sac, flèches animées, liste de 8 lignes avec curseur, icône et description.
 *
 *   const item = await new BagScreen(scene, bag, { mode: 'battle', basePath }).open();
 *   // l'objet choisi, ou null si le joueur ferme le Sac
 *
 * mode 'field'  : tous les objets ; on choisit l'objet, l'appelant propose Utiliser / Jeter
 * mode 'battle' : seuls les objets utilisables en combat peuvent être choisis
 * mode 'give'   : seuls les objets qu'un Pokémon peut tenir (étape 14)
 */
const LIST = { color: '#585850', shadow: '#a8b8b8' };      // ITEMLISTBASECOLOR / SHADOW
const GREYED = { color: '#b8b8b0', shadow: '#d8d8d0' };
const DESCRIPTION = { color: '#f8f8f8', shadow: '#000000' }; // ITEMTEXTBASECOLOR / SHADOW
const ROWS = 7;   // étape 16 : 7 lignes visibles, comme Essentials (la 8e faisait déborder le cadre de sélection)

export default class BagScreen extends Phaser.GameObjects.Container {
    /**
     * keepOpen : le Sac reste affiché après le choix d'un objet (l'appelant le ferme avec destroy())
     */
    constructor(scene, bag, { mode = 'field', basePath, pocket = null, keepOpen = false } = {}) {
        super(scene, 0, 0);
        this.bag = bag;
        this.mode = mode;
        this.basePath = basePath;
        // Poche de départ : la dernière ouverte, sinon la première qui contient quelque chose
        const firstFull = Object.keys(bag.pockets).map(Number).find((p) => bag.pockets[p].length > 0) ?? 1;
        this.pocket = pocket ?? BagScreen.lastPocket ?? (mode === 'battle' ? 2 : firstFull);
        this.keepOpen = keepOpen;
        // On revient sur le dernier objet choisi de cette poche
        this.index = BagScreen.lastIndex?.[this.pocket] ?? 0;
        this.top = 0;
        this.setDepth(1400);
        scene.add.existing(this);
        this.promise = new Promise((resolve) => (this.resolve = resolve));
    }

    async open() {
        // Icônes de tous les objets du Sac
        const images = {};
        for (const items of Object.values(this.bag.pockets)) {
            for (const it of items) images[itemIconKey(it.icon)] = itemIconUrl(this.basePath, it.icon);
        }
        await loadImages(this.scene, images);
        this.build();
        this.draw();
        return this.promise;
    }

    get items() {
        return this.bag.pockets[this.pocket] ?? [];
    }

    /** Ligne sélectionnée : un objet, ou null pour "FERMER LE SAC" (la dernière ligne) */
    get selected() {
        return this.items[this.index] ?? null;
    }

    usable(item) {
        if (this.mode === 'give') return item.holdable;   // étape 14 : objets qu'un Pokémon peut tenir
        return this.mode !== 'battle' || item.useBattle;
    }

    build() {
        const s = this.scene;
        this.bg = s.add.image(0, 0, `bag_bg_${this.pocket}`).setOrigin(0, 0);
        this.sprite = s.add.image(30, 20, `bag_sprite_${this.pocket}`).setOrigin(0, 0);
        this.pocketIcon = s.add.image(0, 0, 'bag_icon_pocket').setOrigin(0, 0);
        this.pocketName = new PixelText(s, 94, 184 - 2, '', { ...LIST, align: 'center' });
        this.left = s.add.sprite(-4, 76, 'ui_left_arrow', 0).setOrigin(0, 0);
        this.right = s.add.sprite(150, 76, 'ui_right_arrow', 0).setOrigin(0, 0);
        this.cursor = s.add.image(184, 0, 'bag_cursor').setOrigin(0, 0);
        this.rows = s.add.container(0, 0);
        this.slider = s.add.container(0, 0);
        this.icon = s.add.image(48, 384 - 48, 'item_none');
        this.description = s.add.container(0, 0);
        this.add([this.bg, this.sprite, this.pocketIcon, this.pocketName, this.left, this.right, this.cursor, this.rows, this.slider, this.icon, this.description]);

        // Flèches animées (8 images)
        for (const [key, sprite] of [['ui_left_arrow', this.left], ['ui_right_arrow', this.right]]) {
            if (!s.anims.exists(key)) {
                s.anims.create({ key, frames: s.anims.generateFrameNumbers(key, { start: 0, end: 7 }), frameRate: 16, repeat: -1 });
            }
            sprite.play(key);
        }

        // Souris : flèches = poche précédente / suivante ; lignes = objets
        clickable(this.left, { onClick: () => this.handleKey('left') });
        clickable(this.right, { onClick: () => this.handleKey('right') });
        for (let r = 0; r < ROWS; r++) {
            const zone = s.add.zone(184, 10 + r * 32, 282, 32).setOrigin(0, 0);
            clickable(zone, {
                onHover: () => { if (this.top + r <= this.items.length && this.index !== this.top + r) { this.index = this.top + r; this.draw(); } },
                onClick: () => { if (this.top + r <= this.items.length) { this.index = this.top + r; this.handleKey('ok'); } },
            });
            this.add(zone);
        }
        // Clic sur une icône de poche (bande en bas à gauche)
        for (let p = 1; p <= 8; p++) {
            const zone = s.add.zone(2 + (p - 1) * 22, 226, 22, 28).setOrigin(0, 0);
            clickable(zone, { onClick: () => this.setPocket(p) });
            this.add(zone);
        }
    }

    setPocket(pocket) {
        this.pocket = pocket;
        BagScreen.lastPocket = pocket;
        this.index = Math.min(BagScreen.lastIndex?.[pocket] ?? 0, this.items.length);
        this.top = 0;
        this.draw();
    }

    draw() {
        const s = this.scene;
        this.bg.setTexture(`bag_bg_${this.pocket}`);
        this.sprite.setTexture(`bag_sprite_${this.pocket}`);
        // Icône de la poche active (icon_pocket.png : 8 icônes de 28 x 28 sur la 1re ligne)
        this.pocketIcon.setCrop((this.pocket - 1) * 28, 0, 28, 28);
        this.pocketIcon.setPosition(2 + (this.pocket - 1) * 22 - (this.pocket - 1) * 28, 226);
        this.pocketName.setText(this.bag.names[this.pocket]);

        // Liste : 8 lignes visibles, la dernière ligne de la poche est "FERMER LE SAC"
        const count = this.items.length + 1;
        if (this.index < this.top) this.top = this.index;
        if (this.index >= this.top + ROWS) this.top = this.index - ROWS + 1;
        this.rows.removeAll(true);
        for (let r = 0; r < ROWS; r++) {
            const i = this.top + r;
            if (i >= count) break;
            const y = 31 + r * 32;   // étape 16 : texte centré dans le cadre de sélection
            const item = this.items[i];
            if (!item) {
                this.rows.add(new PixelText(s, 200, y, 'FERMER LE SAC', LIST));
                continue;
            }
            const colors = this.usable(item) ? LIST : GREYED;
            this.rows.add(new PixelText(s, 200, y, item.name, colors));
            if (!item.key) {
                this.rows.add(new PixelText(s, 450, y, `x${String(item.quantity).padStart(3, ' ')}`, { ...colors, align: 'right' }));
            }
        }
        this.cursor.setY(10 + (this.index - this.top) * 32);

        // Ascenseur (seulement si la liste dépasse)
        this.slider.removeAll(true);
        if (count > ROWS) {
            const sliderHeight = 174;
            let box = Math.floor((sliderHeight * ROWS) / count);
            box += Math.min((sliderHeight - box) / 2, sliderHeight / 6);
            box = Math.max(Math.floor(box), 38);
            const y = 54 + Math.floor(((sliderHeight - box) * this.top) / (count - ROWS));
            const bar = s.add.image(470, y, 'bag_icon_slider').setOrigin(0, 0).setCrop(36, 0, 36, 38);
            bar.setX(470 - 36).setScale(1, box / 38).setOrigin(0, 0);
            this.slider.add(bar);
        }

        // Icône et description de l'objet sélectionné
        const item = this.selected;
        this.icon.setTexture(item && s.textures.exists(itemIconKey(item.icon)) ? itemIconKey(item.icon) : 'item_none');
        this.icon.setVisible(!!item);
        this.description.removeAll(true);
        const text = item ? (item.description ?? '') : 'Fermer le Sac.';
        // Description longue : police étroite, puis petite police (comme ça, rien n'est coupé)
        const fit = PixelText.fit(text, 404);
        fit.lines.forEach((line, i) => {
            this.description.add(new PixelText(s, 84, 288 + i * fit.lineHeight, line, { ...DESCRIPTION, font: fit.font }));
        });
    }

    handleKey(action) {
        if (this.done) return true;
        const count = this.items.length + 1;
        if (action === 'up' || action === 'down') {
            this.index = (this.index + (action === 'up' ? count - 1 : 1)) % count;
            this.draw();
        } else if (action === 'left' || action === 'right') {
            this.setPocket(((this.pocket - 1 + (action === 'left' ? 7 : 1)) % 8) + 1);
        } else if (action === 'ok') {
            const item = this.selected;
            if (!item) this.close(null);
            else if (this.usable(item)) {
                BagScreen.lastIndex = { ...(BagScreen.lastIndex ?? {}), [this.pocket]: this.index };
                if (this.keepOpen) {
                    this.done = true;       // le Sac reste affiché, mais ne réagit plus
                    this.resolve(item);
                } else {
                    this.close(item);
                }
            }
        } else if (action === 'cancel') {
            this.close(null);
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

