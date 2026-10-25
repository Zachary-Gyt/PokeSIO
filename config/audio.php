<?php

/*
|--------------------------------------------------------------------------
| Musique et sons (étape 12)
|--------------------------------------------------------------------------
| Les fichiers viennent du dossier Audio d'Essentials, copiés par :
|   php artisan game:essentials-audio "C:\...\Pokemon Essentials v21.1 2023-07-30"
| vers public/game/audio/bgm (musiques), me (jingles) et se (effets).
|
| Chaque moment du jeu a une LISTE de noms de fichiers possibles (sans extension, majuscules
| sans importance) : le premier qui existe est utilisé, cherché d'abord dans son dossier puis
| dans les deux autres. Aucun ne correspond : ce moment reste silencieux, sans erreur.
| Pour changer un son, mets simplement le nom de ton fichier en premier.
|
| ⚠ Dans Essentials v21.1, la plupart des musiques de carte (Route 1, Poke Center, Gym, Cave...)
| sont des .mid, que les navigateurs ne savent pas lire : convertis-les en .ogg (voir le LISEZMOI).
| Les noms ci-dessous les attendent déjà : une fois converties, elles seront utilisées.
|
| La musique d'une carte se choisit dans Tiled : propriété de carte "bgm" = nom du fichier
| (ex. "Route 1"). Sans cette propriété : 'map_default' ci-dessous.
*/

return [

    // Musiques (dossier BGM) : elles tournent en boucle
    'bgm' => [
        'map_default' => ['Route 1', 'Lappet Town'],                        // .mid dans Essentials : à convertir
        'battle_wild' => ['Battle wild'],
        'battle_trainer' => ['Battle trainer'],
        'battle_leader' => ['Battle Gym Leader', 'Battle trainer'],          // Gym Leader est un .mid
        'battle_title' => ['Battle Champion', 'Battle Elite', 'Battle Gym Leader', 'Battle trainer'],
        'battle_pvp' => ['Battle Frontier', 'Battle trainer'],
        'surf' => ['Surfing'],
        'bicycle' => ['Bicycle'],                                            // étape 14 : à Bicyclette
        'evolution' => ['Evolution'],
    ],

    // Jingles (dossier ME) : joués une fois, la musique de la carte reprend ensuite
    'me' => [
        'victory_wild' => ['Battle victory wild', 'Battle victory'],         // rangés dans BGM chez Essentials
        'victory_trainer' => ['Battle victory trainer', 'Battle victory'],
        'victory_leader' => ['Battle victory leader', 'Battle victory trainer'],
        'caught' => ['Battle capture success'],
        'heal' => ['Pkmn healing'],
        'item' => ['Item get'],
        'key_item' => ['Key item get', 'Item get'],
        'badge' => ['Badge get'],
        'evolved' => ['Evolution success'],
        'level_up' => ['Pkmn level up'],                                     // rangé dans SE chez Essentials
        'pokemon_get' => ['Pkmn get'],
        'slots_win' => ['Slots win'],                                        // étape 20 : Casino
        'slots_big_win' => ['Slots big win'],
    ],

    // Effets (dossier SE)
    'se' => [
        'cursor' => ['GUI sel cursor'],
        'decision' => ['GUI sel decision'],
        'cancel' => ['GUI sel cancel'],
        'menu_open' => ['GUI menu open'],
        'menu_close' => ['GUI menu close'],
        'save' => ['GUI save game'],                                         // rangé dans ME chez Essentials
        'bump' => ['Player bump'],
        'door' => ['Door exit'],
        'jump' => ['Player jump'],
        'exclaim' => ['Exclaim'],
        'hit' => ['Battle damage normal'],
        'hit_weak' => ['Battle damage weak'],
        'hit_super' => ['Battle damage super'],
        'faint' => ['Pkmn faint'],
        'throw' => ['Battle throw'],
        'ball_drop' => ['Battle ball drop'],
        'ball_shake' => ['Battle ball shake'],
        'catch_click' => ['Battle catch click'],
        'recall' => ['Battle recall'],
        'flee' => ['Battle flee'],
        'exp' => ['Pkmn exp gain'],
        'exp_full' => ['Pkmn exp full'],
        'buy' => ['Mart buy item'],
        'repel' => ['Repel'],                                                // étape 19 : Repousse
        'escape_rope' => ['Escape Rope', 'Fly'],                             // étape 19 : Corde Sortie
        'slots_coin' => ['Slots coin'],                                      // étape 20 : Casino
        'slots_stop' => ['Slots stop'],
        'roulette_ball' => ['Roulette Ball'],                                // sons du script Roulette de FL
        'roulette_ball_end' => ['Roulette Ball End'],
        'pc_open' => ['PC open'],
        'pc_close' => ['PC close'],
        'cut' => ['Cut'],
        'rock_smash' => ['Rock Smash'],
        'strength' => ['Strength push'],
        'fly' => ['Fly'],
        'buzzer' => ['GUI sel buzzer'],
    ],

    // Durée maximum du jingle de victoire, en secondes (il se termine en fondu, puis la musique
    // de la carte revient en fondu). Il s'arrête aussi dès que le joueur revient sur la carte.
    'victory_seconds' => 4,

    // Volume de départ (0 à 1) ; chaque joueur le règle ensuite dans Options
    'music_volume' => 0.6,
    'effects_volume' => 0.8,
];
