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
    | Boutiques SPÉCIALISÉES (propriété Tiled shop = la clé). Un vendeur de chacune est dans la salle de test
    | (dev_room) ; à toi de les placer dans le monde. Le choix grandit avec les badges.
    */

    // Objets de combat (étage "combat" d'un Centre Commercial) et Flûtes
    'combat' => [
        0 => ['x-attack', 'x-defense', 'x-speed', 'x-sp-atk', 'x-sp-def', 'x-accuracy', 'dire-hit', 'guard-spec', 'poke-doll', 'fluffy-tail'],
        2 => ['yellow-flute', 'red-flute', 'black-flute', 'white-flute'],
    ],
    // Balls spéciales (Rosalia, Joliberges) ; les Balls Noigrumes de Fargas après 6 badges
    'balls' => [
        0 => ['poke-ball', 'great-ball', 'premier-ball', 'heal-ball', 'net-ball', 'nest-ball', 'sport-ball'],
        2 => ['dive-ball', 'repeat-ball', 'timer-ball', 'dusk-ball', 'quick-ball'],
        4 => ['ultra-ball', 'luxury-ball'],
        6 => ['level-ball', 'lure-ball', 'moon-ball', 'friend-ball', 'love-ball', 'heavy-ball', 'fast-ball'],
    ],
    // Herboriste : remèdes bon marché, mais amers (le Pokémon vous aime moins)
    'herboriste' => [
        0 => ['energy-powder', 'energy-root', 'heal-powder', 'revival-herb'],
    ],
    // Pharmacie de Centre Commercial : vitamines, PP Plus et PP Max
    'vitamines' => [
        0 => ['hp-up', 'protein', 'iron', 'calcium', 'zinc', 'carbos'],
        3 => ['pp-up'],
        6 => ['pp-max'],
    ],
    // Pharmacie : soins, boissons, spécialités régionales, PP
    'pharmacie' => [
        0 => ['potion', 'super-potion', 'antidote', 'paralyze-heal', 'awakening', 'burn-heal', 'ice-heal', 'full-heal',
            'fresh-water', 'soda-pop', 'lemonade', 'moomoo-milk', 'berry-juice', 'lava-cookie', 'old-gateau', 'blue-flute'],
        2 => ['hyper-potion', 'ether', 'max-ether', 'revive'],
        4 => ['elixir', 'max-elixir', 'max-revive'],
        6 => ['max-potion', 'full-restore'],
        8 => ['sacred-ash'],
    ],
    // Nouveau : la marchande de Baies (toutes les Baies des générations 1 à 4)
    'baies' => [
        0 => ['oran-berry', 'cheri-berry', 'chesto-berry', 'pecha-berry', 'rawst-berry', 'aspear-berry', 'persim-berry',
            'razz-berry', 'bluk-berry', 'nanab-berry', 'wepear-berry', 'pinap-berry', 'cornn-berry', 'magost-berry', 'rabuta-berry',
            'nomel-berry', 'spelon-berry', 'pamtre-berry', 'watmel-berry', 'durin-berry', 'belue-berry'],
        2 => ['sitrus-berry', 'leppa-berry', 'figy-berry', 'wiki-berry', 'mago-berry', 'aguav-berry', 'iapapa-berry',
            'pomeg-berry', 'kelpsy-berry', 'qualot-berry', 'hondew-berry', 'grepa-berry', 'tamato-berry'],
        4 => ['lum-berry', 'occa-berry', 'passho-berry', 'wacan-berry', 'rindo-berry', 'yache-berry', 'chople-berry', 'kebia-berry',
            'shuca-berry', 'coba-berry', 'payapa-berry', 'tanga-berry', 'charti-berry', 'kasib-berry', 'haban-berry', 'colbur-berry',
            'babiri-berry', 'chilan-berry'],
        6 => ['liechi-berry', 'ganlon-berry', 'salac-berry', 'petaya-berry', 'apicot-berry', 'lansat-berry', 'starf-berry',
            'micle-berry', 'custap-berry', 'enigma-berry', 'jaboca-berry', 'rowap-berry'],
    ],
    // Nouveau : les CT qu'on ne trouve pas ailleurs (comme le Centre Commercial de Céladopole / Voilaroc)
    'ct' => [
        0 => ['tm05', 'tm10', 'tm11', 'tm12', 'tm16', 'tm17', 'tm18', 'tm20', 'tm21', 'tm27', 'tm32', 'tm33', 'tm37', 'tm41', 'tm42', 'tm44', 'tm46'],
        2 => ['tm08', 'tm09', 'tm28', 'tm31', 'tm40', 'tm47'],
        4 => ['tm02', 'tm13', 'tm14', 'tm15', 'tm22', 'tm24', 'tm25', 'tm29', 'tm35', 'tm36', 'tm50'],
        6 => ['hm07', 'hm08'],
    ],
    // Nouveau : les objets qui renforcent un type (+20 %)
    'objets_type' => [
        0 => ['charcoal', 'mystic-water', 'miracle-seed', 'magnet', 'never-melt-ice', 'black-belt', 'poison-barb', 'soft-sand',
            'sharp-beak', 'twisted-spoon', 'silver-powder', 'hard-stone', 'spell-tag', 'dragon-fang', 'silk-scarf', 'black-glasses',
            'metal-coat', 'sea-incense', 'odd-incense', 'rock-incense', 'wave-incense', 'rose-incense'],
        5 => ['flame-plate', 'splash-plate', 'zap-plate', 'meadow-plate', 'icicle-plate', 'fist-plate', 'toxic-plate', 'earth-plate',
            'sky-plate', 'mind-plate', 'insect-plate', 'stone-plate', 'spooky-plate', 'draco-plate', 'dread-plate', 'iron-plate'],
    ],
    // Nouveau : objets tenus de combat et d'entraînement
    'objets_combat' => [
        0 => ['smoke-ball', 'quick-claw', 'kings-rock', 'focus-band', 'scope-lens', 'bright-powder', 'white-herb', 'mental-herb',
            'power-herb', 'shell-bell', 'big-root', 'light-clay', 'icy-rock', 'smooth-rock', 'heat-rock', 'damp-rock', 'grip-claw',
            'shed-shell', 'zoom-lens', 'wide-lens', 'metronome', 'black-sludge', 'soothe-bell', 'cleanse-tag', 'pure-incense'],
        3 => ['muscle-band', 'wise-glasses', 'expert-belt', 'lax-incense', 'macho-brace', 'power-bracer', 'power-belt', 'power-lens',
            'power-band', 'power-anklet', 'power-weight', 'toxic-orb', 'flame-orb', 'iron-ball', 'lagging-tail', 'sticky-barb', 'full-incense'],
        6 => ['choice-band', 'choice-scarf', 'choice-specs', 'life-orb', 'focus-sash', 'destiny-knot', 'luck-incense'],
    ],
    // Nouveau : pierres et objets d'évolution, objets d'espèce
    'objets_evolution' => [
        0 => ['everstone', 'oval-stone', 'fire-stone', 'water-stone', 'thunder-stone', 'leaf-stone', 'moon-stone'],
        2 => ['light-ball', 'lucky-punch', 'metal-powder', 'quick-powder', 'thick-club', 'stick', 'deep-sea-tooth', 'deep-sea-scale'],
        4 => ['sun-stone', 'shiny-stone', 'dusk-stone', 'dawn-stone', 'razor-claw', 'razor-fang', 'dragon-scale', 'up-grade',
            'protector', 'electirizer', 'magmarizer', 'dubious-disc', 'reaper-cloth', 'prism-scale'],
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
        // Boutiques spécialisées : objets sans prix dans PokéAPI
        'lure-ball' => 1000, 'level-ball' => 1000, 'moon-ball' => 1000, 'heavy-ball' => 1000, 'fast-ball' => 1000, 'friend-ball' => 1000, 'love-ball' => 1000,
        'everstone' => 3000, 'quick-claw' => 4000, 'kings-rock' => 5000, 'focus-band' => 4000, 'scope-lens' => 4000, 'smoke-ball' => 3000, 'destiny-knot' => 8000,
        'tm05' => 2000, 'tm10' => 3000, 'tm11' => 2000, 'tm12' => 2000, 'tm16' => 2000, 'tm17' => 2000, 'tm18' => 2000, 'tm20' => 2000, 'tm21' => 2000,
        'tm27' => 2000, 'tm32' => 2000, 'tm33' => 2000, 'tm37' => 2000, 'tm41' => 2000, 'tm42' => 3000, 'tm44' => 3000, 'tm46' => 2000,
        'tm08' => 3000, 'tm09' => 3000, 'tm28' => 3000, 'tm31' => 3000, 'tm40' => 3000, 'tm47' => 3000,
        'tm02' => 5500, 'tm13' => 5500, 'tm14' => 5500, 'tm15' => 7500, 'tm22' => 5500, 'tm24' => 5500, 'tm25' => 5500, 'tm29' => 5500,
        'tm35' => 5500, 'tm36' => 5500, 'tm50' => 5500,
        'hm07' => 5000, 'hm08' => 5000,
        'oran-berry' => 100, 'sitrus-berry' => 500,
    ],

    /*
    | Prix IMPOSÉS : ils remplacent le prix de la base (PokéAPI donne 20 ou 80 ₽ pour toutes les Baies, et
    | quelques prix trop bas). Le prix de revente reste celui de la base (ou la moitié du prix d'achat).
    */
    'price_overrides' => [
        // Baies de soin et de statut
        'cheri-berry' => 200, 'chesto-berry' => 200, 'pecha-berry' => 200, 'rawst-berry' => 200, 'aspear-berry' => 200, 'persim-berry' => 200,
        'leppa-berry' => 500, 'lum-berry' => 1000,
        'figy-berry' => 400, 'wiki-berry' => 400, 'mago-berry' => 400, 'aguav-berry' => 400, 'iapapa-berry' => 400,
        // Baies à Poffins / Pokébloc (sans effet en combat)
        'razz-berry' => 100, 'bluk-berry' => 100, 'nanab-berry' => 100, 'wepear-berry' => 100, 'pinap-berry' => 100, 'cornn-berry' => 100,
        'magost-berry' => 100, 'rabuta-berry' => 100, 'nomel-berry' => 100, 'spelon-berry' => 100, 'pamtre-berry' => 100, 'watmel-berry' => 100,
        'durin-berry' => 100, 'belue-berry' => 100,
        // Baies qui baissent les EV
        'pomeg-berry' => 400, 'kelpsy-berry' => 400, 'qualot-berry' => 400, 'hondew-berry' => 400, 'grepa-berry' => 400, 'tamato-berry' => 400,
        // Baies qui protègent d'un type
        'occa-berry' => 1000, 'passho-berry' => 1000, 'wacan-berry' => 1000, 'rindo-berry' => 1000, 'yache-berry' => 1000, 'chople-berry' => 1000,
        'kebia-berry' => 1000, 'shuca-berry' => 1000, 'coba-berry' => 1000, 'payapa-berry' => 1000, 'tanga-berry' => 1000, 'charti-berry' => 1000,
        'kasib-berry' => 1000, 'haban-berry' => 1000, 'colbur-berry' => 1000, 'babiri-berry' => 1000, 'chilan-berry' => 1000,
        // Baies "en cas de besoin" et rares
        'liechi-berry' => 2000, 'ganlon-berry' => 2000, 'salac-berry' => 2000, 'petaya-berry' => 2000, 'apicot-berry' => 2000,
        'lansat-berry' => 3000, 'starf-berry' => 3000, 'micle-berry' => 3000, 'custap-berry' => 3000, 'enigma-berry' => 3000,
        'jaboca-berry' => 3000, 'rowap-berry' => 3000,
        // Flûtes et Plaques (prix de la base trop bas)
        'blue-flute' => 1000, 'yellow-flute' => 1000, 'red-flute' => 1000, 'black-flute' => 1000, 'white-flute' => 1000,
        'flame-plate' => 3000, 'splash-plate' => 3000, 'zap-plate' => 3000, 'meadow-plate' => 3000, 'icicle-plate' => 3000, 'fist-plate' => 3000,
        'toxic-plate' => 3000, 'earth-plate' => 3000, 'sky-plate' => 3000, 'mind-plate' => 3000, 'insect-plate' => 3000, 'stone-plate' => 3000,
        'spooky-plate' => 3000, 'draco-plate' => 3000, 'dread-plate' => 3000, 'iron-plate' => 3000,
    ],

];
