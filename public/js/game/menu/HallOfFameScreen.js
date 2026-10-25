import { loadImages } from '../battle/BattleAssets.js';
import PixelText from '../ui/PixelText.js';

/**
 * Étape 27 : le PANTHÉON, comme dans les jeux. Chaque Pokémon de l'équipe passe au centre, l'un après l'autre
 * (nom, niveau, son cri), puis toute l'équipe s'aligne, et le dresseur apparaît : « Bienvenue au Panthéon ! ».
 *
 *   await new HallOfFameScreen(scene, entry, ui, { ceremony: true }).run();
 *   // entry = HallOfFameEntry::present() : { number, date, trainer: { name, sprite }, team: [{ name, level, sprite, cry, shiny }] }
 *
 * ceremony = true : juste après la victoire (musique, cris, rythme lent). false : revu depuis le PC du Panthéon.
 */
const W = 512;
const H = 384;

export default class HallOfFameScreen extends Phaser.GameObjects.Container {
    constructor(scene, entry, ui, { ceremony = false } = {}) {
        super(scene, 0, 0);
        this.entry = entry;
        this.ui = ui;
        this.ceremony = ceremony;
        this.setDepth(1900);
        scene.add.existing(this);
    }

    async run() {
        const s = this.scene;
        const team = this.entry.team ?? [];
        const images = Object.fromEntries(team.map((p, i) => [`hof_${this.entry.id}_${i}`, p.sprite]));
        images[`hof_trainer_${this.entry.trainer.sprite}`] = this.entry.trainer.sprite;
        await loadImages(s, images);

        // Le fond : une salle sombre, avec des faisceaux de lumière dorée
        const bg = s.add.graphics();
        // (pas de dégradé : le jeu tourne en mode Canvas, où Phaser ne sait dessiner que des couleurs unies)
        const bands = [0x101830, 0x141c38, 0x182040, 0x1c2448, 0x202850, 0x242c58];
        bands.forEach((color, i) => {
            bg.fillStyle(color, 1);
            bg.fillRect(0, (H / bands.length) * i, W, H / bands.length + 1);
        });
        bg.fillStyle(0xf8e080, 0.08);
        for (let i = 0; i < 6; i++) bg.fillTriangle(40 + i * 86, 0, 10 + i * 86, H, 70 + i * 86, H);
        this.add(bg);
        const title = new PixelText(s, W / 2, 10, this.ceremony ? 'PANTHÉON' : `PANTHÉON — entrée n° ${this.entry.number}`, { color: '#f8d848', shadow: '#604000', align: 'center' });
        this.add(title);
        s.cameras.main.fadeIn(400, 0, 0, 0);
        if (this.ceremony) this.playMusic();

        // 1) Chaque Pokémon, seul au centre
        for (const [i, p] of team.entries()) {
            const key = `hof_${this.entry.id}_${i}`;
            const sprite = s.add.image(W + 80, 150, s.textures.exists(key) ? key : '__MISSING').setScale(1.4);
            const label = new PixelText(s, W / 2, 238, `${p.name}${p.shiny ? ' ★' : ''}   N. ${p.level}`, { color: '#f8f8f8', shadow: '#404058', align: 'center' });
            label.setAlpha(0);
            this.add([sprite, label]);
            await this.tween({ targets: sprite, x: W / 2, duration: 450, ease: 'Cubic.easeOut' });
            this.tween({ targets: label, alpha: 1, duration: 200 });
            if (this.ceremony) playCry(p.cry);
            await this.ui.say(`${p.name}${p.name !== p.speciesName ? ` (${p.speciesName})` : ''}, niveau ${p.level}.`, { pause: true });
            await this.tween({ targets: [sprite, label], alpha: 0, duration: 250 });
            sprite.destroy();
            label.destroy();
        }

        // 2) Toute l'équipe, en rang, et le dresseur
        const count = Math.max(1, team.length);
        team.forEach((p, i) => {
            const key = `hof_${this.entry.id}_${i}`;
            const x = (W / (count + 1)) * (i + 1);
            const mon = s.add.image(x, 110, s.textures.exists(key) ? key : '__MISSING').setScale(0.75).setAlpha(0);
            this.add(mon);
            this.tween({ targets: mon, alpha: 1, duration: 300, delay: i * 120 });
        });
        const tKey = `hof_trainer_${this.entry.trainer.sprite}`;
        if (s.textures.exists(tKey)) {
            const trainer = s.add.image(W / 2, 290, tKey).setOrigin(0.5, 1).setAlpha(0);
            this.add(trainer);
            await this.tween({ targets: trainer, alpha: 1, duration: 500, delay: 300 });
        }
        const name = this.entry.trainer.name;
        if (this.ceremony) {
            await this.ui.say(`Félicitations, ${name} ! Toi et tes Pokémon entrez au Panthéon de la Ligue Pokémon !`, { pause: true });
            await this.ui.say('Vos noms y resteront gravés à jamais !', { pause: true });
        } else {
            await this.ui.say(`${name} — ${this.entry.date}`, { pause: true });
        }
        await this.tween({ targets: this, alpha: 0, duration: 400 });
        this.destroy();
    }

    tween(config) {
        return new Promise((resolve) => this.scene.tweens.add({ ...config, onComplete: resolve }));
    }

    /** La musique du Panthéon d'Essentials ("Hall of Fame"), si elle est dans public/game/audio/bgm */
    playMusic() {
        const audio = this.scene.registry.get('audio');
        if (!audio) return;
        const url = audio.events.bgm?.hall_of_fame ?? audio.files.bgm?.['hall of fame'];
        if (url) audio.playMusic(url);
    }
}

function playCry(url) {
    if (!url) return;
    try {
        const sound = new Audio(url);
        sound.volume = 0.5;
        sound.play().catch(() => {});
    } catch (error) {
        // pas de son
    }
}
