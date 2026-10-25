<?php

namespace App\Battle;

use RuntimeException;

/**
 * Action refusée par le moteur (attaque sans PP, Pokémon K.O. choisi, fuite d'un combat de dresseur...).
 * Le message est affiché au joueur ; le tour n'est PAS consommé.
 */
class InvalidAction extends RuntimeException
{
}
