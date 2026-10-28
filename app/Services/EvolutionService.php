<?php

namespace App\Services;

use App\Models\Evolution;
use App\Models\Item;
use App\Models\Move;
use App\Models\Pokemon;

/**
 * Les évolutions : par niveau (après un combat, après un Super Bonbon) et par objet (Pierre Feu...).
 *
 * Le serveur vérifie TOUJOURS les conditions au moment d'évoluer : le navigateur ne peut pas
 * faire évoluer un Pokémon qui n'y a pas droit.
 */
class EvolutionService
{
    /** L'évolution possible maintenant (ou null). $item = objet utilisé (Pierre...) */
    public function candidate(Pokemon $pokemon, ?Item $item = null): ?Evolution
    {
        if ($pokemon->is_egg) {
            return null;   // étape 18 : un Œuf n'évolue pas
        }
        // Étape 14 : la Pierre Stase tenue empêche toute évolution
        if (HeldItemService::effectOf($pokemon) === 'no_evolve') {
            return null;
        }
        $pokemon->loadMissing('species.evolutions.to');

        $list = $pokemon->species->evolutions
            ->filter(fn (Evolution $e) => $e->to?->is_obtainable && $e->canBeTriggeredBy($pokemon, $item))
            ->sortBy('to_species_id')->values();

        // Étape 23 : Chenipotte (Armulys ou Blindalys) : c'est la "personnalité" du Pokémon qui décide,
        // toujours la même pour un Pokémon donné
        return $list->count() > 1 ? $list[crc32("{$pokemon->id}:{$pokemon->original_trainer_id}") % $list->count()] : $list->first();
    }

    /** Évolution déclenchée par un échange (Kadabra, Machopeur, Gravalanch, Spectrum), ou null */
    public function tradeCandidate(Pokemon $pokemon): ?Evolution
    {
        if ($pokemon->is_egg) {
            return null;
        }
        if (HeldItemService::effectOf($pokemon) === 'no_evolve') {
            return null;
        }
        $pokemon->loadMissing('species.evolutions.to');

        return $pokemon->species->evolutions
            ->filter(fn (Evolution $e) => $e->trigger === 'trade' && $e->to?->is_obtainable && $e->canBeTriggeredBy($pokemon, null, isTrade: true))
            ->first();
    }

    /** Ce que l'écran d'évolution doit afficher */
    public function preview(Pokemon $pokemon, Evolution $evolution, ?Item $item = null): array
    {
        $female = $pokemon->gender === 'female';

        return [
            'pokemonId' => $pokemon->id,
            'name' => $pokemon->display_name,
            'itemId' => $item?->id,
            'from' => [
                'speciesName' => $pokemon->species->name,
                'sprite' => $pokemon->species->sprite('front', $pokemon->is_shiny, $female),
                'cry' => \App\Models\PokemonSpecies::assetUrl($pokemon->species->cry),
            ],
            'to' => [
                'speciesName' => $evolution->to->name,
                'sprite' => $evolution->to->sprite('front', $pokemon->is_shiny, $female),
                'cry' => \App\Models\PokemonSpecies::assetUrl($evolution->to->cry),
            ],
        ];
    }

    /**
     * Fait évoluer le Pokémon (son surnom éventuel est gardé).
     * Renvoie ['learned' => [noms appris tout seuls], 'pending' => [attaques à proposer au joueur]].
     */
    public function evolve(Pokemon $pokemon, Evolution $evolution): array
    {
        $oldMaxHp = $pokemon->max_hp;
        $fromSpecies = $pokemon->species;

        $pokemon->pokemon_species_id = $evolution->to_species_id;
        $pokemon->setRelation('species', $evolution->to);
        // Étape 23 : l'objet tenu demandé par l'évolution est consommé (Croc Rasoir, Peau Métal, Pierre Ovale...)
        if ($evolution->held_item_id && (int) $pokemon->held_item_id === (int) $evolution->held_item_id) {
            $pokemon->held_item_id = null;
            $pokemon->unsetRelation('heldItem');
        }
        // Les PV perdus restent perdus : on ajoute seulement le gain de PV max
        $pokemon->current_hp = max(0, min($pokemon->max_hp, $pokemon->current_hp + ($pokemon->max_hp - $oldMaxHp)));
        $pokemon->save();

        $pokemon->character->registerInPokedex($evolution->to_species_id, caught: true);

        // Attaques apprises par la nouvelle forme à ce niveau
        $result = $this->learnAtCurrentLevel($pokemon);
        $result['messages'] = [];
        if ($shed = $this->shed($pokemon, $fromSpecies)) {
            $result['messages'][] = $shed;
        }

        return $result;
    }

    /**
     * Étape 23 : Ningale évolue en Ninjask... et si l'équipe a une place libre et le Sac une Poké Ball,
     * Munja apparaît (la Poké Ball est utilisée). Renvoie le message, ou null.
     */
    private function shed(Pokemon $pokemon, $fromSpecies): ?string
    {
        $evolution = Evolution::where('from_species_id', $fromSpecies->id)->where('trigger', 'shed')->with('to')->first();
        $character = $pokemon->character;
        $ball = Item::where('slug', 'poke-ball')->first();
        if (! $evolution?->to?->is_obtainable || ! $ball || ! $character->hasItem($ball) || ! $character->hasRoomInParty()) {
            return null;
        }
        $factory = app(PokemonFactory::class);
        $shedinja = $factory->make($evolution->to, $pokemon->level, [
            'nature' => $pokemon->nature,
            'ivs' => collect(\App\Models\PokemonSpecies::STATS)->mapWithKeys(fn ($st) => [$st => $pokemon->{"iv_{$st}"}])->all(),
            'shiny' => (bool) $pokemon->is_shiny,
        ]);
        foreach (\App\Models\PokemonSpecies::STATS as $st) {
            $shedinja->{"ev_{$st}"} = $pokemon->{"ev_{$st}"};
        }
        $shedinja->current_hp = $shedinja->max_hp;
        $character->removeItem($ball);
        $saved = $factory->giveTo($character, $shedinja, [
            'ball_id' => $ball->id, 'location' => $pokemon->met_location,
            'trainer_id' => $pokemon->original_trainer_id, 'trainer_name' => $pokemon->original_trainer_name,
        ]);
        // Munja garde les capacités de Ningale
        $saved->moves()->delete();
        foreach ($pokemon->moves()->get() as $pm) {
            $saved->moves()->create(['move_id' => $pm->move_id, 'slot' => $pm->slot, 'current_pp' => $pm->max_pp, 'pp_ups' => $pm->pp_ups]);
        }

        return "Il y a quelque chose dans la Poké Ball... {$evolution->to->name} a rejoint votre équipe !";
    }

    /**
     * Attaques que l'espèce apprend exactement au niveau actuel : apprises tout de suite s'il reste
     * de la place, sinon renvoyées (le joueur choisira quoi oublier). Renvoie [[move, auto], ...].
     */
    public function learnAtCurrentLevel(Pokemon $pokemon): array
    {
        $pokemon->load('moves');
        $known = $pokemon->moves->pluck('move_id')->all();
        $result = ['learned' => [], 'pending' => []];

        foreach ($pokemon->species->movesLearnedAt($pokemon->level) as $move) {
            if (in_array($move->id, $known, true)) {
                continue;
            }
            if (count($known) < 4) {
                $pokemon->moves()->create([
                    'move_id' => $move->id,
                    'slot' => count($known) + 1,
                    'current_pp' => $move->pp,
                    'pp_ups' => 0,
                ]);
                $known[] = $move->id;
                $result['learned'][] = $move->name;
            } else {
                $result['pending'][] = PokemonPresenter::newMove($move);
            }
        }

        return $result;
    }

    /**
     * Une CT / CS peut-elle apprendre cette capacité à ce Pokémon ?
     * Compatible si l'espèce l'apprend par CT, par un Maître des capacités ou par niveau.
     */
    public function canLearnByMachine(Pokemon $pokemon, Move $move): bool
    {
        return $pokemon->species->moves()
            ->wherePivotIn('learn_method', ['tm', 'tutor', 'level_up'])
            ->whereKey($move->id)
            ->exists();
    }

    /**
     * Apprend une attaque (en oubliant celle du slot $forget si le Pokémon en connaît déjà 4).
     * Autorisé pour une attaque que l'espèce apprend par niveau, à son niveau ou avant,
     * ou avec une CT ($machine) que le joueur possède et qui est compatible.
     */
    public function learn(Pokemon $pokemon, Move $move, ?int $forgetSlot, ?Item $machine = null): void
    {
        if ($machine) {
            $allowed = $machine->effect_code === 'teach'
                && $machine->move_id === $move->id
                && $pokemon->character->hasItem($machine)
                && $this->canLearnByMachine($pokemon, $move);
        } else {
            $allowed = $pokemon->species->moves()
                ->wherePivot('learn_method', 'level_up')
                ->wherePivot('level', '<=', $pokemon->level)
                ->whereKey($move->id)
                ->exists();
        }

        abort_unless($allowed, 422, 'Ce Pokémon ne peut pas apprendre cette capacité.');

        $pokemon->load('moves');
        abort_if($pokemon->moves->contains('move_id', $move->id), 422, 'Ce Pokémon connaît déjà cette capacité.');

        if ($pokemon->moves->count() < 4) {
            $pokemon->moves()->create(['move_id' => $move->id, 'slot' => $pokemon->moves->count() + 1, 'current_pp' => $move->pp, 'pp_ups' => 0]);

            return;
        }

        $slot = $pokemon->moves->firstWhere('slot', $forgetSlot);
        // Étape 19 : une CS ne s'oublie que depuis le Résumé (page Capacités)
        abort_if($slot && Move::isLockedHm((int) $slot->move_id), 422, 'Une CS ne peut pas être oubliée ainsi. Change-la depuis le Résumé du Pokémon (page Capacités).');
        abort_unless($slot, 422, 'Capacité à oublier inconnue.');
        $slot->forceFill(['move_id' => $move->id, 'current_pp' => $move->pp, 'pp_ups' => 0])->save();
    }
}
