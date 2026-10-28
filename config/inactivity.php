<?php

/*
|--------------------------------------------------------------------------
| Déconnexion pour inactivité
|--------------------------------------------------------------------------
| Sans aucune action (touche, clic, toucher) pendant "minutes", le joueur est renvoyé à l'écran de sélection
| des personnages, après un avertissement "warning_seconds" secondes avant. 0 = jamais.
| Pas de déconnexion pendant un combat contre un autre joueur ou un échange.
| Après une modification : php artisan config:clear
*/

return [
    'minutes' => 5,
    'warning_seconds' => 60,
];
