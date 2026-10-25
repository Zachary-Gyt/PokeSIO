import PixelText from '../ui/PixelText.js';
import Follower from './Follower.js';

const TILE = 32;
const ROWS = { down: 0, left: 1, right: 2, up: 3 };
const STEP_MS = { walk: 250, run: 140, surf: 180, bike: 100 };

/**
 * Les AUTRES joueurs présents sur la carte (multijoueur), avec leur nom au-dessus de la tête.
 * Ils ne bloquent pas le passage : leurs positions arrivent avec un petit retard, un joueur
 * "fantôme" ne doit jamais empêcher de marcher.
 *
 *   this.remotes = new RemotePlayers(scene);
 *   this.remotes.update({ id, name, avatar, x, y, facing, mode, instant, follower });
 *
 * Étape 14 : mode 'bike' (à Bicyclette).
 * Étape 11 : mode 'surf' (le joueur sur son Pokémon) et follower = { sprite, x, y, tucked } (son Pokémon qui suit).
 *   this.remotes.at(x, y)   // le joueur sur cette case (pour lui parler), ou undefined
 */
export default class RemotePlayers {
    constructor(scene) {
        this.scene = scene;
        this.players = new Map();
        this.createAnimations();
    }

    /** Animations de marche et de course pour les DEUX genres (le joueur local n'a créé que les siennes) */
    createAnimations() {
        const anims = this.scene.anims;
        // Étape 17 : tous les personnages jouables (red, leaf, brendan, may...)
        for (const gender of this.scene.registry.get('config').avatars ?? ['red', 'leaf']) {
            if (!this.scene.textures.exists(`${gender}_walk`)) continue;
            // Étape 14 : 'bike' (planche {personnage}_bike, ou celle de marche si elle manque)
            for (const mode of ['walk', 'run', 'bike']) {
                for (const [dir, row] of Object.entries(ROWS)) {
                    const key = `${gender}_${mode}_${dir}`;
                    if (anims.exists(key)) continue;
                    const first = row * 4;
                    anims.create({
                        key,
                        frames: anims.generateFrameNumbers(this.sheet(gender, mode), { frames: [first + 1, first + 2, first + 3, first] }),
                        frameRate: mode === 'walk' ? 8 : 12,
                        repeat: -1,
                    });
                }
            }
            // Surf (étape 11) : le joueur assis et le Pokémon dessous flottent en permanence
            for (const [dir, row] of Object.entries(ROWS)) {
                for (const sheet of [`${gender}_surf`, 'surf_base']) {
                    const key = `${sheet}_${dir}`;
                    if (anims.exists(key) || !this.scene.textures.exists(sheet)) continue;
                    anims.create({
                        key,
                        frames: anims.generateFrameNumbers(sheet, { frames: [row * 4, row * 4 + 1, row * 4 + 2, row * 4 + 3] }),
                        frameRate: 4,
                        repeat: -1,
                    });
                }
            }
        }
    }

    /** La planche d'un mode ; sans planche de vélo, celle de marche */
    sheet(gender, mode) {
        // Planche absente (course, vélo...) : celle de marche
        if (mode !== 'surf' && !this.scene.textures.exists(`${gender}_${mode}`)) return `${gender}_walk`;
        return `${gender}_${mode}`;
    }

    /** Pose immobile : à pied, à vélo, ou en Surf (qui flotte toujours) */
    idle(r) {
        if (r.mode === 'surf') {
            r.sprite.anims.play(`${r.gender}_surf_${r.facing}`, true);
            r.base?.anims.play(`surf_base_${r.facing}`, true);
            return;
        }
        r.sprite.anims.stop();
        r.sprite.setTexture(this.sheet(r.gender, r.mode === 'bike' ? 'bike' : 'walk'), ROWS[r.facing] * 4);
    }

    /** Le Pokémon sous le joueur en Surf : créé ou retiré selon le mode */
    updateSurf(r, mode) {
        r.mode = mode;
        if (mode === 'surf' && !r.base && this.scene.textures.exists('surf_base')) {
            r.base = this.scene.add.sprite(r.sprite.x, r.sprite.y + 14, 'surf_base', ROWS[r.facing] * 4).setOrigin(0.5, 1);
        }
        if (mode !== 'surf' && r.base) {
            r.base.destroy();
            r.base = null;
        }
    }

    /** Son Pokémon qui suit */
    updateFollower(r, data, instant, duration) {
        if (!data?.sprite) {
            r.follower?.setHidden(true);
            return;
        }
        r.follower ??= new Follower(this.scene);
        r.follower.setHidden(false);
        r.follower.setPokemon({ sprite: data.sprite, name: '' });
        if (instant || !r.follower.tile) r.follower.place(data.x, data.y, r.facing, Boolean(data.tucked));
        else r.follower.stepTo(data.x, data.y, duration);
    }

    update(p) {
        let r = this.players.get(p.id);
        // Étape 17 : "gender" contient maintenant le personnage (red, leaf, brendan, may)
        const gender = this.scene.textures.exists(`${p.avatar}_walk`) ? p.avatar : (p.gender === 'female' ? 'leaf' : 'red');
        const px = p.x * TILE + TILE / 2;
        const py = p.y * TILE + TILE;

        if (!r) {
            const sprite = this.scene.add.sprite(px, py, `${gender}_walk`, ROWS[p.facing ?? 'down'] * 4).setOrigin(0.5, 1);
            const tag = new PixelText(this.scene, px, py - 56, p.name, { font: 'tiny', color: '#f8f8f8', shadow: '#383838', align: 'center' });
            tag.setDepth(100000);
            r = { id: p.id, name: p.name, gender, sprite, tag, tile: { x: p.x, y: p.y }, facing: p.facing ?? 'down', mode: 'walk', map: p.map };
            this.players.set(p.id, r);
            sprite.setDepth(py);
            this.updateSurf(r, p.mode);
            this.updateFollower(r, p.follower, true, 0);
            this.place(r, px, py);
            this.idle(r);
            return;
        }

        r.map = p.map;   // étape 25 : la carte où il est (il peut être sur une carte collée)
        const distance = Math.abs(p.x - r.tile.x) + Math.abs(p.y - r.tile.y);
        r.facing = p.facing ?? r.facing;
        r.tile = { x: p.x, y: p.y };
        r.tween?.stop();
        const mode = ['run', 'surf', 'bike'].includes(p.mode) ? p.mode : 'walk';
        this.updateSurf(r, mode);
        this.updateFollower(r, p.follower, p.instant || distance !== 1, STEP_MS[mode]);

        // Étape 16 : saut par-dessus un rebord (2 cases en ligne droite)
        const jump = p.jump && distance === 2 && !p.instant;
        // Saut de plus d'une case (arrivée, retard du réseau) : on le place directement
        if (!jump && (p.instant || distance !== 1)) {
            this.place(r, px, py);
            this.idle(r);
            return;
        }

        r.sprite.anims.play(`${gender}_${mode}_${r.facing}`, true);
        if (mode === 'surf') r.base?.anims.play(`surf_base_${r.facing}`, true);
        r.tween?.stop();
        const sx = r.sprite.x;
        const sy = r.baseY ?? r.sprite.y;
        r.tween = this.scene.tweens.add({
            targets: { p: 0 },
            p: 1,
            duration: jump ? 380 : STEP_MS[mode],
            onUpdate: (tween) => {
                const t = tween.progress;
                const ground = Math.round(sy + (py - sy) * t);
                this.place(r, Math.round(sx + (px - sx) * t), ground);
                if (jump) r.sprite.y = ground - Math.round(Math.sin(t * Math.PI) * 20);
            },
            onComplete: () => {
                this.place(r, px, py);
                // Pas d'autre pas juste derrière : il s'arrête
                this.scene.time.delayedCall(60, () => {
                    if (r.sprite.active && !r.tween?.isPlaying()) this.idle(r);
                });
            },
        });
    }

    place(r, x, y) {
        r.baseY = y;   // y des pieds au sol (pendant un saut, le sprite est plus haut)
        r.sprite.setPosition(x, y).setDepth(y);
        r.tag.setPosition(x, y - 56);
        r.base?.setPosition(x, y + 14).setDepth(y - 1);
    }

    remove(id) {
        const r = this.players.get(id);
        if (!r) return;
        r.tween?.stop();
        r.sprite.destroy();
        r.tag.destroy();
        r.base?.destroy();
        r.follower?.destroy();
        this.players.delete(id);
    }

    at(x, y) {
        for (const r of this.players.values()) {
            if (r.tile.x === x && r.tile.y === y) return r;
        }
        return undefined;
    }
}
