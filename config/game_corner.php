<?php

/*
|--------------------------------------------------------------------------
| Le Casino (étape 20)
|--------------------------------------------------------------------------
| Jetons, guichet, comptoirs des lots, machines à sous et roulettes.
| Après une modification : php artisan config:clear
*/

return [

    // Le plafond de la Boîte Jetons (comme les jeux)
    'max_coins' => 9999,

    // L'objet rare sans lequel on ne peut ni acheter ni jouer (slug de la table items)
    'coin_case' => 'coin-case',

    // Le guichet (PNJ action = coins) : [jetons, prix en ₽]. Rouge / Bleu : 50 jetons pour 1 000 ₽.
    'packs' => [
        [50, 1000],
        [500, 10000],
    ],

    /*
    | Les comptoirs des lots (PNJ action = prizes, propriété prizes = la clé ci-dessous).
    | Une ligne : un objet ('item' => slug), une CT ('tm' => slug de la capacité, la CT est retrouvée toute seule)
    | ou un Pokémon ('pokemon' => slug, 'level'). 'price' = jetons.
    | Valeurs par défaut : le Casino de Céladopole dans Rouge Feu.
    */
    'prizes' => [
        'pokemon' => [
            ['pokemon' => 'abra', 'level' => 9, 'price' => 180],
            ['pokemon' => 'clefairy', 'level' => 8, 'price' => 500],
            ['pokemon' => 'dratini', 'level' => 18, 'price' => 2800],
            ['pokemon' => 'scyther', 'level' => 25, 'price' => 5500],
            ['pokemon' => 'porygon', 'level' => 26, 'price' => 9999],
        ],
        'tms' => [
            ['tm' => 'ice-beam', 'price' => 4000],
            ['tm' => 'iron-tail', 'price' => 3500],
            ['tm' => 'thunderbolt', 'price' => 4000],
            ['tm' => 'shadow-ball', 'price' => 4500],
            ['tm' => 'flamethrower', 'price' => 4000],
        ],
        'items' => [
            ['item' => 'smoke-ball', 'price' => 800],
            ['item' => 'miracle-seed', 'price' => 1000],
            ['item' => 'charcoal', 'price' => 1000],
            ['item' => 'mystic-water', 'price' => 1000],
        ],
    ],

    /*
    | Machines à sous (panneau Tiled action = slots, propriété difficulty = 0, 1 ou 2).
    | Chaque rouleau est fait de ces symboles, mélangés (Pokémon Essentials, Minigame_SlotMachine.rb) :
    |   0 Cerise, 1 Magnéti, 2 Kokiyas, 3 Pikachu, 4 Psykokwak, 5 7 rouge, 6 7 bleu, 7 REPLAY
    | Plus un symbole est présent, plus il tombe souvent.
    */
    'slots' => [
        'reels' => [
            0 => [0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 3, 4, 4, 4, 5, 5, 6, 6, 7],   // facile
            1 => [0, 0, 0, 0, 1, 1, 1, 2, 2, 2, 3, 3, 4, 4, 5, 6, 7],         // normal
            2 => [0, 0, 1, 1, 1, 2, 2, 2, 3, 3, 4, 4, 5, 6, 7],               // difficile
        ],
        /*
        | Comme dans les vrais jeux, la machine décide d'abord CE QUI SORT, puis arrête les rouleaux pour l'afficher.
        | (Avec un tirage au hasard des rouleaux, les Cerises rapporteraient plus que la mise : jetons infinis.)
        | % de chances à chaque partie avec une mise de 3, par difficulté (le reste : perdu).
        | Avec une mise de 1 ou 2, ces chances sont multipliées par 1/3 ou 2/3 (moins de lignes jouées) :
        |   small 1 à 7 jetons (Cerises) ; medium 8 à 14 (Magnéti, Kokiyas) ; big 15 à 89 (Pikachu, Psykokwak) ;
        |   mixed 90 à 299 (7 de deux couleurs) ; jackpot 300 et plus (7 rouges ou bleus) ; replay (partie gratuite)
        | Rendement moyen (jetons gagnés / misés, mise de 3) : facile ~ 89 %, normal ~ 87 %, difficile ~ 70 %.
        */
        'odds' => [
            0 => ['small' => 14.5, 'medium' => 4.5, 'big' => 2.7, 'mixed' => 0.45, 'jackpot' => 0.26, 'replay' => 3],
            1 => ['small' => 15, 'medium' => 4.5, 'big' => 2.5, 'mixed' => 0.4, 'jackpot' => 0.25, 'replay' => 2.5],
            2 => ['small' => 13, 'medium' => 4, 'big' => 2, 'mixed' => 0.35, 'jackpot' => 0.2, 'replay' => 2],
        ],
        // Gains (en jetons, par ligne gagnante)
        'payouts' => [
            'magnemite' => 8, 'shellder' => 8, 'pikachu' => 15, 'psyduck' => 15,
            'mixed_seven' => 90, 'seven' => 300, 'cherry' => 2, 'two_cherries' => 4,
        ],
    ],

    // Roulette (panneau action = roulette, propriété wager = mise : 1 ou 3, color = red / green / blue)
    'roulette' => [
        'rounds' => 6,   // balles jouées avant que le plateau soit vidé
        // Rendement moyen (jetons gagnés / misés). Comme la balle ne retombe jamais sur une case déjà sortie,
        // un tirage parfaitement au hasard rapporterait PLUS que la mise en fin de plateau (12 / cases libres) :
        // jetons infinis. Le serveur règle donc la chance de gagner pour que ce rendement soit respecté.
        'payout_rate' => 0.9,
    ],
];
