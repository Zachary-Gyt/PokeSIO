<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un dresseur PNJ. Placé sur la carte dans Tiled avec la propriété trainer = slug.
 */
class Trainer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_double_battle' => 'boolean',
            'rematchable' => 'boolean',
            'is_title' => 'boolean',
            'items' => 'array',
        ];
    }

    /**
     * Son équipe (modèle, régénérée à chaque combat). Étape 26 : TOUTES les versions (colonne version :
     * 0 = premier combat, 1, 2... = revanches) ; l'équipe d'un combat est choisie par RematchService::team().
     */
    public function pokemon()
    {
        return $this->hasMany(TrainerPokemon::class)->orderBy('slot');
    }

    public function badge()
    {
        return $this->belongsTo(Badge::class);
    }

    public function rewardItem()
    {
        return $this->belongsTo(Item::class, 'reward_item_id');
    }

    /** Personnages qui l'ont battu */
    public function defeatedBy()
    {
        return $this->belongsToMany(Character::class)
            ->withPivot('times_defeated', 'first_defeated_at', 'last_defeated_at');
    }

    /** Tenant du titre : l'historique (le tenant actuel est la ligne sans lost_at) */
    public function titleHolders()
    {
        return $this->hasMany(TitleHolder::class)->latest('won_at');
    }

    public function currentTitleHolder()
    {
        return $this->hasOne(TitleHolder::class)->whereNull('lost_at')->latestOfMany('won_at');
    }

    public function isGymLeader(): bool
    {
        return $this->badge_id !== null;
    }

    /** Peut-il défier ce personnage ? (pas encore battu, ou revanche possible : étape 26, Cherche VS et Champions) */
    public function canChallenge(Character $character): bool
    {
        // Le tenant du titre peut toujours être défié (sauf par lui-même : voir TitleService)
        return app(\App\Services\RematchService::class)->canChallenge($character, $this);
    }

    /** Trainer::findBySlug('route1_youngster_joey') */
    public static function findBySlug(string $slug): ?self
    {
        return self::where('slug', $slug)->first();
    }
}
