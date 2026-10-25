const TILE = 32;

/**
 * Les OBSTACLES des capacités de terrain, placés dans Tiled sur le calque d'objets "field"
 * (un objet d'une case avec la propriété "field") :
 *
 *   field = cut          petit arbre       -> Coupe        (sprites/field/tree.png)
 *   field = rock_smash   rocher fissuré    -> Éclate-Roc   (sprites/field/rock.png)
 *   field = strength     rocher            -> Force        (sprites/field/boulder.png)
 *
 * Les planches viennent d'Essentials (Characters/Object tree 1, Object rock, Object boulder) :
 * 4 x 4 cases de 32 x 32. Ligne 1 = l'objet entier, lignes 2 à 4 = l'animation quand il est coupé / brisé.
 *
 * Un arbre coupé ou un rocher brisé repousse quand on revient sur la carte (comme dans les jeux).
 * Un rocher poussé revient aussi à sa place.
 */
const SHEETS = { cut: 'field_tree', rock_smash: 'field_rock', strength: 'field_boulder' };

export default class FieldObjects {
    constructor(scene, objects) {
        this.scene = scene;
        this.list = [];
        this.createAnimations();

        for (const o of objects) {
            const kind = o.props.field;
            if (!SHEETS[kind]) {
                console.warn(`Objet "field" en (${o.x}, ${o.y}) : type "${kind}" inconnu (cut, rock_smash ou strength).`);
                continue;
            }
            const sprite = scene.add.sprite(o.x * TILE + TILE / 2, o.y * TILE + TILE, SHEETS[kind], 0).setOrigin(0.5, 1);
            sprite.setDepth(sprite.y);
            this.list.push({ kind, x: o.x, y: o.y, sprite });
        }
    }

    createAnimations() {
        for (const key of ['field_tree', 'field_rock']) {
            if (this.scene.anims.exists(`${key}_break`) || !this.scene.textures.exists(key)) continue;
            this.scene.anims.create({
                key: `${key}_break`,
                frames: this.scene.anims.generateFrameNumbers(key, { frames: [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15] }),
                frameRate: 20,
            });
        }
    }

    /** L'obstacle sur la case (x, y), ou undefined */
    at(x, y) {
        return this.list.find((o) => o.x === x && o.y === y);
    }

    /** Coupé ou brisé : petite animation, puis il disparaît */
    destroy(obj) {
        return new Promise((resolve) => {
            this.list = this.list.filter((o) => o !== obj);
            const anim = `${SHEETS[obj.kind]}_break`;
            if (!this.scene.anims.exists(anim)) {
                obj.sprite.destroy();
                resolve();
                return;
            }
            obj.sprite.play(anim);
            obj.sprite.once('animationcomplete', () => {
                obj.sprite.destroy();
                resolve();
            });
        });
    }

    /** Force : le rocher glisse d'une case */
    push(obj, x, y) {
        return new Promise((resolve) => {
            obj.x = x;
            obj.y = y;
            this.scene.tweens.add({
                targets: obj.sprite,
                x: x * TILE + TILE / 2,
                y: y * TILE + TILE,
                duration: 300,
                onUpdate: () => obj.sprite.setDepth(obj.sprite.y),
                onComplete: () => {
                    // Petite secousse de l'écran, comme dans les jeux
                    this.scene.cameras.main.shake(120, 0.004);
                    resolve();
                },
            });
        });
    }
}
