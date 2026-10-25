<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Étape 27 : une entrée du Panthéon (victoire contre le Maître de la Ligue) */
class HallOfFameEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'team' => 'array',
            'entered_at' => 'datetime',
        ];
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    /** Pour le jeu (écran du Panthéon, PC) */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'trainer' => ['name' => $this->trainer_name, 'sprite' => asset('game/sprites/trainers/battle/player_' . ($this->avatar ?: 'red') . '.png')],
            'team' => $this->team,
            'date' => $this->entered_at?->locale('fr')->isoFormat('D MMMM YYYY'),
        ];
    }
}
