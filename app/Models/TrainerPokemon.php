<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un Pokémon de l'équipe d'un dresseur PNJ (modèle, pas une instance possédée).
 */
class TrainerPokemon extends Model
{
    protected $table = 'trainer_pokemon';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evs' => 'array',       // {"attack": 252, "speed": 252}
            'move_ids' => 'array',  // [33, 45] ou null
            'is_shiny' => 'boolean',
        ];
    }

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }

    public function species()
    {
        return $this->belongsTo(PokemonSpecies::class, 'pokemon_species_id');
    }

    public function nature()
    {
        return $this->belongsTo(Nature::class);
    }

    public function ability()
    {
        return $this->belongsTo(Ability::class);
    }

    public function heldItem()
    {
        return $this->belongsTo(Item::class, 'held_item_id');
    }

    /** Ses attaques : celles choisies, sinon les 4 dernières apprises par niveau */
    public function resolveMoves()
    {
        if (! empty($this->move_ids)) {
            return Move::whereIn('id', $this->move_ids)->get();
        }

        return $this->species->levelUpMovesUpTo($this->level)->unique('id')->take(-PokemonMove::MAX_MOVES)->values();
    }
}
