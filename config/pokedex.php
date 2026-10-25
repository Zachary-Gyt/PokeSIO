<?php

/*
|--------------------------------------------------------------------------
| Pokédex (étape 13) : les récompenses du Professeur
|--------------------------------------------------------------------------
| Tous les 10 Pokémon CAPTURÉS (10, 20, 30... puis le Pokédex complet), le Professeur envoie
| une récompense. Elle est donnée automatiquement (dans le Sac / le porte-monnaie) au moment
| où le palier est atteint, et le jeu affiche son message au retour sur la carte.
|
|   palier => ['money' => argent, 'items' => [slug d'objet => quantité]]
|   'complete' : le Pokédex entier (tous les Pokémon disponibles : 493 avec les 4 premières générations)
|
| Un objet qui n'existe pas dans la table items est simplement ignoré.
*/

return [

    'professor' => 'Professeur Thouverez',

    'rewards' => [
        10 => ['items' => ['poke-ball' => 10]],
        20 => ['money' => 3000, 'items' => ['great-ball' => 10]],
        30 => ['items' => ['super-potion' => 10, 'revive' => 3]],
        40 => ['money' => 5000, 'items' => ['ultra-ball' => 10]],
        50 => ['items' => ['rare-candy' => 3, 'fire-stone' => 1, 'water-stone' => 1, 'thunder-stone' => 1]],
        60 => ['items' => ['hyper-potion' => 10, 'max-revive' => 3]],
        70 => ['money' => 10000, 'items' => ['rare-candy' => 5]],
        80 => ['items' => ['hp-up' => 3, 'protein' => 3, 'iron' => 3, 'calcium' => 3, 'zinc' => 3, 'carbos' => 3]],
        90 => ['items' => ['ultra-ball' => 20, 'max-elixir' => 3, 'leaf-stone' => 1, 'moon-stone' => 1]],
        100 => ['money' => 20000, 'items' => ['master-ball' => 1]],
        110 => ['items' => ['rare-candy' => 10, 'full-restore' => 5]],
        120 => ['money' => 30000, 'items' => ['max-revive' => 5, 'max-elixir' => 5]],
        130 => ['items' => ['rare-candy' => 15, 'ultra-ball' => 30]],
        140 => ['money' => 50000, 'items' => ['master-ball' => 1]],
        150 => ['items' => ['master-ball' => 1, 'rare-candy' => 20]],
        // 2e génération (Johto) et au-delà
        160 => ['items' => ['ultra-ball' => 20, 'sun-stone' => 1, 'dusk-stone' => 1]],
        170 => ['money' => 20000, 'items' => ['pp-up' => 5]],
        180 => ['items' => ['rare-candy' => 10, 'max-revive' => 5]],
        190 => ['money' => 25000, 'items' => ['full-restore' => 10]],
        200 => ['items' => ['master-ball' => 1, 'lucky-egg' => 1]],
        210 => ['items' => ['hp-up' => 5, 'protein' => 5, 'iron' => 5, 'calcium' => 5, 'zinc' => 5, 'carbos' => 5]],
        220 => ['money' => 30000, 'items' => ['max-elixir' => 5]],
        230 => ['items' => ['rare-candy' => 15, 'shiny-stone' => 1, 'dawn-stone' => 1]],
        240 => ['money' => 35000, 'items' => ['ultra-ball' => 30]],
        250 => ['items' => ['master-ball' => 1, 'pp-max' => 3]],
        260 => ['money' => 40000, 'items' => ['full-restore' => 10, 'max-revive' => 5]],
        270 => ['items' => ['rare-candy' => 20]],
        280 => ['money' => 40000, 'items' => ['amulet-coin' => 1]],
        290 => ['items' => ['hp-up' => 5, 'protein' => 5, 'iron' => 5, 'calcium' => 5, 'zinc' => 5, 'carbos' => 5]],
        300 => ['items' => ['master-ball' => 2]],
        310 => ['money' => 50000, 'items' => ['max-elixir' => 10]],
        320 => ['items' => ['rare-candy' => 20, 'pp-max' => 3]],
        330 => ['money' => 50000, 'items' => ['ultra-ball' => 50]],
        340 => ['items' => ['full-restore' => 15, 'max-revive' => 10]],
        350 => ['items' => ['master-ball' => 2, 'rare-candy' => 20]],
        360 => ['money' => 60000, 'items' => ['pp-max' => 5]],
        370 => ['items' => ['rare-candy' => 25]],
        380 => ['money' => 60000, 'items' => ['hp-up' => 10, 'protein' => 10, 'iron' => 10, 'calcium' => 10, 'zinc' => 10, 'carbos' => 10]],
        390 => ['items' => ['max-elixir' => 10, 'full-restore' => 20]],
        400 => ['money' => 80000, 'items' => ['master-ball' => 3]],
        410 => ['items' => ['rare-candy' => 30]],
        420 => ['money' => 80000, 'items' => ['pp-max' => 5, 'max-revive' => 10]],
        430 => ['items' => ['ultra-ball' => 50, 'full-restore' => 20]],
        440 => ['money' => 90000, 'items' => ['rare-candy' => 30]],
        450 => ['items' => ['master-ball' => 3, 'max-elixir' => 10]],
        460 => ['money' => 100000, 'items' => ['pp-max' => 10]],
        470 => ['items' => ['rare-candy' => 40]],
        480 => ['money' => 100000, 'items' => ['master-ball' => 3]],
        490 => ['items' => ['rare-candy' => 50, 'master-ball' => 3]],
        'complete' => ['money' => 200000, 'items' => ['master-ball' => 5]],
    ],
];
