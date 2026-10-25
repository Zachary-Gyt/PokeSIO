/**
 * Étape 22 : les BUISSONS À BAIES (calque d'objets Tiled "berries").
 *
 * Le serveur dit l'étape de pousse de chaque buisson POUR CE JOUEUR :
 *   0 plantée   1 a germé   2 grandit   3 en fleurs   4 des Baies
 * Planches d'Essentials (Graphics/Characters, copiées dans sprites/berries) :
 *   berrytreeplanted.png (étape 0) ; berrytree_ORANBERRY.png : 4 lignes (étapes 1 à 4) de 4 images (32 x 64) qui se balancent.
 * Un buisson bloque le passage, comme un PNJ.
 *
 *   this.berryBushes = new BerryBushes(scene);
 *   this.berryBushes.set([{ key, x, y, berry, name, sprite, stage }]);
 *   this.berryBushes.at(x, y);          // le buisson sur cette case, ou undefined
 *   this.berryBushes.setStage(key, 0);  // après la cueillette
 */
const TILE = 32;

/** "oran-berry" -> "berrytree_ORANBERRY" (nom de la planche d'Essentials) */
export function berrySheet(slug) {
    return `berrytree_${String(slug).toUpperCase().replace(/[-'. ]/g, '')}`;
}

export default class BerryBushes {
    constructor(scene) {
        this.scene = scene;
        this.list = [];
    }

    set(bushes) {
        this.destroy();
        this.list = bushes.map((b) => {
            const bush = { ...b, sheet: b.sprite ?? berrySheet(b.berry) };
            const y = b.y * TILE + TILE;
            bush.image = this.scene.add.sprite(b.x * TILE + TILE / 2, y, '__DEFAULT').setOrigin(0.5, 1).setDepth(y);
            this.draw(bush);
            return bush;
        });
    }

    /** L'image de l'étape (et l'animation : la plante se balance doucement) */
    draw(bush) {
        const s = bush.image;
        s.anims?.stop();
        const key = bush.stage <= 0 ? 'berrytreeplanted' : bush.sheet;
        if (!this.scene.textures.exists(key)) {
            s.setVisible(false);
            return;
        }
        s.setVisible(true);
        const row = Math.max(0, Math.min(3, (bush.stage <= 0 ? 1 : bush.stage) - 1));
        const anim = `${key}_stage${row}`;
        if (!this.scene.anims.exists(anim)) {
            this.scene.anims.create({
                key: anim,
                frames: this.scene.anims.generateFrameNumbers(key, { start: row * 4, end: row * 4 + 3 }),
                frameRate: 3,
                repeat: -1,
            });
        }
        s.setTexture(key, row * 4);
        s.play(anim);
    }

    at(x, y) {
        return this.list.find((b) => b.x === x && b.y === y);
    }

    setStage(key, stage) {
        const b = this.list.find((bush) => bush.key === key);
        if (!b) return;
        b.stage = stage;
        this.draw(b);
    }

    destroy() {
        this.list.forEach((b) => b.image?.destroy());
        this.list = [];
    }
}
