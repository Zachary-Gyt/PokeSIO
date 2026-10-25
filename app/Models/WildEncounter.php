<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Une entrée de la table de rencontres d'une zone :
 * "Route 1, herbe : Rattata niv. 2 à 4, poids 45".
 */
class WildEncounter extends Model
{
    protected $guarded = ['id'];

    public function species()
    {
        return $this->belongsTo(PokemonSpecies::class, 'pokemon_species_id');
    }

    /** WildEncounter::at('route_1', 'default', 'grass')->get() */
    public function scopeAt(Builder $query, string $mapKey, string $zone = 'default', string $method = 'grass'): void
    {
        $query->where('map_key', $mapKey)
            ->where('zone', $zone)
            ->where('method', $method)
            ->where(fn ($q) => $q->whereNull('time_of_day')->orWhere('time_of_day', self::currentTimeOfDay()));
    }

    /**
     * Tire une rencontre au hasard, en respectant les poids.
     * Renvoie null si la zone n'a aucune rencontre.
     */
    public static function roll(string $mapKey, string $zone = 'default', string $method = 'grass'): ?self
    {
        $encounters = self::at($mapKey, $zone, $method)
            ->whereHas('species', fn ($q) => $q->obtainable())
            ->with('species')
            ->get();

        $total = (int) $encounters->sum('weight');
        if ($total <= 0) {
            return null;
        }

        $pick = random_int(1, $total);
        foreach ($encounters as $encounter) {
            $pick -= $encounter->weight;
            if ($pick <= 0) {
                return $encounter;
            }
        }

        return $encounters->last();
    }

    public function rollLevel(): int
    {
        return random_int($this->min_level, $this->max_level);
    }

    /** Matin / jour / nuit, comme dans les jeux (étape 23 : heure du jeu, voir App\Services\TimeOfDay) */
    public static function currentTimeOfDay(): string
    {
        return \App\Services\TimeOfDay::period();
    }
}
