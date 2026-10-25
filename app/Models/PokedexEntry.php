<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une espèce vue (et éventuellement capturée) par un personnage.
 * Utilise Character::registerInPokedex() pour l'enregistrer.
 */
class PokedexEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['caught' => 'boolean'];
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function species()
    {
        return $this->belongsTo(PokemonSpecies::class, 'pokemon_species_id');
    }
}
