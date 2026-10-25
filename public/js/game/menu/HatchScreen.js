import { loadImages } from '../battle/BattleAssets.js';

/**
 * Étape 18 : l'ÉCLOSION d'un Œuf, comme Essentials (Graphics/UI/hatch_bg.png, Pokemon/Eggs/000_cracks.png) :
 * "Hein ?", l'Œuf tremble de plus en plus, se fissure (5 images de fissures), éclair blanc,
 * et le Pokémon apparaît avec son cri. L'éclosion est déjà faite par le serveur : on ne fait que la montrer.
 *
 *   await new HatchScreen(scene, hatched, ui).run();
 *   // hatched = { pokemon: (Pokémon de PokemonPresenter), message: "Bulbizarre est sorti de l'Œuf !" }
 */
const CENTER_X = 256;
const CENTER_Y = 168;

export default class HatchScreen extends Phaser.GameObjects.Container {
    constructor(scene, hatched, ui) {
        super(scene, 0, 0);
        this.hatched = hatched;
        this.ui = ui;
        this.setDepth(1900);
        scene.add.existing(this);
    }

    async run() {
        const p = this.hatched.pokemon;
        const key = `pkmn_${p.sprites.front}`;
        await loadImages(this.scene, { [key]: p.sprites.front });

        const s = this.scene;
        this.add(s.add.image(0, 0, s.textures.exists('hatch_bg') ? 'hatch_bg' : 'evolution_bg').setOrigin(0, 0));
        this.egg = s.add.image(CENTER_X, CENTER_Y, 'egg_sprite');
        this.cracks = s.add.image(CENTER_X, CENTER_Y, 'egg_cracks', 0).setVisible(false);
        this.baby = s.add.image(CENTER_X, CENTER_Y, s.textures.exists(key) ? key : 'egg_sprite').setVisible(false);
        this.add([this.egg, this.cracks, this.baby]);
        s.cameras.main.fadeIn(300, 0, 0, 0);
        s.registry.get('audio')?.bgm('evolution');

        await this.ui.say('Hein ?', { pause: true });

        // L'Œuf tremble 4 fois, de plus en plus fort ; une fissure de plus à chaque fois
        for (let i = 0; i < 4; i++) {
            await this.shake(2 + i, 4 + i * 2);
            this.cracks.setFrame(i).setVisible(true);
            s.registry.get('audio')?.se('ball_drop');
            await wait(s, 500 - i * 80);
        }
        await this.shake(6, 10);
        this.cracks.setFrame(4);
        await wait(s, 300);

        // Éclair blanc : le Pokémon est là
        s.cameras.main.flash(500, 255, 255, 255);
        this.egg.setVisible(false);
        this.cracks.setVisible(false);
        this.baby.setVisible(true);
        playCry(p.sprites.cry);
        s.registry.get('audio')?.me('evolved', { resume: false });
        await wait(s, 600);

        await this.ui.say(this.hatched.message, { pause: true });
        this.destroy();
    }

    /** L'Œuf se balance de gauche à droite (Essentials : petits mouvements de 2 à 8 pixels) */
    shake(amplitude, times) {
        return new Promise((resolve) => {
            this.scene.tweens.add({
                targets: [this.egg, this.cracks],
                x: { from: CENTER_X - amplitude, to: CENTER_X + amplitude },
                duration: 60,
                yoyo: true,
                repeat: times,
                onComplete: () => {
                    this.egg.x = CENTER_X;
                    this.cracks.x = CENTER_X;
                    resolve();
                },
            });
        });
    }
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
