<?php

/*
|--------------------------------------------------------------------------
| Les buissons à Baies (étape 22)
|--------------------------------------------------------------------------
| Dans Tiled : un calque d'objets nommé "berries", un rectangle de 32 x 32 par buisson, avec les propriétés
|   berry    (obligatoire) slug de la Baie : oran-berry, sitrus-berry, cheri-berry...
|   id       (facultatif)  nom unique du buisson sur la carte (sinon "x_y")
|   quantity (facultatif)  nombre de Baies fixe à chaque récolte (sinon, au hasard entre "yield")
|   hours    (facultatif)  temps de repousse en heures (sinon "hours" ci-dessous)
|
| Comme dans Rubis / Saphir / Émeraude : chaque joueur a SES buissons. Une fois cueilli, le buisson repousse
| tout seul (plantée, pousse, grandit, en fleurs, puis des Baies) ; le temps est réel, même jeu fermé.
| Après une modification : php artisan config:clear
*/

return [

    // Temps de repousse, en heures
    'hours' => 24,

    // Nombre de Baies à chaque récolte : au hasard entre ces deux valeurs
    'yield' => [1, 3],
];
