<?php

/*
|--------------------------------------------------------------------------
| La pêche (étape 15)
|--------------------------------------------------------------------------
| Les Pokémon pêchés viennent de la table wild_encounters, avec method = old_rod, good_rod ou super_rod,
| pour la zone de la case d'eau visée (calque Tiled "encounters", ou "default").
*/

return [

    // Canne (slug de l'objet) => méthode de rencontre et chance que ça morde (en %), comme Essentials
    'rods' => [
        'old-rod' => ['method' => 'old_rod', 'bite' => 45],
        'good-rod' => ['method' => 'good_rod', 'bite' => 70],
        'super-rod' => ['method' => 'super_rod', 'bite' => 95],
    ],

    // Attente avant que ça morde, en millisecondes (tirée au hasard entre les deux)
    'wait_min' => 1500,
    'wait_max' => 4000,

    // Temps pour appuyer sur Action après le "!" (le serveur ajoute une marge pour la connexion)
    'reaction' => 1500,
    'network_margin' => 1500,

];
