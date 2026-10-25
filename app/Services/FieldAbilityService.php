<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use App\Models\Pokemon;
use App\Models\WildEncounter;

/**
 * Étape 23 : les talents qui agissent HORS combat, quand le PREMIER Pokémon de l'équipe les a
 * (règles de la 4e génération, comme Pokémon Essentials) :
 *   Intimidation, Regard Vif   les Pokémon sauvages bien plus faibles (5 niveaux de moins) fuient une fois sur deux
 *   Puanteur, Écran Fumée, Pied Véloce   une rencontre sur deux évitée
 *   Voile Sable, Rideau Neige  une rencontre sur deux évitée quand la carte a la tempête de sable / la grêle
 *   Statik, Magnépiège         une fois sur deux, un Pokémon Électrik / Acier de la zone
 *   Agitation, Pression, Esprit Vital   une fois sur deux, le Pokémon sauvage a le niveau le plus haut de la zone
 *   Joli Sourire               2 fois sur 3, le Pokémon sauvage est du sexe opposé
 *   Synchro                    une fois sur deux, le Pokémon sauvage a la même nature
 *   Ramassage                  après un combat, 10 % de chances par Pokémon (sans objet) de trouver un objet
 */
class FieldAbilityService
{
    /** Le premier Pokémon de l'équipe (pas un Œuf) et son talent */
    public function lead(Character $character): ?Pokemon
    {
        return $character->party()->where('is_egg', false)->with('ability')->first();
    }

    /** La rencontre est-elle évitée par le talent du premier Pokémon ? */
    public function avoids(Character $character, int $wildLevel, ?MapInfo $map): bool
    {
        $lead = $this->lead($character);
        $ability = $lead?->ability?->slug;
        $weather = $map?->property('weather');

        return match (true) {
            in_array($ability, ['intimidate', 'keen-eye'], true) => $wildLevel <= $lead->level - 5 && random_int(0, 1) === 0,
            in_array($ability, ['stench', 'white-smoke', 'quick-feet'], true) => random_int(0, 1) === 0,
            $ability === 'sand-veil' && $weather === 'sand', $ability === 'snow-cloak' && $weather === 'hail' => random_int(0, 1) === 0,
            default => false,
        };
    }

    /** Statik / Magnépiège : une fois sur deux, on retire la rencontre parmi les Pokémon de ce type */
    public function attractType(Character $character, string $mapKey, string $zone, string $method, WildEncounter $encounter): WildEncounter
    {
        $type = ['static' => 'electric', 'magnet-pull' => 'steel'][$this->lead($character)?->ability?->slug] ?? null;
        if (! $type || random_int(0, 1) === 0) {
            return $encounter;
        }
        $list = WildEncounter::at($mapKey, $zone, $method)->with('species.primaryType', 'species.secondaryType')->get()
            ->filter(fn ($e) => $e->species?->is_obtainable && in_array($type, [$e->species->primaryType?->slug, $e->species->secondaryType?->slug], true));

        return $list->isEmpty() ? $encounter : $list->random();
    }

    /** Niveau du Pokémon sauvage : Agitation, Pression, Esprit Vital donnent le niveau le plus haut une fois sur deux */
    public function level(Character $character, WildEncounter $encounter, int $level): int
    {
        $ability = $this->lead($character)?->ability?->slug;

        return in_array($ability, ['hustle', 'pressure', 'vital-spirit'], true) && random_int(0, 1) === 0 ? $encounter->max_level : $level;
    }

    /** Options de création du Pokémon sauvage (PokemonFactory::make) : Synchro (nature), Joli Sourire (sexe) */
    public function wildOptions(Character $character, \App\Models\PokemonSpecies $species): array
    {
        $lead = $this->lead($character);
        $ability = $lead?->ability?->slug;
        $options = [];
        if ($ability === 'synchronize' && random_int(0, 1) === 0 && $lead->nature) {
            $options['nature'] = $lead->nature;
        }
        if ($ability === 'cute-charm' && in_array($lead->gender, ['male', 'female'], true)
            && $species->gender_rate > 0 && $species->gender_rate < 8 && random_int(1, 3) <= 2) {
            $options['gender'] = $lead->gender === 'male' ? 'female' : 'male';
        }

        return $options;
    }

    /**
     * Ramassage, après un combat gagné : chaque Pokémon de l'équipe qui a ce talent et ne tient rien a 10 % de
     * chances de trouver un objet, selon son niveau (config/items.php, "pickup"). Renvoie les messages.
     */
    public function pickup(Character $character): array
    {
        $messages = [];
        foreach ($character->party()->where('is_egg', false)->whereNull('held_item_id')->whereHas('ability', fn ($q) => $q->where('slug', 'pickup'))->get() as $pokemon) {
            if (random_int(1, 10) !== 1) {
                continue;
            }
            $table = collect(config('items.pickup', []))->first(fn ($row) => $pokemon->level <= $row['max_level']);
            $slug = $table ? $this->weighted($table['items']) : null;
            $item = $slug ? Item::where('slug', $slug)->first() : null;
            if ($item) {
                $pokemon->forceFill(['held_item_id' => $item->id])->save();
                $messages[] = "{$pokemon->display_name} a ramassé {$item->name} !";
            }
        }

        return $messages;
    }

    private function weighted(array $items): ?string
    {
        $total = array_sum($items);
        $roll = random_int(1, max(1, $total));
        foreach ($items as $slug => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $slug;
            }
        }

        return null;
    }
}
