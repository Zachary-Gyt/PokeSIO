<?php

/*
|--------------------------------------------------------------------------
| La Ligue Pokémon et le Panthéon (étape 27)
|--------------------------------------------------------------------------
| Après une modification : php artisan config:clear
*/

return [

    // Badges nécessaires pour entrer (le garde du hall : propriété Tiled requires_badges = 8)
    'badges' => 8,

    // Les dresseurs de la Ligue (slugs de la table trainers), DANS L'ORDRE : le Conseil 4, puis le Maître.
    // Il faut les battre à la suite ; une défaite fait tout recommencer depuis le premier.
    'members' => ['ligue_olga', 'ligue_aldo', 'ligue_agatha', 'ligue_peter', 'ligue_maitre'],

    // Les cartes de la Ligue : sauvegarder AILLEURS (sortir, Vol, Téléport...) fait aussi tout recommencer
    'maps' => ['ligue_hall', 'ligue_salle_1', 'ligue_salle_2', 'ligue_salle_3', 'ligue_salle_4', 'ligue_salle_maitre'],

    // Après le Panthéon : le joueur se retrouve ici (comme le retour à la maison dans les jeux)
    'after' => ['map' => 'ligue_hall', 'x' => 7, 'y' => 9, 'facing' => 'down'],

    // Après la première victoire, la Ligue devient plus forte (comme Platine) : trainer_pokemon.version = 1
    // (s'il n'y a pas d'équipe "version 1", on garde l'équipe d'origine)
    'rematch_version' => 1,
];
