
// Étape 17 : les planches de chaque personnage (sprites/player/{personnage}_{planche}.png)
const PLAYER_SHEETS = ['walk', 'run', 'surf', 'bike', 'fish'];

// Ordre des lignes dans toutes les planches de personnages (format Essentials : 4 x 4 frames)
const ROWS = { down: 0, left: 1, right: 2, up: 3 };

export default class BootScene extends Phaser.Scene {
    constructor() {
        super('Boot'); // le nom de la scène
    }

    preload() {
        const config = this.registry.get('config');
        const v = `?v=${config.version}`;

        // Étape 21 : l'écran de chargement suit le chargement des images, cartes et sons
        this.load.on('progress', (value) => {
            const done = this.load.totalComplete;
            const total = this.load.totalToLoad;
            window.gameLoading?.set(0.08 + value * 0.5, `Chargement des images... ${done} / ${total}`);
        });
        this.load.on('complete', () => window.gameLoading?.set(0.6, 'Chargement de la carte...'));

        // Étape 21 : les cartes, leurs tilesets et les planches des PNJ / dresseurs ne sont plus chargés ici,
        // mais par WorldScene.preload(), carte par carte (seulement ce qui sert). L'interface des combats
        // et des menus est chargée en arrière-plan par AssetsScene, une fois la carte affichée.

        // Étape 17 : les planches de TOUS les personnages jouables (les autres joueurs peuvent être n'importe lequel).
        // Chargées comme de simples images, puis découpées en 4 x 4 dans create() : leur taille varie
        // (Red : marche 128 x 192 ; Brendan : 192 x 192 ; pêche : 384 x 320...).
        for (const avatar of config.avatars ?? ['red', 'leaf']) {
            for (const mode of PLAYER_SHEETS) {
                this.load.image(`${avatar}_${mode}`, `${config.basePath}/sprites/player/${avatar}_${mode}.png${v}`);
            }
        }
        // Étape 11 : le "Pokémon-bateau" sous le joueur en Surf
        this.load.spritesheet('surf_base', `${config.basePath}/sprites/player/surf_base.png${v}`, { frameWidth: 64, frameHeight: 64 });

        // Étape 16 : Poké Ball par terre (32 x 32) et Balls de la machine de soin (64 x 48), planches d'Essentials
        this.load.spritesheet('item_ball', `${config.basePath}/sprites/field/item_ball.png${v}`, { frameWidth: 32, frameHeight: 32 });
        this.load.spritesheet('heal_balls_1', `${config.basePath}/sprites/field/heal_balls_1.png${v}`, { frameWidth: 64, frameHeight: 48 });
        this.load.spritesheet('heal_balls_2', `${config.basePath}/sprites/field/heal_balls_2.png${v}`, { frameWidth: 64, frameHeight: 48 });

        // Étape 11 : obstacles des capacités de terrain (cases de 32 x 32), bandeau des CS, carte de Vol
        for (const name of ['tree', 'rock', 'boulder']) {
            this.load.spritesheet(`field_${name}`, `${config.basePath}/sprites/field/${name}.png${v}`, { frameWidth: 32, frameHeight: 32 });
        }
        this.load.image('field_move_bg', `${config.basePath}/ui/field_move_bg.png${v}`);
        // Bulle "!" d'Essentials : planche de cases de 192 x 192 (0 = petit "!", 1 = écrasé, 2 = complet)
        this.load.spritesheet('exclamation', `${config.basePath}/ui/exclamation.png${v}`, {
            frameWidth: 192,
            frameHeight: 192,
        });

        // La fenêtre des menus (utilisée aussi sur la carte : compteur du Safari...)
        this.load.image('ui_choice', `${config.basePath}/ui/choice.png${v}`);
        this.load.image('ui_sel_arrow', `${config.basePath}/ui/sel_arrow.png${v}`);
    }

    create() {
        const config = this.registry.get('config');

        // Étape 17 : planches des personnages, découpées en 4 x 4 (une absente est simplement ignorée)
        for (const avatar of config.avatars ?? ['red', 'leaf']) {
            for (const mode of PLAYER_SHEETS) this.slicePlayerSheet(`${avatar}_${mode}`);
        }

        // Animation du "!" : il "pousse" en 3 images (petit, écrasé, complet) puis reste affiché
        this.anims.create({
            key: 'exclamation_pop',
            frames: this.anims.generateFrameNumbers('exclamation', { frames: [0, 1, 2] }),
            frameRate: 14,
        });

        // Étape 21 : l'interface des combats et des menus arrive en arrière-plan (AssetsScene)
        this.registry.set('assetsPromise', new Promise((resolve) => this.registry.set('assetsResolve', resolve)));

        const c = config.character;
        // On passe à la scène du monde en lui transmettant la position de départ
        this.scene.start('World', { map: c.map, x: c.x, y: c.y, facing: c.facing });
    }

    slicePlayerSheet(key) {
        slicePlayerSheet(this, key);
    }
}

/** Étape 17 : découpe une planche du joueur en 16 images numérotées 0 à 15 (4 colonnes x 4 lignes) */
export function slicePlayerSheet(scene, key) {
    if (!scene.textures.exists(key)) return;
    const texture = scene.textures.get(key);
    if (texture.has(15)) return;
    const image = texture.getSourceImage();
    const w = Math.floor(image.width / 4);
    const h = Math.floor(image.height / 4);
    for (let i = 0; i < 16; i++) texture.add(i, 0, (i % 4) * w, Math.floor(i / 4) * h, w, h);
}

/**
 * Découpe une planche de personnage (PNJ, dresseur) en 16 frames (grille 4 x 4), QUELLE QUE SOIT sa taille,
 * et crée ses 4 animations de marche ("npc_xxx_down"...). Sans effet si c'est déjà fait (étape 21 :
 * les planches arrivent carte par carte).
 */
export function sliceCharacterSheet(scene, key) {
    if (!scene.textures.exists(key)) return;
    const texture = scene.textures.get(key);
    if (!texture.has(15)) {
        const image = texture.getSourceImage();
        const frameWidth = Math.floor(image.width / 4);
        const frameHeight = Math.floor(image.height / 4);
        for (let i = 0; i < 16; i++) {
            texture.add(i, 0, (i % 4) * frameWidth, Math.floor(i / 4) * frameHeight, frameWidth, frameHeight);
        }
    }
    for (const [dir, row] of Object.entries(ROWS)) {
        if (scene.anims.exists(`${key}_${dir}`)) continue;
        const first = row * 4;
        scene.anims.create({
            key: `${key}_${dir}`,
            frames: scene.anims.generateFrameNumbers(key, { frames: [first + 1, first + 2, first + 3, first] }),
            frameRate: 6,
            repeat: -1,
        });
    }
}
