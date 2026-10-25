<?php

/*
|--------------------------------------------------------------------------
| Réglages du jeu
|--------------------------------------------------------------------------
| Tout ce qu'un game designer voudrait pouvoir ajuster sans toucher au code.
| Accès dans le code : config('game.starting_money')
*/

return [

    // ---- Création de personnage ------------------------------------------

    'name_max_length' => 12,          // comme dans les jeux
    'starting_level' => 5,            // niveau du starter
    'starting_money' => 3000,

    // Point de départ : carte Tiled (fichier pallet_town.tmj) et position en tuiles
    'starting_map' => 'cremieu_player_bedroom',
	'starting_x' => 4,
	'starting_y' => 6,
	'starting_facing' => 'down',
    'starting_location_name' => 'Crémieu', // "lieu de rencontre" affiché sur la fiche du starter

    // Ball dans laquelle le starter est remis
    'starter_ball' => 'poke-ball',

    // Objets dans le sac au départ : slug => quantité
    'starting_items' => [
        'potion' => 3,
        'poke-ball' => 5,
    ],

    // ---- Génération des Pokémon ------------------------------------------

    'shiny_odds' => 4096,             // 1 chance sur 4096 (taux moderne)
	'encounter_chance' => 10,

    // ---- Régions ---------------------------------------------------------

	// Fuseau horaire du jeu (l'heure de Paris, même si le serveur est réglé en UTC)
    'timezone' => 'Europe/Paris',
    // Début de chaque moment de la journée (heures). Comme Or / Argent : matin 4 h, jour 10 h, nuit 20 h.
    'time' => ['morning' => 4, 'day' => 10, 'night' => 20],

    // Nom affiché pour chaque génération (onglets du choix de starter, etc.)
    'regions' => [
        1 => 'Kanto',
        2 => 'Johto',
        3 => 'Hoenn',
        4 => 'Sinnoh',
        5 => 'Unys',
        6 => 'Kalos',
        7 => 'Alola',
        8 => 'Galar',
        9 => 'Paldea',
    ],
];
