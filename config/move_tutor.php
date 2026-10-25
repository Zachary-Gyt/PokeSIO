<?php

/*
|--------------------------------------------------------------------------
| Le Maître des capacités, les surnoms et les objets de terrain (étape 19)
|--------------------------------------------------------------------------
| Après une modification : php artisan config:clear
*/

return [

    // ---- Maître des capacités (PNJ Tiled : action = move_tutor) ----

    // Prix d'une visite (on paie seulement si les capacités changent). 0 = gratuit, comme dans les jeux récents.
    'price' => 0,

    // Les CS (Coupe, Surf...) ne peuvent être oubliées que chez le Maître des capacités,
    // comme dans les premiers jeux. false : elles s'oublient comme les autres capacités.
    'lock_hms' => true,

    // Capacités proposées : celles qu'il connaît, plus celles que son espèce apprend par niveau
    // jusqu'à son niveau actuel. true : aussi celles des formes précédentes (Bulbizarre pour Florizarre).
    'include_pre_evolutions' => true,

    // ---- Surnoms ----

    'nickname_max' => 12,
    // false : comme dans les jeux, un Pokémon reçu d'un autre dresseur (échange) garde son nom
    'rename_traded' => false,

    // ---- Repousse ----

    // "level" : comme dans les jeux récents, seuls les Pokémon sauvages d'un niveau inférieur au premier
    //           Pokémon (en forme) de l'équipe sont repoussés.
    // "all"   : aucune rencontre dans les hautes herbes ni sur l'eau tant que le Repousse agit.
    'repel_mode' => 'level',
];
