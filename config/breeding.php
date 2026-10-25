<?php

/*
|--------------------------------------------------------------------------
| La Pension et les Œufs (étape 18)
|--------------------------------------------------------------------------
| Après une modification : php artisan config:clear
*/

return [

    // Une seule Pension, 2 places par joueur
    'slots' => 2,

    // Prix pour reprendre un Pokémon : (niveaux gagnés + 1) x price_per_level
    'price_per_level' => 100,

    // Points d'expérience gagnés par pas, par Pokémon confié
    'exp_per_step' => 1,

    // Tous les "egg_check_steps" pas, la Pension tente de produire un Œuf
    'egg_check_steps' => 256,

    // Chance (en %) d'avoir un Œuf à chaque tentative, selon l'entente des deux Pokémon
    //   same_species_other_ot : même espèce, dresseurs d'origine différents
    //   same_species / other_species_other_ot : l'un ou l'autre
    //   other_species : espèces différentes, même dresseur d'origine
    'chances' => [
        'very_well' => 70,   // même espèce, dresseurs différents
        'well' => 50,        // même espèce, ou espèces différentes de dresseurs différents
        'little' => 20,      // espèces différentes, même dresseur
    ],
    // Avec le Charme Ovale dans le Sac
    'chances_oval_charm' => [
        'very_well' => 88,
        'well' => 80,
        'little' => 40,
    ],

    // Pas par cycle d'éclosion (Bulbizarre : 20 cycles x 256 = 5 120 pas)
    'steps_per_cycle' => 256,

    // Talents qui font éclore 2 fois plus vite quand le Pokémon est dans l'équipe
    'fast_hatch_abilities' => ['flame-body', 'magma-armor', 'steam-engine'],

    // Hérédité (règles des jeux récents)
    'inherited_ivs' => 3,              // IV transmis par les parents
    'inherited_ivs_destiny_knot' => 5, // ... si un parent tient le Nœud Destin
    'hidden_ability_chance' => 60,     // % : la mère (ou le parent qui n'est pas Métamorph) transmet son talent caché
    'ability_slot_chance' => 80,       // % : ... sinon son talent normal (1er ou 2e)

    // Chromatiques : nombre de tirages (1 = chance normale, config game.shiny_odds)
    'shiny_rolls_masuda' => 6,         // méthode Masuda : parents de dresseurs d'origine différents

    // Objets (slug de la table items)
    'items' => [
        'oval_charm' => 'oval-charm',
        'destiny_knot' => 'destiny-knot',
        'everstone' => 'everstone',
    ],

    // Balls qui ne se transmettent pas (le bébé naît alors dans une Poké Ball)
    'no_inherit_balls' => ['master-ball', 'cherish-ball', 'safari-ball'],

    // Anti-triche : le navigateur envoie les pas par paquets ; au plus "max_steps_per_second"
    // depuis le dernier envoi, et "max_batch" par envoi
    'max_steps_per_second' => 10,
    'max_batch' => 64,

    // Les noms des groupes d'Œuf (affichés dans le Wiki)
    'groups' => [
        'monster' => 'Monstrueux',
        'water1' => 'Aquatique 1',
        'water2' => 'Aquatique 2',
        'water3' => 'Aquatique 3',
        'bug' => 'Insectoïde',
        'flying' => 'Aérien',
        'ground' => 'Terrestre',
        'fairy' => 'Féerique',
        'plant' => 'Végétal',
        'humanshape' => 'Humanoïde',
        'mineral' => 'Minéral',
        'indeterminate' => 'Amorphe',
        'dragon' => 'Draconique',
        'ditto' => 'Métamorph',
        'no-eggs' => 'Inconnu',
    ],
];
