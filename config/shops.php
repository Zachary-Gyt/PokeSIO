<?php

/*
|--------------------------------------------------------------------------
| Boutiques (Poké Marts)
|--------------------------------------------------------------------------
| Dans Tiled, un vendeur est un PNJ avec les propriétés :
|   action = shop
|   shop   = clé d'une boutique ci-dessous (facultatif : "default" par défaut)
|
| Chaque boutique : [nombre de badges nécessaires => [objets (slug)]].
| Comme dans les jeux récents, le choix grandit avec le nombre de badges.
| Un objet absent de la table items, ou dont le prix (items.price) vaut 0, n'est pas proposé.
*/

return [

    'default' => [
        0 => ['poke-ball', 'potion', 'antidote', 'paralyze-heal', 'awakening', 'burn-heal', 'ice-heal'],
        1 => ['great-ball', 'super-potion', 'escape-rope', 'repel'],
        3 => ['hyper-potion', 'revive', 'super-repel'],
        5 => ['ultra-ball', 'full-heal', 'max-repel'],
        7 => ['max-potion', 'full-restore'],
    ],

    /*
    | Étape 22 : boutiques spécialisées des générations 2 à 4 (comme le Centre Commercial de Céladopole,
    | la boutique de Balls de Rosalia / Joliberges ou l'herboriste de Lavanville). Propriété Tiled shop = clé.
    */
    // Étage "combat" d'un Centre Commercial : objets X
    'combat' => [
        0 => ['x-attack', 'x-defense', 'x-speed', 'x-sp-atk', 'x-sp-def', 'x-accuracy', 'dire-hit', 'guard-spec', 'poke-doll'],
    ],
    // Boutique de Balls spéciales (Hoenn, Sinnoh)
    'balls' => [
        0 => ['poke-ball', 'great-ball', 'premier-ball', 'heal-ball', 'net-ball', 'nest-ball'],
        3 => ['dive-ball', 'repeat-ball', 'timer-ball', 'luxury-ball', 'dusk-ball', 'quick-ball'],
        5 => ['ultra-ball'],
    ],
    // Herboriste : remèdes bon marché, mais amers (le Pokémon vous aime moins)
    'herboriste' => [
        0 => ['energy-powder', 'energy-root', 'heal-powder', 'revival-herb'],
    ],
    // Pharmacie de Centre Commercial : vitamines
    'vitamines' => [
        0 => ['hp-up', 'protein', 'iron', 'calcium', 'zinc', 'carbos'],
    ],

    // Exemple de boutique spécialisée, sans condition de badge : propriété Tiled shop = pharmacie
    'pharmacie' => [
        0 => ['potion', 'super-potion', 'hyper-potion', 'antidote', 'paralyze-heal', 'awakening', 'burn-heal', 'ice-heal', 'full-heal'],
    ],

    // Une Honor Ball offerte pour chaque lot de 10 Balls achetées d'un coup (comme dans les jeux)
    'bonus_ball' => 'premier-ball',

    /*
    | Prix de secours (Rouge Feu / Vert Feuille), utilisés quand items.price vaut 0 dans la base.
    | Le prix de revente est la moitié du prix d'achat. Un objet absent d'ici et à 0 en base
    | n'est ni vendu ni rachetable.
    */
    'prices' => [
        'poke-ball' => 200, 'great-ball' => 600, 'ultra-ball' => 1200, 'premier-ball' => 200,
        'net-ball' => 1000, 'dive-ball' => 1000, 'nest-ball' => 1000, 'repeat-ball' => 1000, 'timer-ball' => 1000, 'luxury-ball' => 1000,
        'potion' => 300, 'super-potion' => 700, 'hyper-potion' => 1200, 'max-potion' => 2500, 'full-restore' => 3000,
        'revive' => 1500, 'max-revive' => 4000,
        'antidote' => 100, 'paralyze-heal' => 200, 'awakening' => 250, 'burn-heal' => 250, 'ice-heal' => 250, 'full-heal' => 600,
        'fresh-water' => 200, 'soda-pop' => 300, 'lemonade' => 350, 'moomoo-milk' => 500,
        'ether' => 1200, 'max-ether' => 2000, 'elixir' => 3000, 'max-elixir' => 4500,
        'escape-rope' => 550, 'repel' => 350, 'super-repel' => 500, 'max-repel' => 700,
        'x-attack' => 500, 'x-defense' => 550, 'x-speed' => 350, 'x-accuracy' => 950, 'x-sp-atk' => 350, 'x-sp-def' => 350,
        'guard-spec' => 700, 'dire-hit' => 650, 'poke-doll' => 1000,
        // Étape 22 (prix des jeux récents, comme items.csv de PokéAPI)
        'heal-ball' => 300, 'dusk-ball' => 1000, 'quick-ball' => 1000,
        'energy-powder' => 500, 'energy-root' => 1200, 'heal-powder' => 300, 'revival-herb' => 2800,
        'fluffy-tail' => 1000, 'pp-max' => 9800, 'sacred-ash' => 50000,
        'hp-up' => 9800, 'protein' => 9800, 'iron' => 9800, 'calcium' => 9800, 'zinc' => 9800, 'carbos' => 9800, 'pp-up' => 9800,
        'rare-candy' => 4800,
        'fire-stone' => 2100, 'water-stone' => 2100, 'thunder-stone' => 2100, 'leaf-stone' => 2100, 'moon-stone' => 2100,
        'nugget' => 10000, 'big-pearl' => 7500, 'pearl' => 1400, 'stardust' => 2000, 'star-piece' => 9800, 'tiny-mushroom' => 500, 'big-mushroom' => 5000,
    ],

];
