<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une attaque : Charge, Flammèche, Tonnerre...
 */
class Move extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'makes_contact' => 'boolean',
            // JSON -> tableau PHP : $move->meta['ailment'], $move->meta['stat_changes']...
            'meta' => 'array',
        ];
    }

    public function type()
    {
        return $this->belongsTo(Type::class);
    }

    /** Espèces capables d'apprendre cette attaque */
    public function species()
    {
        return $this->belongsToMany(PokemonSpecies::class, 'pokemon_species_move')
            ->withPivot('learn_method', 'level');
    }

    /**
     * Étape 19 : les capacités enseignées par une CS (objets "hm01"... de la table items).
     * Avec config('move_tutor.lock_hms'), elles ne s'oublient que chez le Maître des capacités.
     */
    public static function hmIds(): array
    {
        static $ids = null;

        return $ids ??= Item::where('slug', 'like', 'hm%')->where('effect_code', 'teach')
            ->whereNotNull('move_id')->pluck('move_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Étape 19 : cette capacité est-elle une CS verrouillée (impossible à oublier en dehors du Maître) ? */
    public static function isLockedHm(int $moveId): bool
    {
        return (bool) config('move_tutor.lock_hms', true) && in_array($moveId, self::hmIds(), true);
    }

    public function isStatus(): bool
    {
        return $this->category === 'status';
    }

    public function isPhysical(): bool
    {
        return $this->category === 'physical';
    }

    /** Statut infligé ("paralysis", "burn"...) ou null */
    public function ailment(): ?string
    {
        $ailment = $this->meta['ailment'] ?? 'none';

        return $ailment === 'none' ? null : $ailment;
    }
}
