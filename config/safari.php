<?php

/*
|--------------------------------------------------------------------------
| Le Parc Safari (étape 15)
|--------------------------------------------------------------------------
| Dans Tiled :
|   - les cartes du Parc ont la propriété de carte "safari" (booléen, cochée) ;
|   - le gardien de l'entrée est un PNJ avec action = safari, to_map, to_x, to_y
|     (où le joueur arrive dans le Parc).
| En sortant du Parc (une carte sans "safari"), la partie de Safari se termine.
*/

return [

    'price' => 500,      // prix d'entrée
    'balls' => 30,       // Safari Balls données à l'entrée
    'steps' => 500,      // nombre de pas (0 = illimité)

    // Bonus de la Safari Ball (x10 : 15 = x1,5, comme une Super Ball)
    'ball_bonus' => 15,

    // Chance de fuite à chaque tour, en % par point de Vitesse du Pokémon (x2 en colère, /4 en train de manger).
    // 0.4 : Vitesse 50 -> 20 %, Vitesse 100 -> 40 %. Toujours au plus 90 %.
    'flee_per_speed' => 0.4,

];
