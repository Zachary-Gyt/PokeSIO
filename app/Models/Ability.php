<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un talent : Brasier, Torrent, Statik...
 */
class Ability extends Model
{
    protected $guarded = ['id'];

    /** Espèces qui peuvent avoir ce talent */
    public function species()
    {
        return $this->belongsToMany(PokemonSpecies::class, 'pokemon_species_ability')
            ->withPivot('is_hidden');
    }
}
