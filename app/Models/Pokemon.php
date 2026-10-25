<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Un Pokémon POSSÉDÉ par un personnage (dans l'équipe ou au PC).
 *
 * Les stats finales ne sont pas en base : elles se calculent à la volée
 *   $pokemon->max_hp, $pokemon->attack, $pokemon->speed...
 * Pense à précharger `species` et `nature` quand tu en affiches plusieurs :
 *   $character->party()->with('species', 'nature')->get()
 */
class Pokemon extends Model
{
    // Sans cette ligne, Laravel chercherait une table "pokemons"
    protected $table = 'pokemon';

    public const PARTY_SIZE = 6;
    public const BOX_COUNT = 8;
    public const BOX_SIZE = 30;
    public const MAX_LEVEL = 100;
    public const MAX_IV = 31;
    public const MAX_EV_PER_STAT = 252;
    public const MAX_EV_TOTAL = 510;

    /**
     * Tout est remplissable sauf l'id et le propriétaire :
     * on crée un Pokémon via $character->pokemon()->create([...]),
     * et on change de propriétaire via $pokemon->character()->associate($autre).
     */
    protected $guarded = ['id', 'character_id'];

    protected function casts(): array
    {
        return [
            'is_shiny' => 'boolean',
            'is_egg' => 'boolean',   // étape 18
            'met_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function species()
    {
        return $this->belongsTo(PokemonSpecies::class, 'pokemon_species_id');
    }

    public function nature()
    {
        return $this->belongsTo(Nature::class);
    }

    public function ability()
    {
        return $this->belongsTo(Ability::class);
    }

    public function heldItem()
    {
        return $this->belongsTo(Item::class, 'held_item_id');
    }

    public function caughtBall()
    {
        return $this->belongsTo(Item::class, 'caught_ball_id');
    }

    /** Les 4 attaques connues (avec PP restants) */
    public function moves()
    {
        return $this->hasMany(PokemonMove::class)->orderBy('slot');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeInParty(Builder $query): void
    {
        $query->where('location', 'party');
    }

    public function scopeInBox(Builder $query, ?int $box = null): void
    {
        $query->where('location', 'box')->when($box, fn ($q) => $q->where('box_number', $box));
    }

    /** Étape 18 : les Pokémon qui ne sont PAS des Œufs (ceux qui peuvent combattre, utiliser une CS...) */
    public function scopeHatched(Builder $query): void
    {
        $query->where('is_egg', false);
    }

    // ------------------------------------------------------------------
    // Statistiques (formules officielles depuis la 3e génération)
    // ------------------------------------------------------------------

    /**
     * Calcule une stat finale.
     * $stat : 'hp', 'attack', 'defense', 'sp_attack', 'sp_defense' ou 'speed'
     */
    public function stat(string $stat): int
    {
        $base = $this->species->{"base_{$stat}"};
        $iv = $this->{"iv_{$stat}"};
        $ev = $this->{"ev_{$stat}"};
        $core = intdiv((2 * $base + $iv + intdiv($ev, 4)) * $this->level, 100);

        if ($stat === 'hp') {
            return $core + $this->level + 10;
        }

        return (int) floor(($core + 5) * ($this->nature?->multiplierFor($stat) ?? 1.0));
    }

    /** Les 6 stats d'un coup : ['hp' => 39, 'attack' => 22, ...] */
    public function stats(): array
    {
        return collect(PokemonSpecies::STATS)->mapWithKeys(fn ($s) => [$s => $this->stat($s)])->all();
    }

    // Accesseurs : $pokemon->max_hp, $pokemon->attack...
    protected function maxHp(): Attribute
    {
        return Attribute::get(fn () => $this->stat('hp'));
    }

    protected function attack(): Attribute
    {
        return Attribute::get(fn () => $this->stat('attack'));
    }

    protected function defense(): Attribute
    {
        return Attribute::get(fn () => $this->stat('defense'));
    }

    protected function spAttack(): Attribute
    {
        return Attribute::get(fn () => $this->stat('sp_attack'));
    }

    protected function spDefense(): Attribute
    {
        return Attribute::get(fn () => $this->stat('sp_defense'));
    }

    protected function speed(): Attribute
    {
        return Attribute::get(fn () => $this->stat('speed'));
    }

    /** Surnom s'il y en a un, sinon le nom de l'espèce (étape 18 : "Œuf" pour un Œuf) */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->is_egg ? 'Œuf' : ($this->nickname ?: $this->species->name));
    }

    /** Pourcentage de PV restants (pour la barre de vie) */
    protected function hpPercent(): Attribute
    {
        return Attribute::get(fn () => $this->max_hp > 0 ? (int) round($this->current_hp * 100 / $this->max_hp) : 0);
    }

    public function evTotal(): int
    {
        return collect(PokemonSpecies::STATS)->sum(fn ($s) => $this->{"ev_{$s}"});
    }

    // ------------------------------------------------------------------
    // Expérience
    // ------------------------------------------------------------------

    /** XP manquante pour le niveau suivant */
    public function experienceToNextLevel(): int
    {
        if ($this->level >= self::MAX_LEVEL) {
            return 0;
        }

        return max(0, $this->species->experienceForLevel($this->level + 1) - $this->experience);
    }

    /** Progression dans le niveau actuel, de 0 à 100 (barre bleue d'XP) */
    public function experiencePercent(): int
    {
        if ($this->level >= self::MAX_LEVEL) {
            return 100;
        }
        $current = $this->species->experienceForLevel($this->level);
        $next = $this->species->experienceForLevel($this->level + 1);

        return (int) floor(($this->experience - $current) * 100 / max(1, $next - $current));
    }

    // ------------------------------------------------------------------
    // État
    // ------------------------------------------------------------------

    public function isFainted(): bool
    {
        return $this->current_hp <= 0;
    }

    public function isInParty(): bool
    {
        return $this->location === 'party';
    }

    /** Soin complet : PV, statut et PP (Centre Pokémon) */
    public function heal(): void
    {
        if ($this->is_egg) {
            return; // étape 18 : un Œuf n'a rien à soigner
        }
        $this->current_hp = $this->max_hp;
        $this->status = 'none';
        $this->sleep_turns = 0;
        $this->save();

        $this->moves->each(function (PokemonMove $move) {
            $move->current_pp = $move->max_pp;
            $move->save();
        });
    }

    /** Ajoute (ou retire, avec une valeur négative) des PV sans dépasser les bornes */
    public function changeHp(int $amount): void
    {
        $this->current_hp = max(0, min($this->max_hp, $this->current_hp + $amount));
        $this->save();
    }

    // ------------------------------------------------------------------
    // Sprites
    // ------------------------------------------------------------------

    public function sprite(string $kind = 'front'): ?string
    {
        return $this->species->sprite($kind, $this->is_shiny, $this->gender === 'female');
    }
}
