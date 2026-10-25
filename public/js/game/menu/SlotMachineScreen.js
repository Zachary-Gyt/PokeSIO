import PixelText from '../ui/PixelText.js';
import { clickable } from '../ui/Controls.js';
import { api } from './Api.js';

/**
 * Étape 20 : la MACHINE À SOUS, comme Pokémon Essentials (Graphics/UI/Slot Machine, Minigame_SlotMachine.rb :
 * mêmes images, mêmes positions, même vitesse des rouleaux, mêmes animations WIN / LOSE / STOP).
 *
 *   Bas : miser 1 jeton (jusqu'à 3)   Action : lancer, puis arrêter les rouleaux un par un   Retour : quitter
 *
 * Le SERVEUR décide du résultat quand la partie est lancée (positions d'arrêt des rouleaux, gain) ;
 * ici on fait tourner les rouleaux et on les arrête exactement sur ces positions.
 *
 *   await new SlotMachineScreen(menuScene, { reels, coins, max }, urls).open();
 */
const SCROLLSPEED = 16;   // pixels par image (60 images / s), comme Essentials
const REEL_X = [64, 144, 224];
const REEL_Y = 112;
const ROW_Y = [170, 122, 218, 82, 82];
const ROW_KEYS = ['slot_line1', 'slot_line2', 'slot_line2', 'slot_line3a', 'slot_line3b'];

class Reel {
    constructor(screen, index, symbols) {
        this.screen = screen;
        this.symbols = symbols;
        this.index = Math.floor(Math.random() * symbols.length);
        this.toppos = 0;
        this.spinning = false;
        this.stopping = false;
        this.target = null;
        const scene = screen.scene;
        this.key = `__slot_reel_${index}_${Date.now()}`;
        this.texture = scene.textures.createCanvas(this.key, 64, 144);
        this.image = scene.add.image(REEL_X[index], REEL_Y, this.key).setOrigin(0, 0);
        screen.add(this.image);
        this.draw();
    }

    start() {
        this.spinning = true;
        this.stopping = false;
        this.target = null;
    }

    /** Arrêter : le rouleau continue jusqu'à la position décidée par le serveur (au plus un tour) */
    stop(target) {
        this.stopping = true;
        this.target = target;
    }

    /** Une image (1/60 s) : comme SlotMachineReel#update */
    step() {
        if (this.spinning && this.stopping && this.target !== null && this.toppos === 0 && this.index === this.target) {
            this.spinning = false;
            this.stopping = false;
        }
        if (this.spinning) {
            this.toppos += SCROLLSPEED;
            if (this.toppos > 0) {
                this.toppos -= 48;
                this.index = (this.index + 1) % this.symbols.length;
            }
        }
    }

    draw() {
        const ctx = this.texture.getContext();
        const images = this.screen.scene.textures.get('slot_images').getSourceImage();
        const overlay = this.screen.scene.textures.get('slot_overlay').getSourceImage();
        ctx.clearRect(0, 0, 64, 144);
        const n = this.symbols.length;
        for (let i = 0; i < 4; i++) {
            const symbol = this.symbols[(this.index - i + n * 4) % n];
            ctx.drawImage(images, symbol * 64, 0, 64, 48, 0, this.toppos + i * 48, 64, 48);
        }
        ctx.drawImage(overlay, 0, 0);
        this.texture.refresh();
    }

    destroy() {
        this.image.destroy();
        this.screen.scene.textures.remove(this.key);
    }
}

/** Les compteurs CREDIT et PAYOUT (Graphics/UI/Slot Machine/numbers : chiffres de 14 x 22) */
class Score {
    constructor(screen, x, y, value) {
        this.screen = screen;
        this.digits = [];
        for (let i = 0; i < 5; i++) {
            const d = screen.scene.add.image(x + 14 * i, y, 'slot_numbers', 0).setOrigin(0, 0);
            screen.add(d);
            this.digits.push(d);
        }
        this.set(value);
    }

    set(value) {
        this.value = Math.max(0, Math.round(value));
        for (let i = 0; i < 5; i++) this.digits[i].setFrame(Math.floor(this.value / 10 ** (4 - i)) % 10);
    }
}

export default class SlotMachineScreen extends Phaser.GameObjects.Container {
    constructor(scene, data, urls) {
        super(scene, 0, 0);
        this.urls = urls;
        this.info = data;
        this.max = data.max ?? 9999;
        this.wager = 0;
        this.state = 'insert';   // insert | spinning | payout | done
        this.frame = 0;
        this.replay = false;
        this.setDepth(1500);
        scene.add.existing(this);
    }

    open() {
        const s = this.scene;
        this.add(s.add.image(0, 0, 'slot_bg').setOrigin(0, 0));
        // La barre du haut (comme le Casino d'Essentials) : les commandes, cliquables
        this.hint(150, '▼ MISER', () => this.handleKey('down'));
        this.hint(290, 'STOP', () => this.handleKey('ok'));
        this.hint(400, 'QUITTER', () => this.handleKey('cancel'));

        this.reels = this.info.reels.map((symbols, i) => new Reel(this, i, symbols));
        this.buttons = [0, 1, 2].map((i) => {
            const b = s.add.image(68 + 80 * i, 260, 'slot_button').setOrigin(0, 0).setVisible(false);
            this.add(b);
            return b;
        });
        this.rows = ROW_KEYS.map((key, i) => {
            const r = s.add.image(2, ROW_Y[i], key).setOrigin(0, 0).setVisible(false);
            this.add(r);
            return r;
        });
        this.lights = [16, 240].map((x, i) => {
            const l = s.add.image(x, 32, 'slot_lights', 0).setOrigin(0, 0).setVisible(false).setFlipX(i === 1);
            this.add(l);
            return l;
        });
        this.window1 = s.add.image(358, 96, 'slot_insert', 0).setOrigin(0, 0);
        this.window2 = s.add.image(358, 96, 'slot_press', 0).setOrigin(0, 0).setVisible(false);
        this.add([this.window1, this.window2]);
        this.credit = new Score(this, 360, 66, this.info.coins);
        this.payout = new Score(this, 438, 66, 0);
        // Clic sur les rouleaux : comme la touche Action
        const zone = s.add.zone(56, 100, 248, 216).setOrigin(0, 0);
        clickable(zone, { onClick: () => this.handleKey('ok') });
        this.add(zone);

        this.elapsed = 0;
        this.ticker = (time, delta) => this.update(delta);
        s.events.on('update', this.ticker);
        return new Promise((resolve) => (this.resolve = resolve));
    }

    hint(x, label, onClick) {
        const t = new PixelText(this.scene, x, 4, label, { color: '#f8f8f8', shadow: '#384868' });
        clickable(t, { onClick });
        this.add(t);
    }

    /** Une fenêtre d'animation (planche "key" découpée en images de 152 x 208, ou 152 x 104 pour le bonus) */
    showWindow(win, key, frame) {
        win.setTexture(key, frame).setVisible(true);
    }

    /** Appelé à chaque image du jeu ; on avance par pas de 1/60 s, comme Essentials */
    update(delta) {
        this.elapsed += Math.min(delta, 100);
        while (this.elapsed >= 1000 / 60) {
            this.elapsed -= 1000 / 60;
            this.tick();
        }
        this.reels.forEach((r) => r.draw());
    }

    tick() {
        this.frame = (this.frame + 1) % 240;
        this.reels.forEach((r) => r.step());
        if (this.state === 'insert') {
            // INSERT COIN (2 images) et PRESS (quand une mise est posée)
            this.showWindow(this.window1, 'slot_insert', Math.floor(this.frame / 24) % 2);
            if (this.wager > 0) this.showWindow(this.window2, 'slot_press', Math.floor(this.frame / 24) % 2);
            else this.window2.setVisible(false);
        } else if (this.state === 'spinning') {
            this.showWindow(this.window1, 'slot_stop', Math.floor(this.frame / 15) % 4);
            this.window2.setVisible(false);
            if (!this.reels[2].spinning && this.result) {
                this.state = 'payout';
                this.showPayout();
            }
        }
    }

    handleKey(action) {
        const audio = this.scene.registry.get('audio');
        if (this.state === 'insert') {
            if (action === 'down' && this.wager < 3 && this.credit.value > 0 && !this.replay) {
                audio?.se('slots_coin');
                this.wager += 1;
                this.credit.set(this.credit.value - 1);
                this.rows.forEach((r, i) => r.setVisible(i < [0, 1, 3, 5][this.wager]));
                if (this.wager >= 3 || this.credit.value === 0) this.start();
            } else if ((action === 'ok' && this.wager > 0) || (action === 'ok' && this.replay)) {
                this.start();
            } else if (action === 'cancel' && this.wager === 0 && !this.replay) {
                this.close();
            }
        } else if (this.state === 'spinning' && action === 'ok') {
            const reel = this.reels.find((r) => r.spinning && !r.stopping);
            if (reel && this.result) {
                audio?.se('slots_stop');
                const i = this.reels.indexOf(reel);
                reel.stop(this.result.stops[i]);
                this.buttons[i].setVisible(true);
            }
        }
        return true;
    }

    get silent() {
        return true;   // les sons sont ceux de la machine
    }

    /** Lancer : le serveur retire la mise et décide du résultat ; les rouleaux tournent pendant ce temps */
    async start() {
        if (this.replay) {
            this.wager = 3;
            this.rows.forEach((r) => r.setVisible(true));
        }
        this.state = 'spinning';
        this.result = null;
        this.frame = 0;
        this.reels.forEach((r) => r.start());
        const r = await api.post(this.urls.slotsPlay, { wager: this.wager });
        if (!r.ok) {
            // Refusé (plus de jetons...) : on arrête tout et on rend la mise affichée
            this.reels.forEach((reel) => { reel.spinning = false; });
            this.credit.set(this.info.coins);
            this.wager = 0;
            this.state = 'insert';
            this.rows.forEach((row) => row.setVisible(false));
            await this.scene.say(r.data.message ?? 'La machine est en panne.');
            return;
        }
        this.result = r.data;
    }

    /** Le gain (Essentials : pbPayout) : lignes gagnantes qui clignotent, WIN, lumières, puis les jetons défilent */
    async showPayout() {
        const s = this.scene;
        const res = this.result;
        const audio = s.registry.get('audio');
        this.payout.set(res.payout);
        const wait = (ms) => new Promise((resolve) => s.time.delayedCall(ms, resolve));
        if (res.payout > 0 || res.replay) {
            audio?.me(res.bonus > 0 ? 'slots_big_win' : 'slots_win');
            for (let f = 0; f < 24; f++) {   // 3 s, 8 images par seconde
                this.showWindow(this.window1, 'slot_win', f % 4);
                if (res.bonus > 0) this.showWindow(this.window2, 'slot_bonus', res.bonus - 1);
                else this.window2.setVisible(false);
                this.lights.forEach((l) => l.setTexture('slot_lights', f % 4).setVisible(true));
                this.rows.forEach((row, i) => row.setVisible(!!res.lines[i] && f % 2 === 0));
                await wait(125);
            }
            this.lights.forEach((l) => l.setVisible(false));
            this.window2.setVisible(false);
            this.showWindow(this.window1, 'slot_win', 0);
            // Les jetons passent de PAYOUT à CREDIT
            while (this.payout.value > 0) {
                const step = Math.max(1, Math.floor(this.payout.value / 40));
                this.payout.set(this.payout.value - step);
                this.credit.set(Math.min(this.max, this.credit.value + step));
                if (this.payout.value % 5 === 0) audio?.se('slots_coin', { volume: 0.5 });
                await wait(25);
            }
            await wait(500);
        } else {
            for (let f = 0; f < 8; f++) {   // 2 s
                this.showWindow(this.window1, 'slot_lose', f % 2);
                await wait(250);
            }
        }
        // Remise à zéro (Essentials : boutons, lignes)
        this.credit.set(res.coins);
        this.info.coins = res.coins;
        this.replay = !!res.replay;
        this.buttons.forEach((b) => b.setVisible(false));
        this.rows.forEach((row) => row.setVisible(false));
        this.wager = 0;
        this.state = 'insert';

        if (res.coins >= this.max) {
            await this.scene.say(`Vous avez ${this.max.toLocaleString('fr-FR')} jetons !`);
            this.close();
        } else if (res.coins <= 0 && !this.replay) {
            await this.scene.say('Vous n\'avez plus de jetons.\nFin de la partie !');
            this.close();
        } else if (this.replay) {
            this.start();   // REPLAY : la partie suivante est gratuite, avec une mise de 3
        }
    }

    close() {
        if (this.state === 'done') return;
        this.state = 'done';
        this.scene.events.off('update', this.ticker);
        this.reels.forEach((r) => r.destroy());
        this.destroy();
        this.resolve(this.info.coins);
    }
}
