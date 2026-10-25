<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une nature : +10 % sur une stat, -10 % sur une autre (ou neutre).
 */
class Nature extends Model
{
    protected $guarded = ['id'];

    /**
     * Multiplicateur appliqué à une stat : 1.1, 0.9 ou 1.0.
     * $stat : 'attack', 'defense', 'sp_attack', 'sp_defense' ou 'speed' (les PV ne sont jamais affectés).
     */
    public function multiplierFor(string $stat): float
    {
        return match ($stat) {
            $this->increased_stat => 1.1,
            $this->decreased_stat => 0.9,
            default => 1.0,
        };
    }

    public function isNeutral(): bool
    {
        return $this->increased_stat === null;
    }
}
