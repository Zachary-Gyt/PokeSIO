/**
 * Images des écrans du menu (Résumé, Sac, PC, évolution), copiées depuis Essentials par
 * `php artisan game:essentials-assets`. Chargées une fois au démarrage (BootScene).
 */
export function preloadMenuAssets(scene, basePath, gender) {
    const ui = `${basePath}/ui`;
    const img = (key, file) => scene.load.image(key, file);
    const sheet = (key, file, w, h) => scene.load.spritesheet(key, file, { frameWidth: w, frameHeight: h });

    img('ui_dialog', `${ui}/dialog.png`);

    // Résumé
    for (const page of [1, 2, 3, 4, 'movedetail', 'learnmove']) {
        img(`summary_bg_${page}`, `${ui}/summary/bg_${page}.png`);
    }
    sheet('summary_cursor_move', `${ui}/summary/cursor_move.png`, 252, 74);
    sheet('summary_hp', `${ui}/summary/overlay_hp.png`, 96, 6);
    img('summary_exp', `${ui}/summary/overlay_exp.png`);
    for (const ball of ['POKEBALL', 'GREATBALL', 'ULTRABALL', 'MASTERBALL', 'SAFARIBALL']) {
        img(`summary_ball_${ball}`, `${ui}/summary/icon_ball_${ball}.png`);
    }
    sheet('ui_category', `${basePath}/battle/ui/category.png`, 64, 28);

    // Sac : un fond et une image de sac par poche
    for (let p = 1; p <= 8; p++) {
        img(`bag_bg_${p}`, `${ui}/bag/bg_${p}.png`);
        img(`bag_sprite_${p}`, `${ui}/bag/bag_${p}${gender === 'female' ? '_f' : ''}.png`);
    }
    img('bag_cursor', `${ui}/bag/cursor.png`);
    img('bag_icon_pocket', `${ui}/bag/icon_pocket.png`);
    img('bag_icon_slider', `${ui}/bag/icon_slider.png`);
    sheet('ui_left_arrow', `${ui}/left_arrow.png`, 40, 28);
    sheet('ui_right_arrow', `${ui}/right_arrow.png`, 40, 28);
    img('item_none', `${basePath}/items/000.png`);

    // PC
    img('storage_bg', `${ui}/storage/bg.png`);
    img('storage_overlay_main', `${ui}/storage/overlay_main.png`);
    img('storage_overlay_party', `${ui}/storage/overlay_party.png`);
    img('storage_lv', `${ui}/storage/overlay_lv.png`);
    for (let b = 0; b < 8; b++) img(`storage_box_${b}`, `${ui}/storage/box_${b}.png`);
    for (const c of ['point_1', 'point_2', 'grab', 'fist']) img(`storage_cursor_${c}`, `${ui}/storage/cursor_${c}.png`);

    // Évolution
    img('evolution_bg', `${ui}/evolution_bg.png`);

    // Étape 20 : le Casino. Machine à sous d'Essentials (Graphics/UI/Slot Machine)
    const slot = `${ui}/slot_machine`;
    img('slot_bg', `${slot}/bg.png`);
    img('slot_images', `${slot}/images.png`);
    img('slot_overlay', `${slot}/ReelOverlay.png`);
    img('slot_button', `${slot}/button.png`);
    for (const line of ['line1', 'line2', 'line3a', 'line3b']) img(`slot_${line}`, `${slot}/${line}.png`);
    sheet('slot_numbers', `${slot}/numbers.png`, 14, 22);
    sheet('slot_lights', `${slot}/lights.png`, 96, 26);
    for (const name of ['insert', 'press', 'lose', 'stop', 'win']) sheet(`slot_${name}`, `${slot}/${name}.png`, 152, 208);
    sheet('slot_bonus', `${slot}/bonus.png`, 152, 104);
    // Roulette (script de FL)
    const roul = `${ui}/roulette`;
    for (const name of ['roulette', 'table', 'ball', 'ballicon', 'ballusedicon', 'creditbox', 'multiplierbox', 'selectedcolor', 'selectedsingle', 'selectedspecies']) {
        img(`roulette_${name}`, `${roul}/${name}.png`);
    }

    // Étape 19 : écran de surnom
    img('naming_bg', `${ui}/naming/bg.png`);

    // Étape 18 : les Œufs (Résumé d'un Œuf, éclosion)
    img('summary_bg_egg', `${ui}/summary/bg_egg.png`);
    img('hatch_bg', `${ui}/hatch_bg.png`);
    img('egg_sprite', `${basePath}/sprites/pokemon/eggs/000.png`);
    sheet('egg_cracks', `${basePath}/sprites/pokemon/eggs/000_cracks.png`, 160, 160);
    img('trade_bg', `${ui}/trade_bg.png`);   // échange entre joueurs (étape 9)

    // Carte Dresseur et Boutique
    img('card_bg', `${ui}/trainer_card/bg.png`);
    img('card_bg_f', `${ui}/trainer_card/bg_f.png`);
    img('card', `${ui}/trainer_card/card.png`);
    img('card_f', `${ui}/trainer_card/card_f.png`);
    img('card_badges', `${ui}/trainer_card/icon_badges.png`);
    img('mart_bg', `${ui}/mart/bg.png`);
    img('mart_cursor', `${ui}/mart/cursor.png`);

    // Étape 13 : le Pokédex
    for (const name of ['bg_menu', 'bg_list', 'bg_listsearch', 'bg_info', 'bg_area', 'cursor_list', 'icon_own', 'icon_seen', 'icon_slider']) {
        img(`dex_${name}`, `${ui}/pokedex/${name}.png`);
    }
}

/** Icône d'objet : public/game/items/SUPERPOTION.png (clé de texture "item_SUPERPOTION") */
export function itemIconKey(icon) {
    return `item_${icon}`;
}

export function itemIconUrl(basePath, icon) {
    return `${basePath}/items/${icon}.png`;
}

/** "poke-ball" -> "POKEBALL" */
export function ballName(slug) {
    return String(slug ?? 'poke-ball').replace(/-/g, '').toUpperCase();
}
