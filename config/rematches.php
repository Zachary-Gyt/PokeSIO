<?php

/*
|--------------------------------------------------------------------------
| Les revanches (étape 26)
|--------------------------------------------------------------------------
| Après une modification : php artisan config:clear
*/

return [

    /*
    | Le Cherche VS (Rouge Feu / Vert Feuille, Diamant / Perle / Platine). Objet rare : on l'utilise depuis le Sac.
    | Les dresseurs déjà battus qui sont à l'écran (range : distance en cases, en largeur et en hauteur)
    | peuvent "lever la main" (chance en %) : ils veulent une revanche. Ensuite, la batterie se recharge en marchant.
    */
    'vs_seeker' => [
        'item' => 'vs-seeker',
        'steps' => 100,
        'chance' => 75,
        'range' => [8, 6],
    ],

    /*
    | Les équipes de revanche : trainer_pokemon.version = 1, 2, 3... (0 = premier combat).
    | Une version est débloquée à partir de ce nombre de badges ; le dresseur prend la plus forte débloquée
    | qui existe pour lui.
    */
    'versions' => [1 => 1, 2 => 3, 3 => 5, 4 => 7, 5 => 8],

    /*
    | Un dresseur SANS équipe de revanche : son équipe d'origine gagne des niveaux selon les badges du joueur
    | (levels_per_badge par badge), sans dépasser le plus haut niveau de l'équipe du joueur, puis évolue
    | (évolution par niveau ; les autres évolutions, pierre, échange, bonheur... à partir de other_evolution_level).
    */
    'auto' => [
        'levels_per_badge' => 3,
        'cap_to_player' => true,
        'other_evolution_level' => 36,
    ],

    // Les Champions d'Arène acceptent une revanche à partir de ce nombre de badges (comme dans Platine / HeartGold)
    'leaders_badges' => 8,
];
