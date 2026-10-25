import { loadImages } from '../battle/BattleAssets.js';

/**
 * L'écran d'ÉVOLUTION d'Essentials (Graphics/UI/evolution_bg.png) :
 * "Hein ? Bulbizarre évolue !", les deux formes clignotent en blanc de plus en plus vite,
 * puis éclair blanc et nouvelle forme. Retour (X / Échap / clic droit) pendant l'animation l'annule.
 *
 *   const done = await new EvolutionScreen(scene, preview, ui).run();
 *   // ui.say(text, {pause}) ; renvoie true si le joueur l'a laissé évoluer
 */
export default class EvolutionScreen extends Phaser.GameObjects.Container {
    /** forced : évolution déjà faite par le serveur (échange) : Retour ne l'annule pas */
    constructor(scene, preview, ui, { forced = false } = {}) {
        super(scene, 0, 0);
        this.preview = preview;
        this.ui = ui;
        this.forced = forced;
        this.cancelled = false;
        this.setDepth(1900); // au-dessus du Sac et de l'écran d'équipe
        scene.add.existing(this);
    }

    async run() {
        const { from, to, name } = this.preview;
        await loadImages(this.scene, { [`pkmn_${from.sprite}`]: from.sprite, [`pkmn_${to.sprite}`]: to.sprite });

        // Centre de l'écran au-dessus de la boîte de message : (256, 160), comme Essentials
        this.add(this.scene.add.image(0, 0, 'evolution_bg').setOrigin(0, 0));
        this.oldSprite = this.scene.add.image(256, 160, `pkmn_${from.sprite}`);
        this.newSprite = this.scene.add.image(256, 160, `pkmn_${to.sprite}`).setVisible(false);
        // Silhouettes blanches (le rendu CANVAS de Phaser ne gère pas setTintFill : on les dessine nous-mêmes)
        this.oldWhite = this.scene.add.image(256, 160, whiteTexture(this.scene, `pkmn_${from.sprite}`)).setVisible(false);
        this.newWhite = this.scene.add.image(256, 160, whiteTexture(this.scene, `pkmn_${to.sprite}`)).setVisible(false);
        this.add([this.oldSprite, this.newSprite, this.oldWhite, this.newWhite]);
        this.scene.cameras.main.fadeIn(300, 0, 0, 0);
        this.scene.registry.get('audio')?.bgm('evolution');   // étape 12
        playCry(from.cry);

        await this.ui.say(`Hein ? ${name} évolue !`, { pause: true });

        // Pendant l'animation, la touche Retour annule l'évolution
        this.handleKey = (action) => {
            if (action === 'cancel' && !this.forced) this.cancelled = true;
            return true;
        };
        this.scene.focus = this;
        const evolved = await this.animate();
        this.scene.focus = null;

        if (!evolved) {
            this.showOnly(this.oldSprite);
            await this.ui.say(`Hein ? ${name} a cessé d'évoluer !`, { pause: true });
            this.destroy();
            return false;
        }

        this.scene.cameras.main.flash(400, 255, 255, 255);
        this.showOnly(this.newSprite);
        playCry(to.cry);
        return true; // l'appelant enregistre l'évolution puis affiche "Félicitations !"
    }

    /** Affiche une seule des 4 images (ancienne, nouvelle, et leurs silhouettes blanches) */
    showOnly(image) {
        for (const i of [this.oldSprite, this.newSprite, this.oldWhite, this.newWhite]) i.setVisible(i === image);
    }

    /** Les deux silhouettes blanches alternent de plus en plus vite (environ 4 secondes) */
    async animate() {
        this.showOnly(this.oldWhite);
        await wait(this.scene, 400);

        let delay = 420;
        let showNew = false;
        while (delay > 40) {
            if (this.cancelled) return false;
            showNew = !showNew;
            this.showOnly(showNew ? this.newWhite : this.oldWhite);
            await wait(this.scene, delay);
            delay = Math.floor(delay * 0.86);
        }
        return !this.cancelled;
    }
}

/** Crée (une fois) la texture "<clé>_white" : la même image, entièrement blanche */
function whiteTexture(scene, key) {
    const whiteKey = `${key}_white`;
    if (scene.textures.exists(whiteKey)) return whiteKey;
    const source = scene.textures.get(key).getSourceImage();
    const canvas = scene.textures.createCanvas(whiteKey, source.width, source.height);
    const ctx = canvas.getContext();
    ctx.drawImage(source, 0, 0);
    ctx.globalCompositeOperation = 'source-in'; // ne peint que là où l'image est opaque
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, source.width, source.height);
    canvas.refresh();
    return whiteKey;
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
