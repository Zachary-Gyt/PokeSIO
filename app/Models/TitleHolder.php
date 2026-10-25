<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un tenant du titre (PNJ "roi de la colline").
 * Le tenant actuel d'un dresseur est la ligne dont lost_at est null.
 */
class TitleHolder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'team' => 'array',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function lostTo()
    {
        return $this->belongsTo(Character::class, 'lost_to_character_id');
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('lost_at');
    }

    /** Étape 17 : le personnage du joueur qui a le titre (red, leaf, brendan, may), ou null pour le dresseur d'origine */
    public function avatarKey(): ?string
    {
        if ($this->avatar) {
            return $this->avatar;
        }

        return $this->gender ? ($this->gender === 'female' ? 'leaf' : 'red') : null;
    }
}
