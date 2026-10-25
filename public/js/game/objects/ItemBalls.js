/**
 * Étape 16 : les POKÉ BALLS posées par terre (calque d'objets Tiled "items").
 *
 * Le serveur dit quelles Balls sont encore là pour ce joueur (les autres ont déjà été ramassées).
 * Une Ball visible bloque le passage, comme un rocher. Une Ball "cachée" (propriété hidden)
 * ne se voit pas : on la trouve en appuyant sur Action devant la bonne case.
 *
 *   this.itemBalls = new ItemBalls(scene);
 *   this.itemBalls.set([{ key, x, y, hidden }]);
 *   this.itemBalls.at(x, y);          // la Ball sur cette case, ou undefined
 *   this.itemBalls.blocks(x, y);      // une Ball VISIBLE bloque-t-elle la case ?
 */
const TILE = 32;

export default class ItemBalls {
    constructor(scene) {
        this.scene = scene;
        this.list = [];
    }

    set(balls) {
        this.list.forEach((b) => b.sprite?.destroy());
        this.list = balls.map((b) => {
            const ball = { ...b };
            if (!b.hidden && this.scene.textures.exists('item_ball')) {
                const y = b.y * TILE + TILE;
                ball.sprite = this.scene.add.sprite(b.x * TILE + TILE / 2, y, 'item_ball', 0).setOrigin(0.5, 1).setDepth(y);
            }
            return ball;
        });
    }

    at(x, y) {
        return this.list.find((b) => b.x === x && b.y === y);
    }

    blocks(x, y) {
        const b = this.at(x, y);
        return Boolean(b && !b.hidden);
    }

    remove(key) {
        const b = this.list.find((ball) => ball.key === key);
        b?.sprite?.destroy();
        this.list = this.list.filter((ball) => ball.key !== key);
    }

    destroy() {
        this.list.forEach((b) => b.sprite?.destroy());
        this.list = [];
    }
}
