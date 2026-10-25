<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une évolution : "Salamèche -> Reptincel au niveau 16".
 */
class Evolution extends Model
{
    /** Étape 23 : les zones d'évolution (propriété de carte Tiled "evolution_area") */
    public const AREAS = ['magnetic' => 'champ magnétique', 'moss' => 'près de la Pierre Mousse', 'ice' => 'près de la Pierre Glace'];

    public $timestamps = false;

    protected $guarded = ['id'];

    public function from()
    {
        return $this->belongsTo(PokemonSpecies::class, 'from_species_id');
    }

    public function to()
    {
        return $this->belongsTo(PokemonSpecies::class, 'to_species_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    /** Étape 23 : objet à tenir (Croc Rasoir...), capacité à connaître, Pokémon à avoir dans l'équipe */
    public function heldItem()
    {
        return $this->belongsTo(Item::class, 'held_item_id');
    }

    public function knownMove()
    {
        return $this->belongsTo(Move::class, 'known_move_id');
    }

    public function partySpecies()
    {
        return $this->belongsTo(PokemonSpecies::class, 'party_species_id');
    }

    /**
     * Ce Pokémon remplit-il les conditions pour évoluer ?
     * $usedItem : l'objet utilisé sur lui (Pierre Feu...), $isTrade : pendant un échange.
     * Étape 23 : toutes les conditions des générations 2 à 4 (moment de la journée, objet tenu, capacité connue,
     * sexe, lieu, Pokémon dans l'équipe, Attaque / Défense de Debugant).
     */
    public function canBeTriggeredBy(Pokemon $pokemon, ?Item $usedItem = null, bool $isTrade = false): bool
    {
        if ($this->time_of_day && $this->time_of_day !== \App\Services\TimeOfDay::dayOrNight()) {
            return false;
        }
        if ($this->gender && $pokemon->gender !== $this->gender) {
            return false;
        }
        if ($this->held_item_id && (int) $pokemon->held_item_id !== (int) $this->held_item_id) {
            return false;
        }
        if ($this->known_move_id && ! $pokemon->moves()->where('move_id', $this->known_move_id)->exists()) {
            return false;
        }
        if ($this->known_move_type_id && ! $pokemon->moves()->whereHas('move', fn ($q) => $q->where('type_id', $this->known_move_type_id))->exists()) {
            return false;
        }
        if ($this->party_species_id && ! $pokemon->character?->party()->where('is_egg', false)->where('pokemon_species_id', $this->party_species_id)->exists()) {
            return false;
        }
        if ($this->location && \App\Services\MapInfo::load((string) $pokemon->character?->current_map)?->property('evolution_area') !== $this->location) {
            return false;
        }
        if ($this->relative_stats !== null && ($pokemon->stat('attack') <=> $pokemon->stat('defense')) !== (int) $this->relative_stats) {
            return false;
        }

        // Une montée de niveau sans niveau minimum doit avoir une autre condition (sinon : jamais)
        $hasCondition = $this->min_level !== null || $this->time_of_day || $this->held_item_id || $this->known_move_id
            || $this->known_move_type_id || $this->party_species_id || $this->location;

        return match ($this->trigger) {
            'level' => ! $usedItem && ! $isTrade && $hasCondition && $pokemon->level >= (int) ($this->min_level ?? 1),
            'friendship' => ! $usedItem && ! $isTrade && $pokemon->friendship >= ($this->min_friendship ?? 160),
            'item' => $usedItem?->id === $this->item_id,
            'trade' => $isTrade,
            default => false, // "shed" (Munja) : géré par EvolutionService quand Ningale évolue
        };
    }

    public static function currentTimeOfDay(): string
    {
        return \App\Services\TimeOfDay::dayOrNight();
    }

    /** Texte lisible : "niv. 16", "Pierre Feu", "échange"... */
    public function conditionLabel(): string
    {
        $base = match ($this->trigger) {
            'level' => $this->min_level ? 'niv. ' . $this->min_level : 'niveau',
            'item' => $this->item?->name ?? 'objet',
            'trade' => 'échange',
            'friendship' => 'bonheur',
            'shed' => 'place libre et Poké Ball',
            default => 'spécial',
        };
        // Étape 23 : les conditions en plus
        $more = array_filter([
            $this->time_of_day ? ($this->time_of_day === 'night' ? 'la nuit' : 'le jour') : null,
            $this->heldItem ? 'en tenant ' . $this->heldItem->name : null,
            $this->knownMove ? 'en connaissant ' . $this->knownMove->name : null,
            $this->gender ? ($this->gender === 'male' ? 'mâle' : 'femelle') : null,
            $this->location ? (self::AREAS[$this->location] ?? $this->location) : null,
            $this->partySpecies ? 'avec ' . $this->partySpecies->name . ' dans l\'équipe' : null,
            $this->relative_stats !== null ? ['Attaque < Défense', 'Attaque = Défense', 'Attaque > Défense'][$this->relative_stats + 1] : null,
        ]);

        return trim($base . ($more ? ', ' . implode(', ', $more) : ''));
    }
}
