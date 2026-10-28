<?php

/*
|--------------------------------------------------------------------------
| Le chat et les groupes
|--------------------------------------------------------------------------
| Après une modification : php artisan config:clear
*/

return [

    // Longueur maximale d'un message
    'max_length' => 120,

    // Messages gardés pour ceux qui arrivent (par carte, global, par groupe, messages privés de chaque joueur)
    'history' => 20,

    // Anti-spam : au plus "messages" messages toutes les "seconds" secondes (tous canaux confondus)
    'rate' => ['messages' => 5, 'seconds' => 10],

    // Joueurs dans un groupe, chef compris
    'group_size' => 4,

    // Une invitation dans un groupe expire au bout de ... secondes
    'invitation_seconds' => 60,

    // Un joueur est "connecté" si le jeu a sauvegardé depuis moins de ... secondes (sauvegarde toutes les 30 s)
    'online_seconds' => 120,
];
