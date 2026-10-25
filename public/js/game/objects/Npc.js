const TILE = 32;
const STEP_MS = 320; // un PNJ marche un peu moins vite que le joueur

const DIRECTIONS = {
    down:  { x: 0,  y: 1,  row: 0 },
    left:  { x: -1, y: 0,  row: 1 },
    right: { x: 1,  y: 0,  row: 2 },
    up:    { x: 0,  y: -1, row: 3 },
};
const DIRECTION_NAMES = Object.keys(DIRECTIONS);

/**
 * Un personnage non joueur placé dans Tiled (calque d'objets "npcs").
 *
 * Propriétés Tiled lues :
 *   sprite   (string) nom du fichier dans public/game/sprites/npc, sans ".png"
 *                     (obligatoire, sauf pour un dresseur qui a son sprite en base de données)
 *   text     (string) ce qu'il dit. Une ligne vide = nouvelle page
 *   name     (string) nom affiché au-dessus de la boîte de dialogue              [facultatif]
 *   facing   (string) up / down / left / right                                   [défaut : down]
 *   behavior (string) static (immobile), look (regarde autour), wander (se promène)  [défaut : static]
 *   radius   (int)    pour wander : distance max (en cases) autour de sa position de départ  [défaut : 2]
 *   trainer  (string) slug d'un dresseur de la table "trainers" -> ce PNJ est un DRESSEUR
 *   action   (string) heal (infirmière), pc, shop (vendeur), title_board (tableau des tenants)
 *   shop     (string) pour action = shop : la boutique de config/shops.php            [défaut : default]
 *   requires_badge  (int) ce PNJ barre le passage tant que le joueur n'a pas ce badge (1 à 8)
 *   requires_badges (int) ... tant que le joueur n'a pas au moins ce nombre de badges
 * Étape 24 :
 *   event    (string) l'événement du scénario joué quand on lui parle (resources/scenario/<carte>.txt)
 *   show_if / hide_if (string) visible seulement si / caché si la condition est vraie (ex. potions_recues)
 *   sprite   peut aussi être une planche de dresseur (sprites/trainers/overworld : professor, lass...)
 *   pokemon  (string) un POKÉMON sur la carte (Ronflex qui dort...) : nom de sa planche dans
 *                     sprites/pokemon/overworld, sans ".png" (ex. SNORLAX)
 */
export default class Npc {
    constructor(scene, data) {
        this.scene = scene;

        this.tile = { x: data.x, y: data.y };   // case actuelle
        this.home = { x: data.x, y: data.y };   // case de départ (centre de sa zone de promenade)
        this.from = null;                       // case qu'il quitte pendant un pas (encore occupée)
        this.isMoving = false;

        this.facing = DIRECTIONS[data.facing] ? data.facing : 'down';
        this.text = data.text ?? '';
        this.name = data.name ?? '';
        this.behavior = data.behavior ?? 'static';
        this.radius = Number(data.radius ?? 2);

        // Données du dresseur (venues de Laravel), ou null pour un PNJ ordinaire
        this.trainer = data.trainerData ?? null;
        if (this.trainer) {
            this.name = `${this.trainer.class} ${this.trainer.name}`; // ex. "Gamin Léo"
        }

        // Sprite : celui du dresseur (base de données) s'il en a un, sinon la propriété "sprite" de Tiled.
        // Tenant du titre joué par un joueur : la planche de marche du joueur (male_walk / female_walk)
        const trainerSprite = this.trainer?.overworld;
        if (this.trainer?.playerSprite && scene.textures.exists(`${this.trainer.playerSprite}_walk`)) {
            this.textureKey = `${this.trainer.playerSprite}_walk`;
        } else if (trainerSprite && scene.textures.exists(`trainer_${trainerSprite}`)) {
            this.textureKey = `trainer_${trainerSprite}`;
        } else if (data.pokemon && scene.textures.exists(`pkmn_${data.pokemon}`)) {
            this.textureKey = `pkmn_${data.pokemon}`;   // étape 24 : un Pokémon sur la carte
        } else if (!scene.textures.exists(`npc_${data.sprite}`) && scene.textures.exists(`trainer_${data.sprite}`)) {
            this.textureKey = `trainer_${data.sprite}`; // étape 24 : une planche de dresseur pour un PNJ ordinaire
        } else {
            if (trainerSprite) {
                console.warn(`Dresseur ${this.name} : sprite "${trainerSprite}" introuvable dans public/game/sprites/trainers/overworld/`);
            }
            this.textureKey = `npc_${data.sprite}`;
            if (!scene.textures.exists(this.textureKey)) {
                console.warn(`PNJ en (${data.x}, ${data.y}) : sprite "${data.sprite}" introuvable dans public/game/sprites/npc/`);
            }
        }

        this.sprite = scene.add.sprite(0, 0, this.textureKey, this.idleFrame());
        this.sprite.setOrigin(0.5, 1); // ancré aux pieds, comme le joueur
        this.sprite.setPosition(this.tile.x * TILE + TILE / 2, this.tile.y * TILE + TILE);
        this.updateDepth();

        // Prochaine "action" (tourner la tête, faire un pas) : dans 1 à 3 secondes
        this.nextActionAt = scene.time.now + Phaser.Math.Between(1000, 3000);
    }

    /** Dresseur qui n'a pas encore été battu : il peut lancer un défi (le tenant du titre attend qu'on lui parle) */
    canChallenge() {
        return this.trainer !== null && !this.trainer.defeated && !this.trainer.isTitle && !this.hidden;
    }

    /** Étape 24 : caché par le scénario (show_if / hide_if, ou "cacher" dans un script) : invisible, ne bloque plus */
    setHidden(hidden) {
        this.hidden = hidden;
        this.sprite.setVisible(!hidden);
    }

    /** Le Tenant du titre a changé : nouveau nom, et apparence du joueur qui l'a pris */
    setTitleHolder(info) {
        this.trainer.name = info.name;
        this.trainer.playerSprite = info.playerSprite;
        this.name = `${this.trainer.class} ${info.name}`;
        const key = info.playerSprite ? `${info.playerSprite}_walk` : `trainer_${this.trainer.overworld}`;
        if (this.scene.textures.exists(key)) {
            this.textureKey = key;
            this.sprite.setTexture(key, this.idleFrame());
        }
    }

    /** Disparaît (gardien qui laisse passer) */
    remove() {
        this.sprite.destroy();
    }

    /** Le PNJ occupe-t-il cette case ? (sa case, et pendant un pas, aussi celle qu'il quitte) */
    occupies(x, y) {
        if (this.hidden) return false;
        return (this.tile.x === x && this.tile.y === y)
            || (this.from !== null && this.from.x === x && this.from.y === y);
    }

    /** Appelé à chaque image par la scène */
    update(time) {
        if (this.hidden || this.isMoving || time < this.nextActionAt) return;

        this.nextActionAt = time + Phaser.Math.Between(1500, 3500);
        const dir = Phaser.Utils.Array.GetRandom(DIRECTION_NAMES);

        if (this.behavior === 'look') {
            this.face(dir);
        } else if (this.behavior === 'wander') {
            this.tryStep(dir);
        }
    }

    /** Promenade : un pas au hasard, s'il est autorisé */
    tryStep(dir) {
        this.face(dir);

        const d = DIRECTIONS[dir];
        const target = { x: this.tile.x + d.x, y: this.tile.y + d.y };

        // Reste dans sa zone de promenade
        if (Math.abs(target.x - this.home.x) > this.radius || Math.abs(target.y - this.home.y) > this.radius) {
            return;
        }

        // La scène vérifie : pas de mur, pas de sortie, pas le joueur, pas un autre PNJ
        if (!this.scene.canNpcEnter(target.x, target.y, this)) {
            return;
        }

        this.stepTo(dir, () => this.stopWalking());
    }

    /**
     * Marche tout droit sur plusieurs cases, puis appelle onComplete.
     * Utilisé quand un dresseur repère le joueur et vient vers lui.
     */
    walk(dir, steps, onComplete) {
        if (steps <= 0) {
            this.stopWalking();
            onComplete?.();
            return;
        }

        // Un pas, puis on recommence avec un pas de moins
        this.stepTo(dir, () => this.walk(dir, steps - 1, onComplete));
    }

    /** Fait UN pas dans une direction (sans vérification : c'est à l'appelant de s'en assurer) */
    stepTo(dir, onArrive) {
        this.facing = dir;
        const d = DIRECTIONS[dir];
        const target = { x: this.tile.x + d.x, y: this.tile.y + d.y };

        this.from = { ...this.tile };
        this.tile = target;   // la case d'arrivée est réservée DÈS le début du pas
        this.isMoving = true;
        this.sprite.anims.play(`${this.textureKey}_${dir}`, true);

        this.scene.tweens.add({
            targets: this.sprite,
            x: target.x * TILE + TILE / 2,
            y: target.y * TILE + TILE,
            duration: STEP_MS,
            onUpdate: () => {
                this.sprite.x = Math.round(this.sprite.x);
                this.sprite.y = Math.round(this.sprite.y);
                this.updateDepth();
            },
            onComplete: () => {
                this.from = null;
                this.isMoving = false;
                onArrive();
            },
        });
    }

    stopWalking() {
        this.sprite.anims.stop();
        this.sprite.setFrame(this.idleFrame());
    }

    /** Se tourne vers une case (ex. : vers le joueur qui lui parle) */
    faceTowards(tile) {
        const dx = tile.x - this.tile.x;
        const dy = tile.y - this.tile.y;

        if (Math.abs(dx) > Math.abs(dy)) {
            this.face(dx > 0 ? 'right' : 'left');
        } else {
            this.face(dy > 0 ? 'down' : 'up');
        }
    }

    face(dir) {
        this.facing = dir;
        if (!this.isMoving) {
            this.sprite.setFrame(this.idleFrame());
        }
    }

    idleFrame() {
        return DIRECTIONS[this.facing].row * 4;
    }

    /** Plus un personnage est bas sur l'écran, plus il est dessiné devant (profondeur = y des pieds) */
    updateDepth() {
        this.sprite.setDepth(this.sprite.y);
    }
}
