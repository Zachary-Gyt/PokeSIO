<?php

/*
|--------------------------------------------------------------------------
| Import des données depuis PokéAPI (https://pokeapi.co)
|--------------------------------------------------------------------------
| Utilisé par `php artisan pokeapi:import`.
*/

return [

    'base_url' => env('POKEAPI_URL', 'https://pokeapi.co/api/v2'),

    // Les réponses JSON sont gardées ici : relancer l'import ne refait aucun appel réseau.
    'cache_path' => storage_path('app/pokeapi-cache'),

    // Langue des noms et descriptions (repli automatique sur l'anglais).
    'language' => 'fr',

    // Générations jouables : les espèces importées hors de cette liste ont is_obtainable = false.
    'obtainable_generations' => [1, 2, 3, 4],

    // Pokémon proposés à la création du personnage (is_starter = true à l'import).
    // Tous les starters sont listés : ceux d'une génération apparaissent dans le choix
    // dès que cette génération est importée et rendue jouable (obtainable_generations).
    'starters' => [
        'bulbasaur', 'charmander', 'squirtle',      // Gen 1 - Kanto
        'chikorita', 'cyndaquil', 'totodile',       // Gen 2 - Johto
        'treecko', 'torchic', 'mudkip',             // Gen 3 - Hoenn
        'turtwig', 'chimchar', 'piplup',            // Gen 4 - Sinnoh
        'snivy', 'tepig', 'oshawott',               // Gen 5 - Unys
        'chespin', 'fennekin', 'froakie',           // Gen 6 - Kalos
        'rowlet', 'litten', 'popplio',              // Gen 7 - Alola
        'grookey', 'scorbunny', 'sobble',           // Gen 8 - Galar
        'sprigatito', 'fuecoco', 'quaxly',          // Gen 9 - Paldea
    ],

    // Learnset (attaques apprises) : jeu de référence, par ordre de préférence.
    // On prend UN seul jeu par espèce pour garder des learnsets cohérents.
    // USUM contient les 151 de Kanto avec le système moderne (split physique/spécial, type Fée).
    // Mets 'scarlet-violet' en premier si tu veux les learnsets les plus récents.
    'version_groups' => [
        'ultra-sun-ultra-moon',
        'sun-moon',
        'omega-ruby-alpha-sapphire',
        'x-y',
    ],

    // Sprites : versions pixel art homogènes, idéales pour un jeu 2D.
    // Pour chaque sprite, les sources sont essayées dans l'ordre : la première qui
    // existe (et qui se télécharge) est gardée.
    // PokéAPI ne fournit PAS de sprite "overworld" (Pokémon qui suit le joueur) : à ajouter à la main.
    'sprites' => [
        'front' => ['versions.generation-v.black-white.front_default', 'front_default'],
        'back' => ['versions.generation-v.black-white.back_default', 'back_default'],
        'front_shiny' => ['versions.generation-v.black-white.front_shiny', 'front_shiny'],
        'back_shiny' => ['versions.generation-v.black-white.back_shiny', 'back_shiny'],
        // Icônes Gen 7 en premier : complètes pour Kanto (les Gen 8 ont des trous chez PokéAPI)
        'icon' => ['versions.generation-vii.icons.front_default', 'versions.generation-viii.icons.front_default'],
        'disk' => 'public',               // avec --download-sprites, fichiers stockés sur ce disque
        'directory' => 'sprites/pokemon',
    ],

    /*
    | Objets importés : catégories PokéAPI retenues + objets rares choisis à la main.
    */
    'item_categories' => [
        'standard-balls', 'special-balls',                                  // Balls
        'healing', 'status-cures', 'revival', 'pp-recovery', 'vitamins',     // Soins
        'stat-boosts',                                                      // Attaque +, Défense +...
        'medicine', 'in-a-pinch', 'picky-healing', 'type-protection',        // Baies
        'evolution',                                                        // Pierres d'évolution
        'held-items', 'choice', 'type-enhancement',                         // Objets tenus
        'spelunking',                                                       // Repousse, Corde Sortie
    ],
	
	'evolution_areas' => [
          'magnetic' => ['mt-coronet', 'chargestone-cave', 'kalos-route-13', 'vast-poni-canyon', 'blush-mountain'],
          'moss' => ['eterna-forest', 'pinwheel-forest', 'kalos-route-20', 'lush-jungle'],
          'ice' => ['sinnoh-route-217', 'twist-mountain', 'frost-cavern', 'mount-lanakila'],
      ],

    'key_items' => ['bicycle', 'old-rod', 'good-rod', 'super-rod', 'town-map', 'exp-share'],

    // Catégories rangées dans la poche "objets tenus" (PokéAPI les met dans "misc").
    'held_item_categories' => ['held-items', 'choice', 'type-enhancement'],

    // Poche PokéAPI -> poche du jeu (valeurs de l'enum items.pocket)
    'pocket_map' => [
        'pokeballs' => 'balls',
        'medicine' => 'medicine',
        'battle' => 'medicine',
        'berries' => 'berries',
        'machines' => 'tms',
        'key' => 'key_items',
        'misc' => 'misc',
    ],

    /*
    | Effets lus par ton moteur de jeu : slug => [effect_code, effect_value].
    | PokéAPI ne décrit les effets qu'en texte libre, donc cette table est faite à la main.
    | Pour les balls, effect_value = multiplicateur de capture x10 (15 = x1.5, 255 = capture garantie).
    */
    'item_effects' => [
        'potion' => ['heal_hp', 20],
        'super-potion' => ['heal_hp', 60],
        'hyper-potion' => ['heal_hp', 120],
        'max-potion' => ['heal_hp_full', null],
        'full-restore' => ['full_restore', null],
        'fresh-water' => ['heal_hp', 30],
        'soda-pop' => ['heal_hp', 50],
        'lemonade' => ['heal_hp', 70],
        'moomoo-milk' => ['heal_hp', 100],
        'antidote' => ['cure_status', null],
        'paralyze-heal' => ['cure_status', null],
        'burn-heal' => ['cure_status', null],
        'ice-heal' => ['cure_status', null],
        'awakening' => ['cure_status', null],
        'full-heal' => ['cure_status', null],
        'revive' => ['revive', 50],                   // % des PV max
        'max-revive' => ['revive', 100],
        'ether' => ['restore_pp', 10],
        'max-ether' => ['restore_pp', 99],
        'elixir' => ['restore_pp_all', 10],
        'max-elixir' => ['restore_pp_all', 99],
        'rare-candy' => ['level_up', 1],
        'hp-up' => ['add_ev', 10],
        'protein' => ['add_ev', 10],
        'iron' => ['add_ev', 10],
        'calcium' => ['add_ev', 10],
        'zinc' => ['add_ev', 10],
        'carbos' => ['add_ev', 10],
        'poke-ball' => ['catch', 10],
        'great-ball' => ['catch', 15],
        'ultra-ball' => ['catch', 20],
        'master-ball' => ['catch', 255],
        'oran-berry' => ['heal_hp', 10],
        'sitrus-berry' => ['heal_hp_percent', 25],
        'repel' => ['repel', 100],                    // nombre de pas
        'super-repel' => ['repel', 200],
        'max-repel' => ['repel', 250],
        'escape-rope' => ['escape', null],
        'old-rod' => ['fish', 1],
        'good-rod' => ['fish', 2],
        'super-rod' => ['fish', 3],
        'bicycle' => ['ride', null],
    ],

    // Effet par défaut d'une catégorie quand l'objet n'est pas dans item_effects.
    'category_effects' => [
        'evolution' => 'evolve',
        'standard-balls' => 'catch',
        'special-balls' => 'catch',
    ],

    'type_colors' => [
        'normal' => '#A8A77A', 'fire' => '#EE8130', 'water' => '#6390F0', 'electric' => '#F7D02C',
        'grass' => '#7AC74C', 'ice' => '#96D9D6', 'fighting' => '#C22E28', 'poison' => '#A33EA1',
        'ground' => '#E2BF65', 'flying' => '#A98FF3', 'psychic' => '#F95587', 'bug' => '#A6B91A',
        'rock' => '#B6A136', 'ghost' => '#735797', 'dragon' => '#6F35FC', 'dark' => '#705746',
        'steel' => '#B7B7CE', 'fairy' => '#D685AD',
    ],
];
