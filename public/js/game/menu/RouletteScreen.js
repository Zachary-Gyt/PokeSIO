import PixelText from '../ui/PixelText.js';
import { clickable } from '../ui/Controls.js';
import { api } from './Api.js';

/**
 * Étape 20 : la ROULETTE de Rubis / Saphir / Émeraude, portée du script Essentials de FL (Roulette Minigame 1.1.6,
 * licence MIT-0) : mêmes images, mêmes positions, mêmes vitesses de la roue et de la balle.
 *
 *   Flèches : choisir la mise (une case, une colonne = un Pokémon, une ligne = une couleur)
 *   Action : miser et lancer la balle   Retour : quitter
 *
 * Le SERVEUR tire la case quand on mise ; ici on montre la balle qui tourne et s'arrête dessus.
 *
 * Les angles sont ceux de RPG Maker (sens inverse des aiguilles d'une montre) ; Phaser tourne dans l'autre sens.
 */
const COLUMNS = 4;
const ROWS = 3;
const TABLE_POSITIONS = [[1, 10, 7, 4], [5, 2, 11, 8], [9, 6, 3, 0]];
const SPINS = [60 * 30, 36 * 20, 30 * 10, 20 * 3];
const COLORS = {
    red: [0xc02050, 0xf898a0],
    green: [0x519631, 0x84dd57],
    blue: [0x2050c0, 0x98a0f8],
};
const MULTIPLIER_COLORS = { 12: '#f8a888', 6: '#f7c70e', 4: '#c1a1f4', 3: '#f8f8f8' };

export default class RouletteScreen extends Phaser.GameObjects.Container {
    /** data : réponse du serveur à l'ouverture ({ wager, played, coins, rounds, max }) */
    constructor(scene, data, urls, color = 'red') {
        super(scene, 0, 0);
        this.urls = urls;
        this.wager = data.wager;
        this.coins = data.coins;
        this.displayed = data.coins;
        this.rounds = data.rounds ?? 6;
        this.played = data.played ?? Array(12).fill(false);
        this.colors = COLORS[color] ?? COLORS.red;
        this.moved = 0;
        this.busy = false;
        this.setDepth(1500);
        scene.add.existing(this);
    }

    async open() {
        const s = this.scene;
        this.add(s.add.rectangle(0, 0, 512, 384, this.colors[0]).setOrigin(0, 0));
        this.wheelAngle = 0;   // angle RGSS de la roue
        this.wheel = s.add.image(0, 192, 'roulette_roulette');
        this.table = s.add.image(0, 0, 'roulette_table').setOrigin(0, 0);
        this.multiplierBox = s.add.image(0, 0, 'roulette_multiplierbox').setOrigin(0, 0);
        this.creditBox = s.add.image(512 - 116 - 6, 6, 'roulette_creditbox').setOrigin(0, 0);
        this.add([this.wheel, this.table, this.multiplierBox, this.creditBox]);

        // Les balles sur la roue, sur le plateau, et les petites balles sous CREDIT (balles restantes)
        this.balls = [];         // balles posées sur la roue : { image, angle (RGSS), height }
        this.tableBalls = [];    // { image, cell }
        this.ballIcons = [];
        for (let i = 0; i < this.rounds; i++) {
            const icon = s.add.image(this.creditBox.x + 10 + (this.rounds - i - 1) * 16, this.creditBox.y + 56 + 2, 'roulette_ballicon').setOrigin(0, 0);
            this.add(icon);
            this.ballIcons.push(icon);
        }
        const used = this.played.filter(Boolean).length;
        this.ballIcons.forEach((icon, i) => this.useIcon(icon, i < used));

        this.cursor = s.add.image(0, 0, 'roulette_selectedsingle').setOrigin(0, 0);
        this.add(this.cursor);
        this.creditText = new PixelText(s, this.creditBox.x + 116 - 26, this.creditBox.y + 28, '', { color: '#f8f8f8', shadow: '#000000', align: 'right' });
        this.multiplierText = new PixelText(s, 0, 0, '', { color: '#f8a888', shadow: '#606070', align: 'right' });
        this.add([this.creditText, this.multiplierText]);

        // Souris : survoler une case du plateau déplace le curseur, cliquer mise
        for (let y = 0; y <= ROWS; y++) {
            for (let x = 0; x <= COLUMNS; x++) {
                if (x === 0 && y === 0) continue;
                const zone = s.add.zone(0, 0, 46, 46).setOrigin(0, 0);
                zone.cell = { x, y };
                clickable(zone, {
                    enabled: () => !this.busy,
                    onHover: () => { if (this.indexX !== x || this.indexY !== y) { this.setIndex(x, y, true); } },
                    onClick: () => { this.setIndex(x, y, true); this.handleKey('ok'); },
                });
                this.add(zone);
                (this.zones ??= []).push(zone);
            }
        }

        this.indexX = -1;
        this.indexY = -1;
        this.setIndex(1, 1);
        this.layout();
        this.drawCredits();
        this.drawMultiplier();

        this.blinkTime = 0;
        this.creditTime = 0;
        this.ticker = (time, delta) => this.update(Math.min(delta, 100) / 1000);
        s.events.on('update', this.ticker);

        this.busy = true;
        await s.say('Choisissez votre mise avec les flèches, puis appuyez sur Action.');
        this.busy = false;
        return new Promise((resolve) => (this.resolve = resolve));
    }

    useIcon(icon, used) {
        icon.setTexture(used ? 'roulette_ballusedicon' : 'roulette_ballicon');
        if (used) icon.setTint(this.colors[1]); else icon.clearTint();
    }

    // ---------------------------------------------------------------------
    // Le curseur (FL : Roulette::Cursor)
    // ---------------------------------------------------------------------

    setIndex(x, y, silent = false) {
        if (this.indexX !== -1 && !silent) this.scene.registry.get('audio')?.se('cursor');
        x = ((x % (COLUMNS + 1)) + COLUMNS + 1) % (COLUMNS + 1);
        y = ((y % (ROWS + 1)) + ROWS + 1) % (ROWS + 1);
        // Petits ajustements : la case (0, 0) n'existe pas
        if (this.indexY === 0 && x === 0) x = this.indexX === 1 ? COLUMNS : 1;
        if (this.indexX === 0 && y === 0) y = this.indexY === 1 ? ROWS : 1;
        if (x === 0 && y === 0) y = 1;
        const key = x === 0 ? 'roulette_selectedcolor' : y === 0 ? 'roulette_selectedspecies' : 'roulette_selectedsingle';
        this.cursor.setTexture(key);
        this.indexX = x;
        this.indexY = y;
        this.blinkTime = 0;
        this.cursor.setVisible(true);
        this.layout();
        this.drawMultiplier();
    }

    /** Les cases du plateau (ligne * 4 + colonne) couvertes par la mise */
    betCells() {
        if (this.indexX === 0) return [0, 1, 2, 3].map((c) => (this.indexY - 1) * COLUMNS + c);
        if (this.indexY === 0) return [0, 1, 2].map((r) => r * COLUMNS + this.indexX - 1);
        return [(this.indexY - 1) * COLUMNS + this.indexX - 1];
    }

    multiplier() {
        const free = this.betCells().filter((i) => !this.played[i]).length;
        return free === 0 ? 0 : Math.floor((COLUMNS * ROWS) / free);
    }

    // ---------------------------------------------------------------------
    // Positions (FL : refreshBottomElementsPos), "moved" = décalage pendant que la roue va au centre
    // ---------------------------------------------------------------------

    layout() {
        const moved = Math.floor(this.moved);
        this.wheel.x = Math.min(306 / 2 + 4 + Math.floor(this.moved / 2), 256);
        this.table.x = 512 - 244 - 10 + moved;
        this.table.y = 384 - 196 - 10;
        const baseBoxX = this.table.x - 14;
        this.multiplierBox.x = Math.min(baseBoxX, 512 - 56 - 10);
        this.multiplierBox.y = this.table.y + 6;
        this.multiplierText.setPosition(this.multiplierBox.x + 56 - 8, this.multiplierBox.y + 4);
        this.cursor.x = this.table.x - 2 + this.indexX * 48 + (this.indexX === 0 ? 4 : 0);
        this.cursor.y = this.table.y - 2 + this.indexY * 48 + (this.indexY === 0 ? 4 : 0);
        for (const b of this.tableBalls) {
            b.image.x = 6 + this.table.x + ((b.cell % COLUMNS) + 1) * 48;
            b.image.y = 6 + this.table.y + (Math.floor(b.cell / COLUMNS) + 1) * 48;
        }
        for (const zone of this.zones ?? []) {
            zone.x = this.table.x + zone.cell.x * 48;
            zone.y = this.table.y + zone.cell.y * 48;
        }
        this.placeBalls();
    }

    /** Une balle sur la roue : à (hauteur - 15) pixels du centre, dans la direction de son angle */
    placeBalls() {
        for (const b of this.balls) {
            const rad = (-b.angle * Math.PI) / 180;   // angle RGSS -> sens des aiguilles d'une montre
            const d = b.height - 15;
            b.image.setPosition(this.wheel.x + Math.sin(rad) * d, this.wheel.y - Math.cos(rad) * d).setAngle(-b.angle);
        }
        this.wheel.setAngle(-this.wheelAngle);
    }

    drawCredits() {
        this.creditText.setText(String(this.displayed));
    }

    drawMultiplier() {
        const m = this.multiplier();
        this.multiplierText.setText(m ? String(m) : '');
        if (m) {
            this.multiplierText.options.color = MULTIPLIER_COLORS[m] ?? '#f8a888';
            this.multiplierText.text = null;
            this.multiplierText.setText(String(m));
        }
    }

    // ---------------------------------------------------------------------
    // Chaque image
    // ---------------------------------------------------------------------

    update(dt) {
        // La roue tourne toujours (80 degrés par seconde), et les balles posées avec elle
        this.wheelAngle += 80 * dt;
        for (const b of this.balls) b.angle += 80 * dt;
        // Le curseur clignote (0,4 s)
        this.blinkTime += dt;
        if (this.blinkTime >= 0.4 && !this.spin) {
            this.cursor.setVisible(!this.cursor.visible);
            this.blinkTime = 0;
        }
        // Les jetons gagnés s'ajoutent un par un au compteur
        this.creditTime -= dt;
        if (this.displayed < this.coins && this.creditTime <= 0) {
            this.creditTime = 0.075;
            this.displayed += 1;
            this.drawCredits();
        }
        if (this.move) this.updateMove(dt);
        else if (this.spin) this.updateSpin(dt);
        this.placeBalls();
    }

    /** La roue glisse au centre (et le plateau sort à droite), ou revient (FL : movePictures) */
    updateMove(dt) {
        const speed = 480 * dt * (this.move.toCenter ? 1 : -1);
        this.moved = Math.max(this.moved + speed, 0);
        this.layout();
        if ((this.move.toCenter && this.table.x > 512) || (!this.move.toCenter && this.moved === 0)) {
            const done = this.move.done;
            this.move = null;
            done();
        }
    }

    /** La balle tourne de plus en plus lentement et se rapproche du centre (FL : spinRoulette) */
    updateSpin(dt) {
        const sp = this.spin;
        let degrees = 120;
        if (sp.left > SPINS[1] + SPINS[2] + sp.variable) degrees = 1200;
        else if (sp.left > SPINS[2] + sp.variable) degrees = 800;
        else if (sp.left > sp.variable) degrees = 400;
        const gain = Math.min(degrees * dt, sp.left);
        sp.ball.angle -= gain;
        sp.left -= gain;
        const heights = [[74, 0], [88, sp.variable], [98, SPINS[2] / 2 + sp.variable], [108, SPINS[2] + sp.variable],
            [118, SPINS[1] / 2 + SPINS[2] + sp.variable], [128, SPINS[1] + SPINS[2] + sp.variable]];
        for (const [height, min] of heights) {
            if (sp.left > min) continue;
            sp.ball.height = height;
            break;
        }
        if (!sp.ending && sp.left <= SPINS[2] + sp.variable) {
            sp.ending = true;
            this.ballSound?.pause();
            this.scene.registry.get('audio')?.se('roulette_ball_end');
        }
        if (sp.left <= 0) {
            this.spin = null;
            this.scene.registry.get('audio')?.se('ball_drop');
            sp.done();
        }
    }

    // ---------------------------------------------------------------------
    // Touches
    // ---------------------------------------------------------------------

    handleKey(action) {
        if (this.busy) return true;
        if (action === 'up') this.setIndex(this.indexX, this.indexY - 1);
        if (action === 'down') this.setIndex(this.indexX, this.indexY + 1);
        if (action === 'left') this.setIndex(this.indexX - 1, this.indexY);
        if (action === 'right') this.setIndex(this.indexX + 1, this.indexY);
        if (action === 'ok') this.bet();
        if (action === 'cancel') this.askQuit();
        return true;
    }

    get silent() {
        return true;
    }

    async askQuit() {
        this.busy = true;
        if (await this.scene.yesNo('Quitter la roulette ?')) return this.close();
        this.busy = false;
    }

    /** Miser : le serveur retire la mise et tire la case ; puis toute l'animation */
    async bet() {
        const audio = this.scene.registry.get('audio');
        if (this.multiplier() === 0) {
            audio?.se('buzzer');
            return;
        }
        this.busy = true;
        const r = await api.post(this.urls.roulettePlay, { x: this.indexX, y: this.indexY });
        if (!r.ok) {
            await this.scene.say(r.data.message ?? 'Impossible de miser.');
            this.busy = false;
            return;
        }
        const res = r.data;
        audio?.se('slots_coin');
        this.displayed -= this.wager;
        this.drawCredits();
        this.cursor.setVisible(true);

        await new Promise((done) => { this.move = { toCenter: true, done }; });
        await this.spinTo(res);
        await new Promise((done) => { this.move = { toCenter: false, done }; });
        await this.showResult(res);
    }

    /** Une balle lancée : elle tourne SPINS + 30 degrés par case, et s'arrête sur la case tirée (FL : startSpin) */
    spinTo(res) {
        const s = this.scene;
        const i = this.played.filter(Boolean).length;
        if (this.ballIcons[i]) this.useIcon(this.ballIcons[i], true);
        const image = s.add.image(0, 0, 'roulette_ball');
        this.add(image);
        const ball = { image, angle: this.wheelAngle - 10, height: 148 };
        this.balls.push(ball);
        const variable = 10 * 3 * res.slot + SPINS[3];
        this.ballSound = s.registry.get('audio')?.se('roulette_ball');
        return new Promise((done) => {
            this.spin = { ball, variable, left: SPINS[0] + SPINS[1] + SPINS[2] + variable, ending: false, done };
        });
    }

    /** La balle sur le plateau clignote, puis gagné ou perdu (FL : endSpin, blink, processResults) */
    async showResult(res) {
        const s = this.scene;
        const wait = (ms) => new Promise((resolve) => s.time.delayedCall(ms, resolve));
        const image = s.add.image(0, 0, 'roulette_ball').setOrigin(0, 0);
        this.add(image);
        this.tableBalls.push({ image, cell: res.result });
        this.layout();
        for (let b = 0; b < 8; b++) {
            image.setVisible(b % 2 === 1);
            await wait(100);
        }
        image.setVisible(true);
        await wait(50);

        const audio = s.registry.get('audio');
        if (res.win) {
            audio?.me(res.jackpot ? 'slots_big_win' : 'slots_win');
            await s.say(res.jackpot ? 'Jackpot !' : 'Gagné !');
            this.coins = res.coins;
            await s.say(`Vous gagnez ${res.payout} jetons !`);
        } else {
            audio?.se('buzzer');
            this.coins = res.coins;
            await s.say('Perdu !');
        }
        this.played = res.played;
        if (res.cleared) {
            if (this.rounds !== 1) await s.say('Le plateau de la roulette va être vidé.');
            this.balls.forEach((b) => b.image.destroy());
            this.tableBalls.forEach((b) => b.image.destroy());
            this.balls = [];
            this.tableBalls = [];
            this.ballIcons.forEach((icon) => this.useIcon(icon, false));
        }
        this.drawMultiplier();
        if (!(await s.yesNo('Continuer à jouer ?'))) return this.close();
        if (this.coins < this.wager) {
            await s.say('Vous n\'avez pas assez de jetons pour jouer !');
            return this.close();
        }
        this.busy = false;
    }

    close() {
        if (this.closed) return;
        this.closed = true;
        this.ballSound?.pause();
        this.scene.events.off('update', this.ticker);
        this.destroy();
        this.resolve(this.coins);
    }
}
