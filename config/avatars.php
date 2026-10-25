<?php

/*
|--------------------------------------------------------------------------
| Les personnages jouables (étape 17)
|--------------------------------------------------------------------------
| À la création, le joueur choisit l'un de ces personnages (colonne characters.avatar).
| La clé sert de nom aux fichiers du jeu :
|   public/game/sprites/player/{clé}_walk.png   marche   (4 x 4 images : 128 x 192 ou 192 x 192...)
|                              {clé}_run.png    course
|                              {clé}_surf.png   Surf
|                              {clé}_bike.png   Bicyclette
|                              {clé}_fish.png   pêche (4 x 4 images, les pieds 16 pixels au-dessus du bas)
|                              {clé}_back.png   dos, en combat (5 images côte à côte)
|   public/game/sprites/trainers/battle/player_{clé}.png   face, en combat / Carte Dresseur
|
| "files" : les fichiers d'Essentials que copie `php artisan game:essentials-assets`
| (cherchés dans Graphics\Characters, Graphics\Trainers, puis Graphics).
| Une image déjà présente dans public/game n'est jamais remplacée.
|
| Pour ajouter un personnage : une entrée ici, ses images, puis `php artisan config:clear`.
*/

return [

    'red' => [
        'name' => 'Red',
        'gender' => 'male',
        'files' => [
            'walk' => 'trainer_POKEMONTRAINER_Red', 'run' => 'boy_run', 'surf' => 'boy_surf', 'bike' => 'boy_bike',
            'fish' => 'boy_fish_offset', 'back' => 'POKEMONTRAINER_Red_back', 'front' => 'POKEMONTRAINER_Red',
        ],
        'legacy' => 'male',     // anciens fichiers (male_walk.png, player_male.png...) repris s'ils existent
    ],

    'leaf' => [
        'name' => 'Leaf',
        'gender' => 'female',
        'files' => [
            'walk' => 'trainer_POKEMONTRAINER_Leaf', 'run' => 'girl_run', 'surf' => 'girl_surf', 'bike' => 'girl_bike',
            'fish' => 'girl_fish_offset', 'back' => 'POKEMONTRAINER_Leaf_back', 'front' => 'POKEMONTRAINER_Leaf',
        ],
        'legacy' => 'female',
    ],

    'brendan' => [
        'name' => 'Brendan',
        'gender' => 'male',
        'files' => [
            'walk' => 'rsBrendan', 'run' => 'rsBrendan_run', 'surf' => 'rsBrendan_surf', 'bike' => 'rsBrendan_bike',
            'fish' => 'rsBrendan_fish_offset', 'back' => 'POKEMONTRAINER_Brendan_back', 'front' => 'POKEMONTRAINER_Brendan',
        ],
    ],

    'may' => [
        'name' => 'May',
        'gender' => 'female',
        'files' => [
            'walk' => 'rsMay', 'run' => 'rsMay_run', 'surf' => 'rsMay_surf', 'bike' => 'rsMay_bike',
            'fish' => 'rsMay_fish_offset', 'back' => 'POKEMONTRAINER_May_back', 'front' => 'POKEMONTRAINER_May',
        ],
    ],

    // Ajoutés après l'étape 24 : les héros de Heartgold / Soulsilver
    'ethan' => [
        'name' => 'Ethan',
        'gender' => 'male',
        'files' => [
            'walk' => 'trainer_TRAINER_Ethan', 'run' => 'ethan_run', 'surf' => 'ethan_surf', 'bike' => 'ethan_bike',
            'fish' => 'ethan_fish_offset', 'back' => 'TRAINER_Ethan_back', 'front' => 'Trainer_Ethan_Battler',
        ],
    ],

    'lyra' => [
        'name' => 'Lyra',
        'gender' => 'female',
        'files' => [
            'walk' => 'trainer_TRAINER_Lyra', 'run' => 'lyra_run', 'surf' => 'lyra_surf', 'bike' => 'lyra_bike',
            'fish' => 'lyra_fish_offset', 'back' => 'TRAINER_Maribel_back', 'front' => 'Trainer_Lyra_Battler',
        ],
    ],

];
