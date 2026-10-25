import PixelText from '../ui/PixelText.js';
import { createWindow } from '../ui/WindowSkin.js';
import { loadImages } from '../battle/BattleAssets.js';
import { clickable } from '../ui/Controls.js';
import { itemIconKey, itemIconUrl } from './MenuAssets.js';
import { formatMoney } from './TrainerCardScreen.js';

/**
 * L'écran d'ACHAT de la Boutique d'Essentials (Graphics/UI/Mart), positions reprises de UI_Mart.rb :
 *   - liste des objets et de leur prix, à droite (fenêtre en (180, 10), 7 lignes) ;
 *   - argent en haut à gauche, "Dans le Sac" au-dessus de l'icône ;
 *   - icône et description de l'objet en bas.
 *
 *   const screen = new MartScreen(scene, { stock, money, basePath });
 *   const item = await screen.choose();     // l'objet choisi (l'écran reste affiché), ou null : fermer
 */
const LIST = { color: '#585850', shadow: '#a8b8b8' };        // (88, 88, 80) / (168, 184, 184)
const DESCRIPTION = { color: '#f8f8f8', shadow: '#000000' };
const ROWS = 7;
const T = (y) => y - 2;

export default class MartScreen extends Phaser.GameObjects.Container {
    constructor(scene, { stock, money, basePath }) {
        super(scene, 0, 0);
        this.stock = stock;
        this.money = money;
        this.basePath = basePath;
        this.index = 0;
        this.top = 0;
        this.active = false;
        this.setDepth(1400);
        scene.add.existing(this);
    }

    async build() {
        const s = this.scene;
        const images = {};
        for (const it of this.stock) images[itemIconKey(it.icon)] = itemIconUrl(this.basePath, it.icon);
        await loadImages(s, images);

        this.add(s.add.image(0, 0, 'mart_bg').setOrigin(0, 0));
        this.cursor = s.add.image(196, 0, 'mart_cursor').setOrigin(0, 0);
        this.rows = s.add.container(0, 0);
        this.icon = s.add.image(36, 384 - 50, 'item_none');
        this.description = s.add.container(0, 0);

        // Fenêtres de l'argent et de la quantité dans le Sac (cadre des menus du jeu)
        this.moneyWindow = s.add.container(0, 0);
        this.moneyWindow.add(createWindow(s, 'ui_choice', 190, 96));
        this.moneyText = new PixelText(s, 16, T(16), 'Argent :', LIST);
        this.moneyValue = new PixelText(s, 174, T(48), '', { ...LIST, align: 'right' });
        this.moneyWindow.add([this.moneyText, this.moneyValue]);

        this.qtyWindow = s.add.container(0, 384 - 102 - 64);
        this.qtyWindow.add(createWindow(s, 'ui_choice', 190, 64));
        this.qtyText = new PixelText(s, 16, T(16), 'Dans le Sac :', { ...LIST, font: 'narrow' }); // police étroite : place pour le nombre
        this.qtyValue = new PixelText(s, 174, T(16), '', { ...LIST, align: 'right' });
        this.qtyWindow.add([this.qtyText, this.qtyValue]);

        this.add([this.cursor, this.rows, this.icon, this.description, this.moneyWindow, this.qtyWindow]);

        // Souris : une zone par ligne
        for (let r = 0; r < ROWS; r++) {
            const zone = s.add.zone(196, 26 + r * 32, 314, 32).setOrigin(0, 0);
            clickable(zone, {
                enabled: () => this.active,
                onHover: () => { if (this.top + r <= this.stock.length && this.index !== this.top + r) { this.index = this.top + r; this.draw(); } },
                onClick: () => { if (this.top + r <= this.stock.length) { this.index = this.top + r; this.handleKey('ok'); } },
            });
            this.add(zone);
        }
        this.draw();
    }

    /** Attend que le joueur choisisse un objet ; l'écran reste affiché pendant les questions */
    choose() {
        this.active = true;
        this.draw();
        return new Promise((resolve) => (this.resolve = resolve));
    }

    update({ stock, money }) {
        if (stock) this.stock = stock;
        if (money !== undefined) this.money = money;
        this.index = Math.min(this.index, this.stock.length);
        this.draw();
    }

    get selected() {
        return this.stock[this.index] ?? null;
    }

    draw() {
        const s = this.scene;
        const count = this.stock.length + 1;
        if (this.index < this.top) this.top = this.index;
        if (this.index >= this.top + ROWS) this.top = this.index - ROWS + 1;

        this.rows.removeAll(true);
        for (let r = 0; r < ROWS; r++) {
            const i = this.top + r;
            if (i >= count) break;
            const y = T(26 + r * 32 + 2);
            const item = this.stock[i];
            if (!item) {
                this.rows.add(new PixelText(s, 212, y, 'QUITTER', LIST));
                continue;
            }
            this.rows.add(new PixelText(s, 212, y, item.name, LIST));
            this.rows.add(new PixelText(s, 492, y, `${formatMoney(item.price)} ₽`, { ...LIST, align: 'right' }));
        }
        this.cursor.setY(26 + (this.index - this.top) * 32 + 2).setVisible(this.active);

        this.moneyValue.setText(`${formatMoney(this.money)} ₽`);

        const item = this.selected;
        this.icon.setTexture(item && s.textures.exists(itemIconKey(item.icon)) ? itemIconKey(item.icon) : 'item_none').setVisible(!!item);
        this.qtyWindow.setVisible(!!item);
        this.qtyValue.setText(item ? String(item.quantity) : '');
        this.description.removeAll(true);
        const text = item ? (item.description ?? '') : 'Quitter la Boutique.';
        // Description longue : police étroite, puis petite police (comme ça, rien n'est coupé)
        const fit = PixelText.fit(text, 416);
        fit.lines.forEach((line, i) => {
            this.description.add(new PixelText(s, 80, T(288 + i * fit.lineHeight), line, { ...DESCRIPTION, font: fit.font }));
        });
    }

    /** La description est masquée pendant les questions (la boîte de message prend sa place) */
    showDescription(visible) {
        this.description.setVisible(visible);
    }

    handleKey(action) {
        if (!this.active) return true;
        const count = this.stock.length + 1;
        if (action === 'up' || action === 'down') {
            this.index = (this.index + (action === 'up' ? count - 1 : 1)) % count;
            this.draw();
        } else if (action === 'left' || action === 'right') {
            this.index = Math.max(0, Math.min(count - 1, this.index + (action === 'left' ? -ROWS : ROWS)));
            this.draw();
        } else if (action === 'ok' || action === 'cancel') {
            this.active = false;
            this.draw();
            this.resolve(action === 'ok' ? this.selected : null);
        }
        return true;
    }

    get wheel() {
        return true;
    }
}

