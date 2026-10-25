<?php

/*
|--------------------------------------------------------------------------
| Effets des objets du Sac (étape 22)
|--------------------------------------------------------------------------
| Les valeurs suivent Pokémon Essentials v21 (règles des jeux récents), sauf mention contraire.
| Après une modification : php artisan config:clear
*/

return [

    // Remèdes : objet -> statuts guéris. Un objet "cure_status" absent d'ici guérit TOUS les statuts
    // (Total Soin, Poudre Soin, Lava Cookie, Vieux Gâteau, Baie Prine...).
    'cures' => [
        'antidote' => ['poison', 'bad_poison'],
        'paralyze-heal' => ['paralysis'],
        'burn-heal' => ['burn'],
        'ice-heal' => ['freeze'],
        'awakening' => ['sleep'],
        'blue-flute' => ['sleep'],
        'cheri-berry' => ['paralysis'],
        'chesto-berry' => ['sleep'],
        'pecha-berry' => ['poison', 'bad_poison'],
        'rawst-berry' => ['burn'],
        'aspear-berry' => ['freeze'],
    ],

    // Objets X : la stat augmentée (de "value" niveaux, 2 dans les jeux récents, 1 jusqu'à la 6e génération)
    'battle_stats' => [
        'x-attack' => 'attack', 'x-defense' => 'defense', 'x-sp-atk' => 'sp_attack',
        'x-sp-def' => 'sp_defense', 'x-speed' => 'speed', 'x-accuracy' => 'accuracy',
    ],

    // Baies qui baissent les EV (et rendent le Pokémon plus heureux)
    'ev_berries' => [
        'pomeg-berry' => 'hp', 'kelpsy-berry' => 'attack', 'qualot-berry' => 'defense',
        'hondew-berry' => 'sp_attack', 'grepa-berry' => 'sp_defense', 'tamato-berry' => 'speed',
    ],

    // Plantes médicinales amères : le Pokémon les aime moins (méthode de bonheur, voir plus bas)
    'bitter' => [
        'energy-powder' => 'powder', 'heal-powder' => 'powder',
        'energy-root' => 'energy_root', 'revival-herb' => 'revival_herb',
    ],

    /*
    | Poké Balls : le bonus de base est items.effect_value (x10 : 15 = x1,5). Les Balls spéciales
    | ont en plus une condition, calculée par le moteur de combat (BattleEngine::ballModifier) :
    |   net (x3,5 Eau/Insecte), dive (x3,5 sur l'eau ou à la pêche), nest ((41 - niveau) / 10),
    |   repeat (x3,5 si déjà capturé), timer (1 + 0,3 par tour, x4 max), dusk (x3 la nuit ou dans une grotte),
    |   quick (x5 au premier tour), level, lure (x5 à la pêche), moon, love, heavy, fast,
    |   et à la capture : heal (soigne), friend (Bonheur 200), luxury (Bonheur gagné +1).
    */
    'balls' => [
        'net-ball' => 3.5, 'dive-ball' => 3.5, 'repeat-ball' => 3.5, 'dusk-ball' => 3, 'quick-ball' => 5,
        'lure-ball' => 5, 'moon-ball' => 4, 'love-ball' => 8, 'fast-ball' => 4,
    ],

    // Flûte Noire / Blanche : rencontres x value / 100 jusqu'au changement de carte
    // Poupée Pokémon, Queue Skitty : fuite assurée face à un Pokémon sauvage

    /*
    |--------------------------------------------------------------------------
    | Le Bonheur (amitié) : 0 à 255
    |--------------------------------------------------------------------------
    | Gain selon le Bonheur actuel : [moins de 100, 100 à 199, 200 et plus] (règles de la 4e génération).
    | Grelot Zen : gains x1,5. Luxe Ball : +1 à chaque gain.
    */
    'friendship' => [
        'walking_steps' => 128,                  // +1 tous les 128 pas pour chaque Pokémon de l'équipe
        'gains' => [
            'walking' => [1, 1, 1],
            'levelup' => [5, 3, 2],
            'vitamin' => [5, 3, 2],
            'evberry' => [10, 5, 2],
            'battleitem' => [1, 1, 0],
            'gym' => [3, 2, 1],
            'faint' => [-1, -1, -1],
            'powder' => [-5, -5, -10],
            'energy_root' => [-10, -10, -15],
            'revival_herb' => [-15, -15, -20],
        ],
        'friend_ball' => 200,                    // Bonheur d'un Pokémon capturé avec une Copain Ball
    ],

    // Objets tenus qui réduisent les rencontres quand le PREMIER Pokémon de l'équipe les tient (x2/3)
    'encounter_reducers' => ['cleanse-tag', 'pure-incense'],

    /*
    | Étape 23 : Ramassage (talent). Après un combat, 10 % de chances par Pokémon sans objet tenu.
    | Les objets dépendent du niveau du Pokémon : [niveau maximum => [slug => poids]].
    */
    'pickup' => [
        ['max_level' => 10, 'items' => ['potion' => 30, 'antidote' => 10, 'super-potion' => 10, 'great-ball' => 10, 'repel' => 10, 'escape-rope' => 10, 'full-heal' => 10, 'hyper-potion' => 4, 'ultra-ball' => 4, 'rare-candy' => 1, 'nugget' => 1]],
        ['max_level' => 30, 'items' => ['super-potion' => 30, 'great-ball' => 10, 'full-heal' => 10, 'ultra-ball' => 10, 'revive' => 10, 'hyper-potion' => 10, 'rare-candy' => 10, 'sun-stone' => 4, 'moon-stone' => 4, 'heart-scale' => 1, 'full-restore' => 1]],
        ['max_level' => 60, 'items' => ['ultra-ball' => 30, 'revive' => 10, 'rare-candy' => 10, 'dusk-stone' => 10, 'shiny-stone' => 10, 'dawn-stone' => 10, 'full-restore' => 10, 'max-revive' => 4, 'pp-up' => 4, 'max-elixir' => 1, 'leftovers' => 1]],
        ['max_level' => 100, 'items' => ['rare-candy' => 30, 'full-restore' => 10, 'max-revive' => 10, 'pp-up' => 10, 'max-elixir' => 10, 'ether' => 10, 'max-ether' => 10, 'elixir' => 4, 'pp-max' => 4, 'big-pearl' => 1, 'leftovers' => 1]],
    ],
];
