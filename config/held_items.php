<?php

/*
|--------------------------------------------------------------------------
| Objets tenus (étape 14)
|--------------------------------------------------------------------------
| Un Pokémon peut tenir UN objet (colonne pokemon.held_item_id). Cette table dit ce que fait
| chaque objet en combat. La clé est le slug de l'objet (colonne items.slug).
|
| Un objet tenu qui n'est pas listé ici ne fait rien (une Potion tenue, par exemple), comme dans
| les jeux. Pour ajouter un objet : une ligne ici, puis `php artisan config:clear`.
|
| Effets possibles :
|   exp_share            Multi Exp : le porteur reçoit de l'Exp. même s'il n'a pas combattu
|   exp_boost            x value sur l'Exp. gagnée
|   money_boost          x value sur l'argent gagné contre un dresseur (si le porteur a combattu)
|   leftovers            récupère 1/value des PV max à la fin de chaque tour
|   berry_heal           Baie : +value PV quand les PV tombent à la moitié ou moins (mangée)
|   berry_heal_percent   Baie : +value % des PV max, même condition (mangée)
|   berry_cure           Baie : soigne les statuts listés dans "status" (mangée)
|   type_boost           x value sur la puissance des attaques du type "type"
|   quick_claw           value % de chances d'agir en premier
|   flinch               value % de chances d'apeurer la cible (attaques qui font des dégâts)
|   focus_band           value % de chances de tenir avec 1 PV au lieu d'être K.O.
|   crit_boost           +value au taux de coups critiques
|   flee                 la fuite réussit toujours contre un Pokémon sauvage
|   no_evolve            empêche l'évolution
|
| Étape 22 (objets des générations 2 à 4, valeurs des jeux récents comme Pokémon Essentials v21) :
|   choice               Bandeau / Lunettes / Mouchoir Choix : "stat" x1,5, mais une seule capacité jusqu'au retrait
|   life_orb             Orbe Vie : dégâts x1,3, le porteur perd 1/10 de ses PV à chaque attaque
|   expert_belt          Ceinture Pro : x1,2 sur les attaques super efficaces
|   category_boost       Bandeau Muscle (physique) / Lunettes Sages (spéciale) : x1,1
|   focus_sash           Ceinture Force : avec tous ses PV, le porteur tient avec 1 PV (consommée)
|   shell_bell           Grelot Coque : récupère 1/8 des dégâts infligés
|   black_sludge         Boue Noire : Poison récupère 1/16 des PV par tour, les autres en perdent 1/8
|   evasion              Poudre Claire / Encens Doux : précision de l'adversaire x0,9
|   accuracy             Loupe : précision x value ; Lentille Zoom (after = true) : x1,2 si le porteur agit après
|   metronome            Métronome : +20 % par utilisation de suite de la même capacité (x2 au plus)
|   white_herb           Herbe Blanche : annule les baisses de stats (consommée)
|   power_herb           Herbe Pouvoir : attaque en deux tours lancée tout de suite (consommée)
|   mental_herb          Herbe Mental : soigne l'attirance (sans effet ici : pas d'Attraction)
|   ev_double            Bracelet Macho : EV gagnés x2, Vitesse /2
|   ev_power             Poids, Ceinture... Pouvoir : +value EV de "stat" à chaque combat gagné, Vitesse /2
|   toxic_orb            Orbe Toxique : empoisonne gravement le porteur à la fin du tour
|   flame_orb            Orbe Flamme : brûle le porteur à la fin du tour
|   iron_ball            Balle Fer : Vitesse /2, les attaques Sol touchent même un Pokémon Vol
|   last                 Ralentiqueue / Encens Plein : le porteur agit en dernier
|   sticky_barb          Piquants : le porteur perd 1/8 de ses PV par tour
|   light_clay           Lumargile : Protection et Mur Lumière durent 8 tours
|   big_root             Grosse Racine : les attaques qui drainent soignent x1,3
|   grip_claw            Accro Griffe : les attaques qui piègent durent 7 tours
|   shed_shell           Carapace Mue : le porteur peut toujours être rappelé
|   species_stat         Balle Lumière, Masse Os... : "stats" x value, seulement pour "species"
|   species_crit         Poireau, Poing Chance : +value au taux de critiques, seulement pour "species"
|   species_type_boost   Orbes, Rosée Âme : x value aux attaques des "types", seulement pour "species"
|   resist_berry         Baie Chocco, Pocpoc... : dégâts super efficaces de "type" divisés par 2 (Baie Zalis : Normal)
|   pinch_berry          Baie Lichii... : "stat" +value quand les PV tombent au quart (Baie Lansat : crit, Baie Frista :
|                        "random", Baie Micle : précision, Baie Chérim : agit en premier)
|   confuse_berry        Baie Figuy... : soigne 1/3 des PV au quart, le Pokémon devient confus s'il n'aime pas ce "flavor"
|   leppa_berry          Baie Mepo : +10 PP quand une capacité tombe à 0 PP
|   persim_berry         Baie Kika : soigne la confusion
|   retaliate_berry      Baie Jacoba (physical) / Pommo (special) : l'attaquant perd 1/8 de ses PV
|   enigma_berry         Baie Enigma : soigne 1/4 des PV après une attaque super efficace
|   soothe_bell          Grelot Zen : Bonheur gagné x1,5 (hors combat)
|   encounters           Rune Purifiante / Encens Pur : moins de rencontres (en tête d'équipe, hors combat)
*/

return [

    'items' => [
        // --- Expérience et argent ---
        'exp-share' => ['effect' => 'exp_share'],
        'lucky-egg' => ['effect' => 'exp_boost', 'value' => 1.5],
        'amulet-coin' => ['effect' => 'money_boost', 'value' => 2],

        // --- Soin ---
        'leftovers' => ['effect' => 'leftovers', 'value' => 16],
        'oran-berry' => ['effect' => 'berry_heal', 'value' => 10],
        'sitrus-berry' => ['effect' => 'berry_heal_percent', 'value' => 25],
        'cheri-berry' => ['effect' => 'berry_cure', 'status' => ['paralysis']],
        'chesto-berry' => ['effect' => 'berry_cure', 'status' => ['sleep']],
        'pecha-berry' => ['effect' => 'berry_cure', 'status' => ['poison', 'bad_poison']],
        'rawst-berry' => ['effect' => 'berry_cure', 'status' => ['burn']],
        'aspear-berry' => ['effect' => 'berry_cure', 'status' => ['freeze']],
        'lum-berry' => ['effect' => 'berry_cure', 'status' => ['paralysis', 'sleep', 'poison', 'bad_poison', 'burn', 'freeze']],

        // --- Puissance d'un type : x1,2 ---
        'charcoal' => ['effect' => 'type_boost', 'type' => 'fire', 'value' => 1.2],
        'mystic-water' => ['effect' => 'type_boost', 'type' => 'water', 'value' => 1.2],
        'miracle-seed' => ['effect' => 'type_boost', 'type' => 'grass', 'value' => 1.2],
        'magnet' => ['effect' => 'type_boost', 'type' => 'electric', 'value' => 1.2],
        'never-melt-ice' => ['effect' => 'type_boost', 'type' => 'ice', 'value' => 1.2],
        'black-belt' => ['effect' => 'type_boost', 'type' => 'fighting', 'value' => 1.2],
        'poison-barb' => ['effect' => 'type_boost', 'type' => 'poison', 'value' => 1.2],
        'soft-sand' => ['effect' => 'type_boost', 'type' => 'ground', 'value' => 1.2],
        'sharp-beak' => ['effect' => 'type_boost', 'type' => 'flying', 'value' => 1.2],
        'twisted-spoon' => ['effect' => 'type_boost', 'type' => 'psychic', 'value' => 1.2],
        'silver-powder' => ['effect' => 'type_boost', 'type' => 'bug', 'value' => 1.2],
        'hard-stone' => ['effect' => 'type_boost', 'type' => 'rock', 'value' => 1.2],
        'spell-tag' => ['effect' => 'type_boost', 'type' => 'ghost', 'value' => 1.2],
        'dragon-fang' => ['effect' => 'type_boost', 'type' => 'dragon', 'value' => 1.2],
        'silk-scarf' => ['effect' => 'type_boost', 'type' => 'normal', 'value' => 1.2],
        'black-glasses' => ['effect' => 'type_boost', 'type' => 'dark', 'value' => 1.2],
        'metal-coat' => ['effect' => 'type_boost', 'type' => 'steel', 'value' => 1.2],

        // --- Combat ---
        'quick-claw' => ['effect' => 'quick_claw', 'value' => 20],
        'kings-rock' => ['effect' => 'flinch', 'value' => 10],
        'focus-band' => ['effect' => 'focus_band', 'value' => 10],
        'scope-lens' => ['effect' => 'crit_boost', 'value' => 1],
        'smoke-ball' => ['effect' => 'flee'],

        // --- Hors combat ---
        'everstone' => ['effect' => 'no_evolve'],

        // =====================================================================
        // Étape 22 : générations 2 à 4
        // =====================================================================

        // --- Expérience, argent, Bonheur, rencontres ---
        'luck-incense' => ['effect' => 'money_boost', 'value' => 2],
        'soothe-bell' => ['effect' => 'soothe_bell'],
        'cleanse-tag' => ['effect' => 'encounters'],
        'pure-incense' => ['effect' => 'encounters'],

        // --- Soin ---
        'berry-juice' => ['effect' => 'berry_heal', 'value' => 20],
        'shell-bell' => ['effect' => 'shell_bell', 'value' => 8],
        'black-sludge' => ['effect' => 'black_sludge'],
        'leppa-berry' => ['effect' => 'leppa_berry', 'value' => 10],
        'persim-berry' => ['effect' => 'persim_berry'],
        'figy-berry' => ['effect' => 'confuse_berry', 'flavor' => 'attack'],      // épicé : déplaît si la nature baisse l'Attaque
        'wiki-berry' => ['effect' => 'confuse_berry', 'flavor' => 'sp_attack'],   // sec
        'mago-berry' => ['effect' => 'confuse_berry', 'flavor' => 'speed'],       // sucré
        'aguav-berry' => ['effect' => 'confuse_berry', 'flavor' => 'sp_defense'], // amer
        'iapapa-berry' => ['effect' => 'confuse_berry', 'flavor' => 'defense'],   // acide
        'enigma-berry' => ['effect' => 'enigma_berry'],

        // --- Baies de stress (au quart des PV) ---
        'liechi-berry' => ['effect' => 'pinch_berry', 'stat' => 'attack', 'value' => 1],
        'ganlon-berry' => ['effect' => 'pinch_berry', 'stat' => 'defense', 'value' => 1],
        'salac-berry' => ['effect' => 'pinch_berry', 'stat' => 'speed', 'value' => 1],
        'petaya-berry' => ['effect' => 'pinch_berry', 'stat' => 'sp_attack', 'value' => 1],
        'apicot-berry' => ['effect' => 'pinch_berry', 'stat' => 'sp_defense', 'value' => 1],
        'lansat-berry' => ['effect' => 'pinch_berry', 'stat' => 'crit', 'value' => 2],
        'starf-berry' => ['effect' => 'pinch_berry', 'stat' => 'random', 'value' => 2],
        'micle-berry' => ['effect' => 'pinch_berry', 'stat' => 'micle', 'value' => 1.2],
        'custap-berry' => ['effect' => 'pinch_berry', 'stat' => 'custap'],

        // --- Baies qui affaiblissent une attaque super efficace ---
        'occa-berry' => ['effect' => 'resist_berry', 'type' => 'fire'],
        'passho-berry' => ['effect' => 'resist_berry', 'type' => 'water'],
        'wacan-berry' => ['effect' => 'resist_berry', 'type' => 'electric'],
        'rindo-berry' => ['effect' => 'resist_berry', 'type' => 'grass'],
        'yache-berry' => ['effect' => 'resist_berry', 'type' => 'ice'],
        'chople-berry' => ['effect' => 'resist_berry', 'type' => 'fighting'],
        'kebia-berry' => ['effect' => 'resist_berry', 'type' => 'poison'],
        'shuca-berry' => ['effect' => 'resist_berry', 'type' => 'ground'],
        'coba-berry' => ['effect' => 'resist_berry', 'type' => 'flying'],
        'payapa-berry' => ['effect' => 'resist_berry', 'type' => 'psychic'],
        'tanga-berry' => ['effect' => 'resist_berry', 'type' => 'bug'],
        'charti-berry' => ['effect' => 'resist_berry', 'type' => 'rock'],
        'kasib-berry' => ['effect' => 'resist_berry', 'type' => 'ghost'],
        'haban-berry' => ['effect' => 'resist_berry', 'type' => 'dragon'],
        'colbur-berry' => ['effect' => 'resist_berry', 'type' => 'dark'],
        'babiri-berry' => ['effect' => 'resist_berry', 'type' => 'steel'],
        'chilan-berry' => ['effect' => 'resist_berry', 'type' => 'normal'],      // Normal : même pas super efficace
        'jaboca-berry' => ['effect' => 'retaliate_berry', 'category' => 'physical'],
        'rowap-berry' => ['effect' => 'retaliate_berry', 'category' => 'special'],

        // --- Puissance ---
        'choice-band' => ['effect' => 'choice', 'stat' => 'attack'],
        'choice-specs' => ['effect' => 'choice', 'stat' => 'sp_attack'],
        'choice-scarf' => ['effect' => 'choice', 'stat' => 'speed'],
        'life-orb' => ['effect' => 'life_orb', 'value' => 1.3],
        'expert-belt' => ['effect' => 'expert_belt', 'value' => 1.2],
        'muscle-band' => ['effect' => 'category_boost', 'category' => 'physical', 'value' => 1.1],
        'wise-glasses' => ['effect' => 'category_boost', 'category' => 'special', 'value' => 1.1],
        'metronome' => ['effect' => 'metronome'],
        // Encens et Plaques : x1,2 sur un type (comme le Charbon)
        'sea-incense' => ['effect' => 'type_boost', 'type' => 'water', 'value' => 1.2],
        'wave-incense' => ['effect' => 'type_boost', 'type' => 'water', 'value' => 1.2],
        'rose-incense' => ['effect' => 'type_boost', 'type' => 'grass', 'value' => 1.2],
        'odd-incense' => ['effect' => 'type_boost', 'type' => 'psychic', 'value' => 1.2],
        'rock-incense' => ['effect' => 'type_boost', 'type' => 'rock', 'value' => 1.2],
        'flame-plate' => ['effect' => 'type_boost', 'type' => 'fire', 'value' => 1.2],
        'splash-plate' => ['effect' => 'type_boost', 'type' => 'water', 'value' => 1.2],
        'zap-plate' => ['effect' => 'type_boost', 'type' => 'electric', 'value' => 1.2],
        'meadow-plate' => ['effect' => 'type_boost', 'type' => 'grass', 'value' => 1.2],
        'icicle-plate' => ['effect' => 'type_boost', 'type' => 'ice', 'value' => 1.2],
        'fist-plate' => ['effect' => 'type_boost', 'type' => 'fighting', 'value' => 1.2],
        'toxic-plate' => ['effect' => 'type_boost', 'type' => 'poison', 'value' => 1.2],
        'earth-plate' => ['effect' => 'type_boost', 'type' => 'ground', 'value' => 1.2],
        'sky-plate' => ['effect' => 'type_boost', 'type' => 'flying', 'value' => 1.2],
        'mind-plate' => ['effect' => 'type_boost', 'type' => 'psychic', 'value' => 1.2],
        'insect-plate' => ['effect' => 'type_boost', 'type' => 'bug', 'value' => 1.2],
        'stone-plate' => ['effect' => 'type_boost', 'type' => 'rock', 'value' => 1.2],
        'spooky-plate' => ['effect' => 'type_boost', 'type' => 'ghost', 'value' => 1.2],
        'draco-plate' => ['effect' => 'type_boost', 'type' => 'dragon', 'value' => 1.2],
        'dread-plate' => ['effect' => 'type_boost', 'type' => 'dark', 'value' => 1.2],
        'iron-plate' => ['effect' => 'type_boost', 'type' => 'steel', 'value' => 1.2],

        // --- Précision, coups critiques, peur ---
        'bright-powder' => ['effect' => 'evasion', 'value' => 0.9],
        'lax-incense' => ['effect' => 'evasion', 'value' => 0.9],
        'wide-lens' => ['effect' => 'accuracy', 'value' => 1.1],
        'zoom-lens' => ['effect' => 'accuracy', 'value' => 1.2, 'after' => true],
        'razor-claw' => ['effect' => 'crit_boost', 'value' => 1],
        'razor-fang' => ['effect' => 'flinch', 'value' => 10],

        // --- Herbes, Ceinture Force ---
        'focus-sash' => ['effect' => 'focus_sash'],
        'white-herb' => ['effect' => 'white_herb'],
        'power-herb' => ['effect' => 'power_herb'],
        'mental-herb' => ['effect' => 'mental_herb'],

        // --- Entraînement (EV) ---
        'macho-brace' => ['effect' => 'ev_double'],
        'power-weight' => ['effect' => 'ev_power', 'stat' => 'hp', 'value' => 8],
        'power-bracer' => ['effect' => 'ev_power', 'stat' => 'attack', 'value' => 8],
        'power-belt' => ['effect' => 'ev_power', 'stat' => 'defense', 'value' => 8],
        'power-lens' => ['effect' => 'ev_power', 'stat' => 'sp_attack', 'value' => 8],
        'power-band' => ['effect' => 'ev_power', 'stat' => 'sp_defense', 'value' => 8],
        'power-anklet' => ['effect' => 'ev_power', 'stat' => 'speed', 'value' => 8],

        // --- Objets à double tranchant ---
        'toxic-orb' => ['effect' => 'toxic_orb'],
        'flame-orb' => ['effect' => 'flame_orb'],
        'iron-ball' => ['effect' => 'iron_ball'],
        'lagging-tail' => ['effect' => 'last'],
        'full-incense' => ['effect' => 'last'],
        'sticky-barb' => ['effect' => 'sticky_barb'],

        // --- Durées ---
        'light-clay' => ['effect' => 'light_clay', 'value' => 8],
        'big-root' => ['effect' => 'big_root', 'value' => 1.3],
        'grip-claw' => ['effect' => 'grip_claw', 'value' => 7],
        'shed-shell' => ['effect' => 'shed_shell'],

        // --- Objets réservés à une espèce ---
        'light-ball' => ['effect' => 'species_stat', 'species' => ['pikachu'], 'stats' => ['attack', 'sp_attack'], 'value' => 2],
        'thick-club' => ['effect' => 'species_stat', 'species' => ['cubone', 'marowak'], 'stats' => ['attack'], 'value' => 2],
        'metal-powder' => ['effect' => 'species_stat', 'species' => ['ditto'], 'stats' => ['defense'], 'value' => 2],
        'quick-powder' => ['effect' => 'species_stat', 'species' => ['ditto'], 'stats' => ['speed'], 'value' => 2],
        'deep-sea-tooth' => ['effect' => 'species_stat', 'species' => ['clamperl'], 'stats' => ['sp_attack'], 'value' => 2],
        'deep-sea-scale' => ['effect' => 'species_stat', 'species' => ['clamperl'], 'stats' => ['sp_defense'], 'value' => 2],
        'stick' => ['effect' => 'species_crit', 'species' => ['farfetchd'], 'value' => 2],
        'lucky-punch' => ['effect' => 'species_crit', 'species' => ['chansey'], 'value' => 2],
        'soul-dew' => ['effect' => 'species_type_boost', 'species' => ['latias', 'latios'], 'types' => ['dragon', 'psychic'], 'value' => 1.2],
        'adamant-orb' => ['effect' => 'species_type_boost', 'species' => ['dialga'], 'types' => ['steel', 'dragon'], 'value' => 1.2],
        'lustrous-orb' => ['effect' => 'species_type_boost', 'species' => ['palkia'], 'types' => ['water', 'dragon'], 'value' => 1.2],
        'griseous-orb' => ['effect' => 'species_type_boost', 'species' => ['giratina-altered', 'giratina-origin', 'giratina'], 'types' => ['ghost', 'dragon'], 'value' => 1.2],

        // Sans effet pour l'instant (météo, Arceus, Concours...) : Roche Chaude, Roche Humide, Roche Lisse, Roche Glace,
        // Foulards, Lettres. Tenus, ils ne font rien (comme dans les jeux sans le système qui va avec).
    ],

];
