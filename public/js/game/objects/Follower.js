import { loadImages } from '../battle/BattleAssets.js';

const TILE = 32;
const ROWS = { down: 0, left: 1, right: 2, up: 3 };

/**
 * LE POKÉMON QUI SUIT LE JOUEUR (comme dans Pokémon Jaune, HeartGold / SoulSilver ou le plugin
 * "Following Pokémon" d'Essentials).
 *
 * Il utilise la planche "overworld" du Pokémon (sprites/pokemon/overworld/PIKACHU.png) :
 * 4 x 4 cases de 64 x 64, une ligne par direction (bas, gauche, droite, haut), comme les personnages.
 * Il piétine en permanence, comme dans Essentials.
 *
 * Il marche toujours sur la case que le joueur vient de quitter : quand le joueur fait un pas
 * de A vers B, le Pokémon va sur A, à la même vitesse.
 *
 *   const follower = new Follower(scene);
 *   await follower.setPokemon({ id, name, sprite });   // null = pas de Pokémon (équipe K.O.)
 *   follower.place(x, y, 'down');
 *   follower.stepTo(x, y, 250);
 */
export default class Follower {
    constructor(scene) {
        this.scene = scene;
        this.sprite = null;
        this.pokemon = null;
        this.tile = null;
        this.facing = 'down';
        this.hidden = false;       // caché pendant le Surf
        this.tucked = false;       // sur la même case que le joueur (à l'arrivée sur une carte)
    }

    /** Change de Pokémon (null : plus de Pokémon qui suit). Charge sa planche si besoin. */
    async setPokemon(pokemon) {
        if (pokemon?.sprite === this.pokemon?.sprite) {
            this.pokemon = pokemon;
            return;
        }
        this.pokemon = pokemon;
        if (!pokemon?.sprite) {
            this.sprite?.destroy();
            this.sprite = null;
            return;
        }

        const key = `follower_${pokemon.sprite}`;
        await loadImages(this.scene, { [key]: pokemon.sprite });
        if (this.destroyed) return; // carte quittée pendant le chargement
        // Un autre Pokémon a été demandé pendant le chargement : c'est lui qui s'affichera.
        // (On compare la PLANCHE et non l'objet : le Pokémon d'un autre joueur est renvoyé à chaque pas,
        // dans un nouvel objet. Comparer les objets l'empêchait d'apparaître chez les autres joueurs.)
        if (this.pokemon?.sprite !== pokemon.sprite || !this.scene.textures.exists(key)) return;
        Follower.slice(this.scene, key);

        const x = this.sprite?.x ?? 0;
        const y = this.sprite?.y ?? 0;
        this.sprite?.destroy();
        this.sprite = this.scene.add.sprite(x, y, key, ROWS[this.facing] * 4).setOrigin(0.5, 1);
        if (this.tile) this.place(this.tile.x, this.tile.y, this.facing, this.tucked);
        this.refresh();
    }

    /** Découpe la planche en 16 cases et crée les 4 animations (une seule fois par Pokémon) */
    static slice(scene, key) {
        const texture = scene.textures.get(key);
        if (texture.has(15)) return;
        const image = texture.getSourceImage();
        const w = Math.floor(image.width / 4);
        const h = Math.floor(image.height / 4);
        for (let i = 0; i < 16; i++) texture.add(i, 0, (i % 4) * w, Math.floor(i / 4) * h, w, h);
        for (const [dir, row] of Object.entries(ROWS)) {
            if (scene.anims.exists(`${key}_${dir}`)) continue;
            scene.anims.create({
                key: `${key}_${dir}`,
                frames: scene.anims.generateFrameNumbers(key, { frames: [row * 4, row * 4 + 1, row * 4 + 2, row * 4 + 3] }),
                frameRate: 6,
                repeat: -1,
            });
        }
    }

    get active() {
        return Boolean(this.sprite) && !this.hidden;
    }

    /** Pose le Pokémon sur une case. tucked = sur la case du joueur, caché derrière lui jusqu'à son premier pas. */
    place(x, y, facing = this.facing, tucked = false) {
        this.tile = { x, y };
        this.facing = facing;
        this.tucked = tucked;
        this.tween?.stop();
        if (!this.sprite) return;
        this.sprite.setPosition(x * TILE + TILE / 2, y * TILE + TILE);
        this.refresh();
    }

    /** Un pas vers (x, y), en durationMs (la même durée que le pas du joueur) */
    stepTo(x, y, durationMs) {
        if (!this.tile) return this.place(x, y);
        const dx = x - this.tile.x;
        const dy = y - this.tile.y;
        if (dx === 0 && dy === 0) {
            // Il était "caché" sur la case du joueur : il apparaît quand le joueur s'en va
            if (this.tucked) {
                this.tucked = false;
                this.refresh();
            }
            return;
        }
        // Étape 16 : 2 cases en ligne droite = il saute par-dessus un rebord, derrière le joueur
        const jump = (dx === 0 || dy === 0) && Math.abs(dx) + Math.abs(dy) === 2;
        // Plus d'une case d'écart (téléportation...) : il apparaît directement
        if ((Math.abs(dx) + Math.abs(dy) !== 1 && !jump) || !this.sprite) return this.place(x, y, this.facing);

        this.facing = dx > 0 ? 'right' : dx < 0 ? 'left' : dy > 0 ? 'down' : 'up';
        this.tile = { x, y };
        this.tucked = false;
        this.refresh();
        this.tween?.stop();
        const sx = this.sprite.x;
        const sy = this.sprite.y;
        const tx = x * TILE + TILE / 2;
        const ty = y * TILE + TILE;
        this.tween = this.scene.tweens.add({
            targets: { p: 0 },
            p: 1,
            duration: jump ? Math.max(durationMs, 380) : durationMs,
            onUpdate: (tween) => {
                if (!this.sprite) return;
                const p = tween.progress;
                const groundY = Math.round(sy + (ty - sy) * p);
                this.sprite.x = Math.round(sx + (tx - sx) * p);
                this.sprite.y = groundY - (jump ? Math.round(Math.sin(p * Math.PI) * 16) : 0);
                this.sprite.setDepth(groundY - 1);
            },
            onComplete: () => this.sprite?.setPosition(tx, ty).setDepth(ty - 1),
        });
    }

    /** Se tourne vers une case (quand le joueur lui parle) */
    faceTowards(tile) {
        if (!this.tile) return;
        const dx = tile.x - this.tile.x;
        const dy = tile.y - this.tile.y;
        this.facing = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up');
        this.refresh();
    }

    setHidden(hidden) {
        this.hidden = hidden;
        this.refresh();
    }

    /** Affichage : animation de la bonne direction, profondeur juste derrière le joueur s'ils se chevauchent */
    refresh() {
        if (!this.sprite) return;
        this.sprite.setVisible(!this.hidden && !this.tucked);
        this.sprite.setDepth(this.sprite.y - 1);
        const anim = `${this.sprite.texture.key}_${this.facing}`;
        if (this.sprite.anims.currentAnim?.key !== anim) this.sprite.anims.play(anim, true);
    }

    occupies(x, y) {
        return this.active && !this.tucked && this.tile?.x === x && this.tile?.y === y;
    }

    destroy() {
        this.destroyed = true;
        this.tween?.stop();
        this.sprite?.destroy();
        this.sprite = null;
    }
}
