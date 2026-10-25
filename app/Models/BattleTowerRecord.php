<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Étape 28 : le record d'un personnage à la Tour de Combat (meilleure série, victoires, séries terminées) */
class BattleTowerRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'best_team' => 'array',
            'best_at' => 'datetime',
        ];
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
