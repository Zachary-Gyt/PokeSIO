<?php

/*
|--------------------------------------------------------------------------
| Capacités utilisées hors combat (étape 11)
|--------------------------------------------------------------------------
| Accès dans le code : config('field_moves.require_badges')
*/

return [

    // Faut-il le badge d'arène pour utiliser une CS hors combat (comme dans Rouge Feu / Vert Feuille) ?
    // Pendant tes tests, mets FIELD_MOVES_REQUIRE_BADGES=false dans .env (puis php artisan config:clear).
    'require_badges' => (bool) env('FIELD_MOVES_REQUIRE_BADGES', true),

    // Capacité (slug de la table moves) => numéro du badge nécessaire (colonne badges.order)
    //   1 Roche, 2 Cascade, 3 Foudre, 4 Prisme, 5 Âme, 6 Marais, 7 Volcan, 8 Terre
    'moves' => [
        'cut' => 2,
        'fly' => 3,
        'surf' => 5,
        'strength' => 4,
        'flash' => 1,
        'rock-smash' => 6,
    ],

    // Chance (en %) qu'un Pokémon sauvage sorte d'un rocher brisé par Éclate-Roc
    // (seulement si la carte a des rencontres "rock_smash" dans la table wild_encounters)
    'rock_smash_encounter_chance' => 25,
];
