<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un objet : Potion, Poké Ball, Vélo, Pierre Feu...
 */
class Item extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'consumable' => 'boolean',
            'tradable' => 'boolean',
            'usable_in_battle' => 'boolean',
            'usable_overworld' => 'boolean',
            'holdable' => 'boolean',
        ];
    }

    /** Pour une CT : l'attaque qu'elle apprend */
    public function move()
    {
        return $this->belongsTo(Move::class);
    }

    /**
     * Les "scopes" sont des filtres réutilisables :
     *   Item::inPocket('balls')->get()
     */
    public function scopeInPocket(Builder $query, string $pocket): void
    {
        $query->where('pocket', $pocket);
    }

    /** Objets vendus en boutique */
    public function scopeBuyable(Builder $query): void
    {
        $query->where('price', '>', 0)->where('pocket', '!=', 'key_items');
    }

    public function isBall(): bool
    {
        return $this->effect_code === 'catch';
    }

    public function spriteUrl(): ?string
    {
        return PokemonSpecies::assetUrl($this->sprite);
    }
}
