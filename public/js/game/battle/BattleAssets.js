/**
 * Images du combat.
 *
 * Les images FIXES (interface, menus, balls) sont chargées une fois au démarrage du jeu (BootScene).
 * Les images qui dépendent du combat (fond, Pokémon, dresseur) sont chargées au début du combat,
 * ou en plein combat quand un dresseur envoie un nouveau Pokémon.
 */

// Balls de la 1re génération (fichiers Graphics/Battle animations/ball_XXX.png d'Essentials)
export const BALLS = ['POKEBALL', 'GREATBALL', 'ULTRABALL', 'MASTERBALL', 'SAFARIBALL'];

/** "poke-ball" -> "POKEBALL" (nom du fichier Essentials) */
export function ballKey(slug) {
    const key = String(slug ?? 'poke-ball').replace(/-/g, '').toUpperCase();
    return BALLS.includes(key) ? key : 'POKEBALL';
}

/** À appeler dans BootScene.preload() */
export function preloadBattleAssets(scene, basePath, gender) {
    const ui = `${basePath}/battle/ui`;
    const img = (key, file) => scene.load.image(key, file);

    img('battle_databox', `${ui}/databox_normal.png`);
    img('battle_databox_foe', `${ui}/databox_normal_foe.png`);
    img('battle_databox_safari', `${ui}/databox_safari.png`);   // étape 15 : Parc Safari
    img('battle_lv', `${ui}/overlay_lv.png`);
    img('battle_exp', `${ui}/overlay_exp.png`);
    img('battle_own', `${ui}/icon_own.png`);
    img('battle_shiny', `${ui}/shiny.png`);
    img('battle_overlay_message', `${ui}/overlay_message.png`);
    img('battle_overlay_command', `${ui}/overlay_command.png`);
    img('battle_overlay_fight', `${ui}/overlay_fight.png`);
    img('battle_cursor_command', `${ui}/cursor_command.png`);
    img('battle_cursor_fight', `${ui}/cursor_fight.png`);
    img('battle_types', `${ui}/types.png`);
    img('battle_lineup', `${ui}/overlay_lineup.png`);
    for (const name of ['icon_ball', 'icon_ball_empty', 'icon_ball_faint', 'icon_ball_status']) {
        img(`battle_${name}`, `${ui}/${name}.png`);
    }
    scene.load.spritesheet('battle_hp', `${ui}/overlay_hp.png`, { frameWidth: 96, frameHeight: 6 });
    scene.load.spritesheet('battle_statuses', `${ui}/icon_statuses.png`, { frameWidth: 44, frameHeight: 16 });
    scene.load.spritesheet('battle_numbers', `${ui}/icon_numbers.png`, { frameWidth: 16, frameHeight: 14 });
    scene.load.spritesheet('battle_pause_arrow', `${basePath}/ui/pause_arrow.png`, { frameWidth: 16, frameHeight: 16 });

    // Balls : 8 images de 32 x 64 (rotation), et la Ball ouverte
    for (const ball of BALLS) {
        scene.load.spritesheet(`ball_${ball}`, `${basePath}/battle/balls/ball_${ball}.png`, { frameWidth: 32, frameHeight: 64 });
        img(`ball_${ball}_open`, `${basePath}/battle/balls/ball_${ball}_open.png`);
    }

    // Menus (fenêtre "choice" d'Essentials + flèche de sélection) et écran d'équipe
    img('ui_choice', `${basePath}/ui/choice.png`);
    img('ui_sel_arrow', `${basePath}/ui/sel_arrow.png`);
    const party = `${basePath}/ui/party`;
    for (const name of ['bg', 'icon_ball', 'icon_ball_sel', 'icon_cancel', 'icon_cancel_sel', 'overlay_hp_back',
        'overlay_hp_back_faint', 'overlay_lv', 'panel_round', 'panel_round_sel', 'panel_round_faint', 'panel_round_faint_sel',
        'panel_rect', 'panel_rect_sel', 'panel_rect_faint', 'panel_rect_faint_sel', 'panel_blank',
        'panel_round_swap', 'panel_round_swap_sel', 'panel_rect_swap', 'panel_rect_swap_sel', 'icon_item']) {
        img(`party_${name}`, `${party}/${name}.png`);
    }
    scene.load.spritesheet('party_hp', `${party}/overlay_hp.png`, { frameWidth: 96, frameHeight: 8 });
    scene.load.spritesheet('party_statuses', `${basePath}/battle/ui/statuses.png`, { frameWidth: 44, frameHeight: 16 });

    // Sprite de dos du joueur (5 images côte à côte : immobile, puis le lancer de Ball)
    img(`player_back_${gender}`, `${basePath}/sprites/player/${gender}_back.png`);
}

/** À appeler dans BootScene.create() : découpe des planches en images nommées */
export function registerBattleFrames(scene, gender) {
    const sheet = (key, columns, rows, w, h, name) => {
        const texture = scene.textures.get(key);
        for (let r = 0; r < rows; r++) {
            for (let c = 0; c < columns; c++) {
                texture.add(name(r, c), 0, c * w, r * h, w, h);
            }
        }
    };
    // Boutons du menu : 2 colonnes (normal / sélectionné) x 10 lignes de 46 px
    sheet('battle_cursor_command', 2, 10, 130, 46, (r, c) => `c${r}_${c}`);
    // Boutons d'attaque : 2 colonnes x 19 types
    sheet('battle_cursor_fight', 2, 19, 192, 46, (r, c) => `f${r}_${c}`);
    // Étiquettes de type : 19 lignes de 28 px
    sheet('battle_types', 1, 19, 64, 28, (r) => `t${r}`);

    // Dos du joueur : largeur / 5
    const back = scene.textures.get(`player_back_${gender}`);
    const image = back.getSourceImage();
    const w = Math.floor(image.width / 5);
    for (let i = 0; i < 5; i++) back.add(i, 0, i * w, 0, w, image.height);
}

/**
 * Charge des images pendant une scène. images : { clé: url }.
 * Les clés déjà chargées sont ignorées. Renvoie une promesse.
 */
export function loadImages(scene, images) {
    const missing = Object.entries(images).filter(([key, url]) => url && !scene.textures.exists(key));
    if (missing.length === 0) return Promise.resolve();

    return new Promise((resolve) => {
        for (const [key, url] of missing) scene.load.image(key, url);
        scene.load.once('complete', () => resolve());
        scene.load.start();
    });
}

/**
 * Hauteur des pixels vides SOUS le Pokémon dans son image (les sprites de face d'Essentials
 * ont de la marge en bas) : sert à poser ses pieds sur le socle.
 */
export function bottomPadding(scene, key) {
    const texture = scene.textures.get(key);
    if (texture.__bottomPadding !== undefined) return texture.__bottomPadding;

    const image = texture.getSourceImage();
    const canvas = document.createElement('canvas');
    canvas.width = image.width;
    canvas.height = image.height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(image, 0, 0);
    const data = ctx.getImageData(0, 0, image.width, image.height).data;

    let padding = 0;
    outer: for (let y = image.height - 1; y >= 0; y--) {
        for (let x = 0; x < image.width; x++) {
            if (data[(y * image.width + x) * 4 + 3] > 0) break outer;
        }
        padding++;
    }
    texture.__bottomPadding = padding;
    return padding;
}
