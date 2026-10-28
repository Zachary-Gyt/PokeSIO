<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Une des 4 attaques connues par un Pokémon, avec ses PP restants.
 */
class PokemonMove extends Model
{
    public const MAX_MOVES = 4;
    public const MAX_PP_UPS = 3;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** Chaque capacité qui arrive dans les 4 emplacements est retenue (Résumé : la reprendre plus tard) */
    protected static function booted(): void
    {
        static::saved(function (PokemonMove $pm) {
            if ($pm->wasRecentlyCreated || $pm->wasChanged('move_id')) {
                self::remember((int) $pm->pokemon_id, (int) $pm->move_id);
            }
        });
    }

    /** Note qu'un Pokémon a appris cette capacité (sans doublon) */
    public static function remember(int $pokemonId, int $moveId): void
    {
        DB::table('pokemon_learned_moves')->insertOrIgnore(['pokemon_id' => $pokemonId, 'move_id' => $moveId]);
    }

    public function pokemon()
    {
        return $this->belongsTo(Pokemon::class);
    }

    public function move()
    {
        return $this->belongsTo(Move::class);
    }

    /** PP max : +20 % de la base par PP Plus utilisé */
    protected function maxPp(): Attribute
    {
        return Attribute::get(fn () => intdiv($this->move->pp * (5 + $this->pp_ups), 5));
    }

    public function hasPp(): bool
    {
        return $this->current_pp > 0;
    }
}
