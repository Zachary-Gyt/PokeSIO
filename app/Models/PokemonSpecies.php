<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Une ESPÈCE (fiche Pokédex) : "Bulbizarre" en général.
 * Ne pas confondre avec Pokemon, qui est un Bulbizarre précis appartenant à un joueur.
 */
class PokemonSpecies extends Model
{
    // Laravel devinerait "pokemon_species" tout seul, mais on le précise pour plus de clarté
    protected $table = 'pokemon_species';

    protected $guarded = ['id'];

    public const STATS = ['hp', 'attack', 'defense', 'sp_attack', 'sp_defense', 'speed'];

    protected function casts(): array
    {
        return [
            'is_obtainable' => 'boolean',
            'is_starter' => 'boolean',
            'is_legendary' => 'boolean',
            'has_female_sprites' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function primaryType()
    {
        return $this->belongsTo(Type::class, 'primary_type_id');
    }

    public function secondaryType()
    {
        return $this->belongsTo(Type::class, 'secondary_type_id');
    }

    /** Talents possibles (pivot->is_hidden indique le talent caché) */
    public function abilities()
    {
        return $this->belongsToMany(Ability::class, 'pokemon_species_ability')
            ->withPivot('is_hidden')
            ->orderByPivot('is_hidden');
    }

    /** Learnset complet (pivot->learn_method, pivot->level) */
    public function moves()
    {
        return $this->belongsToMany(Move::class, 'pokemon_species_move')
            ->withPivot('learn_method', 'level');
    }

    /** Évolutions vers d'autres espèces */
    public function evolutions()
    {
        return $this->hasMany(Evolution::class, 'from_species_id');
    }

    /** L'évolution qui mène à cette espèce (Herbizarre -> vient de Bulbizarre) */
    public function preEvolution()
    {
        return $this->hasOne(Evolution::class, 'to_species_id');
    }

    /** Tous les Pokémon de joueurs de cette espèce */
    public function pokemon()
    {
        return $this->hasMany(Pokemon::class);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /** PokemonSpecies::obtainable()->get() : uniquement les générations ouvertes en jeu */
    public function scopeObtainable(Builder $query): void
    {
        $query->where('is_obtainable', true);
    }

    public function scopeStarters(Builder $query): void
    {
        $query->where('is_starter', true)->orderBy('pokedex_number');
    }

    // ------------------------------------------------------------------
    // Logique de jeu
    // ------------------------------------------------------------------

    /** Les types sous forme de collection : [Plante, Poison] */
    public function types(): Collection
    {
        return collect([$this->primaryType, $this->secondaryType])->filter()->values();
    }

    /**
     * Attaques apprises par niveau jusqu'à $level inclus, de la plus ancienne à la plus récente.
     * Un Pokémon sauvage ou fraîchement créé connaît les 4 dernières de cette liste.
     */
    public function levelUpMovesUpTo(int $level): Collection
    {
        return $this->moves()
            ->wherePivot('learn_method', 'level_up')
            ->wherePivot('level', '<=', $level)
            ->orderByPivot('level')
            ->get();
    }

    /** Attaques apprises exactement à ce niveau (pour la montée de niveau) */
    public function movesLearnedAt(int $level): Collection
    {
        return $this->moves()
            ->wherePivot('learn_method', 'level_up')
            ->wherePivot('level', $level)
            ->get();
    }

    /** Étape 18 : les groupes d'Œuf de l'espèce : ['monster', 'plant'] */
    public function eggGroups(): array
    {
        return array_values(array_filter(explode(',', (string) $this->egg_groups)));
    }

    /**
     * Étape 18 : l'espèce qui sort de l'Œuf : la première forme de l'évolution (Florizarre -> Bulbizarre).
     * On ne remonte que vers des espèces disponibles en jeu (Pikachu ne donne pas Pichu, absent de Kanto).
     */
    public function babySpecies(): self
    {
        $species = $this;
        for ($i = 0; $i < 5; $i++) {
            $from = $species->preEvolution?->from;
            if (! $from || ! $from->is_obtainable) {
                break;
            }
            $species = $from;
        }

        return $species;
    }

    /** Tire un sexe au hasard selon le ratio de l'espèce */
    public function randomGender(): string
    {
        if ($this->gender_rate === -1) {
            return 'genderless';
        }

        // gender_rate = nombre de huitièmes de femelles (0 = 100% mâle, 8 = 100% femelle)
        return random_int(1, 8) <= $this->gender_rate ? 'female' : 'male';
    }

    /** Tire un talent au hasard parmi les talents non cachés */
    public function randomAbility(): ?Ability
    {
        $abilities = $this->abilities->where('pivot.is_hidden', false);

        return $abilities->isEmpty() ? $this->abilities->first() : $abilities->random();
    }

    /**
     * Expérience totale nécessaire pour atteindre un niveau, selon la courbe de l'espèce.
     * Formules officielles des jeux.
     */
    public function experienceForLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }
        $n = $level;
        $cube = $n ** 3;

        $xp = match ($this->growth_rate) {
            'fast' => 4 * $cube / 5,
            'medium_fast' => $cube,
            'medium_slow' => 6 / 5 * $cube - 15 * $n ** 2 + 100 * $n - 140,
            'slow' => 5 * $cube / 4,
            'erratic' => match (true) {
                $n < 50 => $cube * (100 - $n) / 50,
                $n < 68 => $cube * (150 - $n) / 100,
                $n < 98 => $cube * intdiv(1911 - 10 * $n, 3) / 500,
                default => $cube * (160 - $n) / 100,
            },
            'fluctuating' => match (true) {
                $n < 15 => $cube * (intdiv($n + 1, 3) + 24) / 50,
                $n < 36 => $cube * ($n + 14) / 50,
                default => $cube * (intdiv($n, 2) + 32) / 50,
            },
            default => $cube,
        };

        return max(0, (int) floor($xp));
    }

    /** Niveau correspondant à un total d'expérience */
    public function levelForExperience(int $experience): int
    {
        $level = 1;
        while ($level < 100 && $experience >= $this->experienceForLevel($level + 1)) {
            $level++;
        }

        return $level;
    }

    // ------------------------------------------------------------------
    // Sprites
    // ------------------------------------------------------------------

    /**
     * URL d'un sprite, prête pour une balise <img>.
     * $kind : 'front', 'back', 'icon' ou 'overworld'
     * $female : variante femelle si l'espèce en a une (Pikachu, Rattata, Magicarpe...)
     */
    public function sprite(string $kind = 'front', bool $shiny = false, bool $female = false): ?string
    {
        $column = $shiny && in_array($kind, ['front', 'back', 'overworld'], true)
            ? "sprite_{$kind}_shiny"
            : "sprite_{$kind}";

        $path = $this->{$column} ?? $this->{"sprite_{$kind}"};

        // Sprites d'Essentials : la variante femelle est le même fichier suivi de "_female"
        // (front/PIKACHU.png -> front/PIKACHU_female.png)
        if ($female && $this->has_female_sprites && $path && in_array($kind, ['front', 'back'], true)) {
            $path = preg_replace('/\.png$/', '_female.png', $path);
        }

        return self::assetUrl($path);
    }

    /**
     * Chemin enregistré en base -> URL utilisable dans le navigateur :
     *   "https://..."                       URL externe (sprite PokéAPI non téléchargé)
     *   "game/sprites/pokemon/front/X.png"  fichier de public/ (sprites d'Essentials)
     *   "sprites/pokemon/1/front.png"       fichier du disque "public" (storage/app/public)
     */
    public static function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return match (true) {
            str_starts_with($path, 'http') => $path,
            str_starts_with($path, 'game/') => asset($path),
            default => asset('storage/' . $path),
        };
    }
}
