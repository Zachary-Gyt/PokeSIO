<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * L'action choisie par un joueur pour un tour : attaque, changement, objet, fuite.
 */
class BattleAction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array']; // {"move_slot": 2} / {"party_slot": 3} / {"item_id": 17}
    }

    public function battle()
    {
        return $this->belongsTo(Battle::class);
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
