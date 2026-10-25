<?php

/*
|--------------------------------------------------------------------------
| La Tour de Combat (étape 28), comme dans Platine
|--------------------------------------------------------------------------
| Après une modification : php artisan config:clear
*/

return [

    'team_size' => 3,          // Pokémon choisis dans l'équipe
    /*
    | Le NIVEAU des combats : il commence à 'start' et monte de 'step' tous les 'every' combats (une série),
    | jusqu'à 'max'. Les adversaires sont à ce niveau ; tes Pokémon plus forts y sont ramenés le temps du
    | combat (les plus faibles restent tels quels). Ici : 20, puis 25 au 8e combat, 30 au 15e... 100 au 113e.
    | Pour un niveau fixe (comme Platine) : 'level' => 50,
    */
    'level' => ['start' => 20, 'step' => 5, 'every' => 7, 'max' => 100],
    'set_length' => 7,         // combats par série ; après le 7e, la série est validée (on peut faire une pause)

    // Interdits à la Tour (comme dans les jeux) : les Pokémon légendaires et fabuleux, et deux fois la même espèce
    // ou le même objet tenu (clauses espèce et objet)
    'banned' => ['mewtwo', 'mew', 'lugia', 'ho-oh', 'celebi', 'kyogre', 'groudon', 'rayquaza', 'jirachi', 'deoxys',
        'dialga', 'palkia', 'giratina', 'phione', 'manaphy', 'darkrai', 'shaymin', 'arceus'],

    // Argent gagné par victoire : base + par_victoire x (série en cours), le 7e combat d'une série vaut double
    'money' => ['base' => 1000, 'per_win' => 100, 'max' => 10000],

    /*
    | Les adversaires, de plus en plus forts avec la série (paliers par nombre de victoires déjà obtenues).
    | bst : total des stats de base des Pokémon tirés au sort ; iv : leurs IV ; ai : intelligence (1 à 3).
    | Les Pokémon tirés sont des formes finales (ou sans évolution), jamais légendaires.
    */
    'tiers' => [
        0 => ['bst' => [300, 460], 'iv' => 8, 'ai' => 2],
        7 => ['bst' => [400, 500], 'iv' => 14, 'ai' => 2],
        14 => ['bst' => [440, 530], 'iv' => 20, 'ai' => 3],
        21 => ['bst' => [470, 560], 'iv' => 26, 'ai' => 3],
        35 => ['bst' => [490, 600], 'iv' => 31, 'ai' => 3],
    ],

    // Objets tenus possibles pour les adversaires (seulement ceux qui existent dans ta base)
    'held_items' => ['sitrus-berry', 'leftovers', 'lum-berry', 'quick-claw', 'kings-rock', 'bright-powder', 'focus-band', 'scope-lens', 'shell-bell', 'oran-berry'],

    // Les dresseurs : classe (affichée) => sprite de combat (public/game/sprites/trainers/battle)
    'classes' => [
        'Topdresseur' => 'cooltrainer_m', 'Topdresseuse' => 'cooltrainer_f', 'Karatéka' => 'blackbelt', 'Montagnard' => 'hiker',
        'Médium' => 'psychic_m', 'Kinésiste' => 'psychic_f', 'Gentleman' => 'gentleman', 'Dame' => 'lady', 'Scientifique' => 'scientist',
        'Ornithologue' => 'birdkeeper', 'Rockeur' => 'rocker', 'Motard' => 'biker', 'Éleveuse' => 'pokemonbreeder',
        'Ranger' => 'pokemonranger_m', 'Rangeuse' => 'pokemonranger_f', 'Canon' => 'beauty', 'Pêcheur' => 'fisherman', 'Dresseur' => 'tamer',
    ],
    'names' => ['Albert', 'Bastien', 'Camille', 'Damien', 'Élise', 'Fanny', 'Gaspard', 'Hugo', 'Inès', 'Jules', 'Karim', 'Léa', 'Mathis',
        'Nina', 'Oscar', 'Pauline', 'Quentin', 'Rose', 'Samuel', 'Théa', 'Ugo', 'Valentin', 'Wendy', 'Yanis', 'Zoé'],
    'intros' => ['Ta série s\'arrête ici !', 'Je me suis entraîné des mois pour ce combat !', 'Montre-moi ce que vaut ton équipe !',
        'La Tour de Combat, c\'est ma maison.', 'On dit que tu es fort... Vérifions !', 'Mes Pokémon sont prêts. Et les tiens ?'],
    'defeats' => ['Bien joué...', 'Tu es vraiment fort !', 'J\'ai perdu... Continue comme ça !', 'Quelle équipe !', 'Je reviendrai plus fort.'],

    // Le Maître de la Tour (Palmer dans Platine) : au 21e combat (argent), puis au 49e et tous les 49 ensuite (or)
    'tycoon' => [
        'name' => 'Palmer',
        'class' => 'Maître de la Tour',
        'sprite' => 'champion',
        'silver_at' => 21,
        'gold_at' => 49,
        'silver' => [['rhyperior', 'sitrus-berry'], ['milotic', 'leftovers'], ['dragonite', 'lum-berry']],
        'gold' => [['rhyperior', 'quick-claw'], ['milotic', 'leftovers'], ['regigigas', 'lum-berry']],
        'intro' => 'Je suis Palmer, le Maître de la Tour ! Ta série m\'impressionne... Voyons si elle résiste à mes Pokémon !',
        'defeat' => 'Splendide ! Tu mérites ta réputation !',
    ],
];
