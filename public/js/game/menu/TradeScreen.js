import { loadImages } from '../battle/BattleAssets.js';
import { createWindow } from '../ui/WindowSkin.js';
import PixelText from '../ui/PixelText.js';
import { tintFill, clearTint } from '../ui/Tint.js';

const DARK = { color: '#505058', shadow: '#a0a0a8' };
const GREEN = { color: '#208020', shadow: '#90c890' };
const GREY = { color: '#909098', shadow: '#d0d0d8' };

/**
 * L'écran d'ÉCHANGE entre deux joueurs : à gauche mon offre, à droite celle de l'autre joueur,
 * sur le fond "trade_bg" d'Essentials. Les commandes et messages sont ceux de MenuScene.
 *
 *   const screen = new TradeScreen(scene, view);   // view : réponse de /echange/{id}
 *   await screen.update(view);                      // après chaque changement
 *   await screen.playTrade(view, ui);               // l'animation de l'échange terminé
 */
export default class TradeScreen extends Phaser.GameObjects.Container {
    constructor(scene, view) {
        super(scene, 0, 0);
        this.setDepth(1300);
        scene.add.existing(this);

        this.add(scene.add.image(0, 0, 'trade_bg').setOrigin(0, 0));

        const title = scene.add.container(0, 0);
        title.add(createWindow(scene, 'ui_choice', 512, 64));
        this.title = new PixelText(scene, 256, 14, '', { ...DARK, align: 'center' });
        title.add(this.title);
        this.add(title);

        // Deux cadres à gauche : la fenêtre des commandes s'ouvre à droite sans les cacher
        this.panels = {
            mine: this.panel(8, 72),
            theirs: this.panel(158, 72),
        };
        this.view = view;
    }

    panel(x, y) {
        const s = this.scene;
        const c = s.add.container(x, y);
        c.add(createWindow(s, 'ui_choice', 146, 208));
        const owner = new PixelText(s, 73, 12, '', { ...DARK, font: 'narrow', align: 'center' });
        const sprite = s.add.image(73, 136, '__DEFAULT').setOrigin(0.5, 1).setVisible(false);
        const name = new PixelText(s, 73, 128, '', { ...DARK, font: 'narrow', align: 'center' });
        const level = new PixelText(s, 73, 156, '', { ...DARK, font: 'small', align: 'center' });
        const state = new PixelText(s, 73, 176, '', { ...GREY, font: 'small', align: 'center' });
        c.add([owner, sprite, name, level, state]);
        this.add(c);

        return { c, owner, sprite, name, level, state };
    }

    async update(view) {
        this.view = view;
        this.title.setText(`Échange avec ${view.partner.name}`);
        const offers = [view.mine.offer, view.theirs.offer].filter(Boolean);
        await loadImages(this.scene, Object.fromEntries(offers.map((p) => [`pkmn_${p.sprites.front}`, p.sprites.front])));
        if (!this.active) return; // l'écran a été fermé pendant le chargement

        this.fill(this.panels.mine, 'Vous', view.mine);
        this.fill(this.panels.theirs, view.partner.name, view.theirs);
    }

    fill(panel, owner, side) {
        panel.owner.setText(owner);
        const p = side.offer;
        if (!p) {
            panel.sprite.setVisible(false);
            panel.name.setText('');
            panel.level.setText('');
            panel.state.setText('Aucune offre');
            this.setColors(panel.state, GREY);
            return;
        }
        panel.sprite.setTexture(`pkmn_${p.sprites.front}`).setVisible(true);
        // Les sprites d'Essentials font 192 x 192 : réduits de moitié dans le cadre (pieds au-dessus du nom)
        panel.sprite.setScale(0.5).setY(118);
        panel.name.setText(p.name);
        panel.level.setText(`N. ${p.level}`);
        panel.state.setText(side.confirmed ? 'Prêt !' : 'Réfléchit...');
        this.setColors(panel.state, side.confirmed ? GREEN : GREY);
    }

    setColors(text, colors) {
        if (text.options.color === colors.color) return;
        text.options.color = colors.color;
        text.options.shadow = colors.shadow;
        const value = text.text;
        text.text = null;
        text.setText(value);
    }

    /**
     * L'échange a eu lieu : mon Pokémon part dans sa Poké Ball, la Ball s'envole,
     * celle de l'autre joueur arrive et son Pokémon en sort (comme Essentials, en plus simple).
     */
    async playTrade(view, ui) {
        const s = this.scene;
        const sent = view.mine.offer;
        let received = view.theirs.offer;
        const partner = view.partner.name;
        // Il a déjà évolué sur le serveur (Kadabra -> Alakazam) : il ARRIVE sous son ancienne forme
        if (view.evolution) {
            const from = view.evolution.from;
            received = { ...received, name: view.evolution.name, sprites: { ...received.sprites, front: from.sprite, cry: from.cry } };
            await loadImages(s, { [`pkmn_${from.sprite}`]: from.sprite });
        }

        // On ne garde que le fond
        for (const panel of Object.values(this.panels)) panel.c.setVisible(false);
        this.list[1].setVisible(false);

        const mon = s.add.image(256, 200, `pkmn_${sent.sprites.front}`).setOrigin(0.5, 1);
        this.add(mon);
        playCry(sent.sprites.cry);
        await ui.say(`Vous envoyez ${sent.name} à ${partner}.`, { pause: true });

        // Dans la Ball...
        const ball = s.add.sprite(256, 150, 'ball_POKEBALL', 0).setVisible(false);
        this.add(ball);
        tintFill(mon, 0xffffff);
        await tween(s, { targets: mon, scale: 0, y: 150, duration: 400 });
        ball.setVisible(true);
        await wait(s, 300);
        // ... qui s'envole
        await tween(s, { targets: ball, y: -60, duration: 600, ease: 'Sine.easeIn' });
        await wait(s, 500);

        // La Ball de l'autre joueur tombe du ciel
        ball.setTexture('ball_POKEBALL', 0).setY(-60);
        await tween(s, { targets: ball, y: 150, duration: 600, ease: 'Bounce.easeOut' });
        await wait(s, 300);
        ball.setTexture('ball_POKEBALL_open');
        const newMon = s.add.image(256, 150, `pkmn_${received.sprites.front}`).setOrigin(0.5, 1).setScale(0);
        this.add(newMon);
        tintFill(newMon, 0xffffff);
        await tween(s, { targets: newMon, scale: 1, y: 200, duration: 400 });
        ball.destroy();
        clearTint(newMon);
        playCry(received.sprites.cry);

        await ui.say(`${partner} vous envoie ${received.name}.`, { pause: true });
        await ui.say(`Prenez bien soin de ${received.name} !`, { pause: true });
    }
}

function tween(scene, config) {
    return new Promise((resolve) => scene.tweens.add({ ...config, onComplete: resolve }));
}

function wait(scene, ms) {
    return new Promise((resolve) => scene.time.delayedCall(ms, resolve));
}

function playCry(url) {
    if (!url) return;
    try {
        const audio = new Audio(url);
        audio.volume = 0.5;
        audio.play().catch(() => {});
    } catch (error) {
        // pas de son
    }
}
