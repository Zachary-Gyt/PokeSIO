<?php

/*
|--------------------------------------------------------------------------
| La Banque
|--------------------------------------------------------------------------
| Un PNJ ou un panneau (distributeur) avec la propriété Tiled action = bank.
| Après une modification : php artisan config:clear
*/

return [

    // Intérêts par jour (0.10 = +10 % par jour). Ils sont COMPOSÉS (les intérêts rapportent aussi) et comptés
    // au prorata du temps écoulé, même quand le joueur n'est pas connecté : 12 h = un peu moins de +5 %.
    'daily_rate' => 0.10,

    // Le compte ne peut pas dépasser ce montant (il ne rapporte plus rien une fois plein)
    'max_balance' => 9_999_999,

    // Le plus petit dépôt accepté
    'min_deposit' => 1,

];
