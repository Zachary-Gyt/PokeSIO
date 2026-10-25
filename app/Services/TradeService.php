<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Events\PlayerNotice;
use App\Models\Character;
use App\Models\Pokemon;
use App\Models\Trade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Les ÉCHANGES de Pokémon entre deux joueurs (un contre un, comme dans les jeux).
 *
 *   1. Chacun choisit le Pokémon de son équipe qu'il propose              -> offer()
 *      (changer d'avis remet les deux confirmations à zéro)
 *   2. Chacun voit l'offre de l'autre (son Résumé), puis confirme          -> confirm()
 *   3. Quand les DEUX ont confirmé, le serveur échange les Pokémon d'un coup (transaction)
 *   4. Kadabra, Machopeur, Gravalanch et Spectrum évoluent en arrivant chez leur nouveau dresseur.
 *
 * Le Dresseur d'Origine (D.O.) ne change pas : un Pokémon échangé trop fort pour les badges
 * de son nouveau dresseur peut désobéir en combat (étape 8).
 */
class TradeService
{
    public function __construct(private EvolutionService $evolutions)
    {
    }

    /** Ce que le jeu affiche à $viewer */
    public function view(Trade $trade, Character $viewer): array
    {
        $this->check($trade, $viewer);
        $trade->load('pokemon');
        $partner = $trade->partnerOf($viewer);
        $isInitiator = $viewer->id === $trade->initiator_id;

        $offerOf = fn (Character $c) => $trade->pokemon->first(fn ($p) => $p->pivot->owner_id === $c->id);
        $present = function (?Pokemon $p) {
            if (! $p) {
                return null;
            }
            $p->load(PokemonPresenter::RELATIONS);

            return PokemonPresenter::detail($p);
        };

        // Échange terminé : les offres telles qu'elles étaient AVANT l'échange (et avant une évolution)
        $before = $trade->status === 'completed' ? Cache::get("trade:{$trade->id}:offers") : null;

        $view = [
            'id' => $trade->id,
            'status' => $trade->status,
            'partner' => ['id' => $partner->id, 'name' => $partner->name],
            'mine' => ['offer' => $present($offerOf($viewer)), 'confirmed' => $isInitiator ? $trade->initiator_confirmed : $trade->recipient_confirmed],
            'theirs' => ['offer' => $present($offerOf($partner)), 'confirmed' => $isInitiator ? $trade->recipient_confirmed : $trade->initiator_confirmed],
        ];

        if ($trade->status === 'negotiating') {
            $view['party'] = $viewer->party()->with(PokemonPresenter::RELATIONS)->get()
                ->map(fn ($p) => PokemonPresenter::detail($p))->values()->all();
        }

        if ($before) {
            $view['mine']['offer'] = $before[$viewer->id] ?? null;
            $view['theirs']['offer'] = $before[$partner->id] ?? null;
        }

        // Échange terminé : le Pokémon reçu, et son évolution éventuelle
        if ($trade->status === 'completed') {
            $view['evolution'] = Cache::get("trade:{$trade->id}:evolution:{$viewer->id}");
        }

        return $view;
    }

    /** Je propose ce Pokémon de mon équipe */
    public function offer(Trade $trade, Character $character, int $pokemonId): void
    {
        DB::transaction(function () use ($trade, $character, $pokemonId) {
            $trade = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            $this->check($trade, $character, open: true);

            $pokemon = $character->party()->whereKey($pokemonId)->first();
            if (! $pokemon) {
                throw new InvalidAction('Ce Pokémon n\'est pas dans votre équipe.');
            }
            if ($pokemon->is_egg) {
                throw new InvalidAction('Les Œufs ne peuvent pas être échangés.');   // étape 18
            }

            // Une seule offre par joueur : la nouvelle remplace l'ancienne
            $mine = $trade->pokemon()->wherePivot('owner_id', $character->id)->pluck('pokemon.id');
            $trade->pokemon()->detach($mine);
            $trade->pokemon()->attach($pokemon->id, ['owner_id' => $character->id]);
            $trade->resetConfirmations();
        });

        $this->notify($trade, 'offer');
    }

    /** Je suis d'accord avec l'échange tel qu'il est. Les deux sont d'accord : il a lieu. */
    public function confirm(Trade $trade, Character $character): void
    {
        $completed = DB::transaction(function () use ($trade, $character) {
            $trade = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            $this->check($trade, $character, open: true);

            if ($trade->pokemon()->count() < 2) {
                throw new InvalidAction('Les deux dresseurs doivent proposer un Pokémon.');
            }
            $trade->forceFill([$character->id === $trade->initiator_id ? 'initiator_confirmed' : 'recipient_confirmed' => true])->save();

            if (! $trade->bothConfirmed()) {
                return false;
            }
            $this->complete($trade);

            return true;
        });

        $this->notify($trade, $completed ? 'completed' : 'confirm');
    }

    public function cancel(Trade $trade, Character $character): void
    {
        $this->check($trade, $character);
        if ($trade->isOpen()) {
            $trade->update(['status' => 'cancelled']);
            $this->notify($trade, 'cancelled');
        }
    }

    /** Le transfert (déjà dans une transaction, ligne verrouillée) */
    private function complete(Trade $trade): void
    {
        $a = $trade->initiator;
        $b = $trade->recipient;
        $offers = $trade->pokemon()->get()->keyBy(fn ($p) => $p->pivot->owner_id);
        $fromA = Pokemon::whereKey($offers[$a->id]->id ?? 0)->lockForUpdate()->first();
        $fromB = Pokemon::whereKey($offers[$b->id]->id ?? 0)->lockForUpdate()->first();

        // Les Pokémon ont-ils changé de place depuis l'offre (PC, relâché...) ?
        foreach ([[$fromA, $a], [$fromB, $b]] as [$p, $owner]) {
            if (! $p || $p->character_id !== $owner->id || $p->location !== 'party') {
                $trade->resetConfirmations();
                throw new InvalidAction('Un des Pokémon proposés n\'est plus dans l\'équipe. Échange annulé.');
            }
        }

        // Chaque équipe doit garder au moins un Pokémon en état de se battre
        foreach ([[$a, $fromA, $fromB], [$b, $fromB, $fromA]] as [$owner, $given, $received]) {
            $able = $owner->party()->where('id', '!=', $given->id)->where('current_hp', '>', 0)->exists() || $received->current_hp > 0;
            if (! $able) {
                $trade->resetConfirmations();
                throw new InvalidAction("{$owner->name} n'aurait plus aucun Pokémon en état de se battre !");
            }
        }

        // Échange des places (case temporaire 98 : l'index unique "équipe" ne doit jamais être violé)
        $slotA = $fromA->party_slot;
        $slotB = $fromB->party_slot;
        $fromA->forceFill(['character_id' => $b->id, 'party_slot' => 98])->save();
        $fromB->forceFill(['character_id' => $a->id, 'party_slot' => $slotA])->save();
        $fromA->forceFill(['party_slot' => $slotB])->save();

        $a->registerInPokedex($fromB->pokemon_species_id, caught: true);
        $b->registerInPokedex($fromA->pokemon_species_id, caught: true);

        $trade->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        // Ce que chacun a envoyé, tel quel (pour l'animation de l'échange)
        Cache::put("trade:{$trade->id}:offers", [
            $a->id => PokemonPresenter::detail($fromA->fresh()->load(PokemonPresenter::RELATIONS)),
            $b->id => PokemonPresenter::detail($fromB->fresh()->load(PokemonPresenter::RELATIONS)),
        ], now()->addHour());

        // Évolution par échange (Kadabra -> Alakazam...) : elle a lieu tout de suite
        foreach ([[$fromA->fresh(), $b], [$fromB->fresh(), $a]] as [$pokemon, $newOwner]) {
            $evolution = $this->evolutions->tradeCandidate($pokemon);
            if ($evolution) {
                $preview = $this->evolutions->preview($pokemon, $evolution);
                $this->evolutions->evolve($pokemon, $evolution);
                Cache::put("trade:{$trade->id}:evolution:{$newOwner->id}", $preview, now()->addHour());
            }
        }
    }

    private function check(Trade $trade, Character $character, bool $open = false): void
    {
        if (! $trade->involves($character)) {
            throw new InvalidAction('Cet échange ne vous concerne pas.');
        }
        if ($open && $trade->status !== 'negotiating') {
            throw new InvalidAction('Cet échange est terminé.');
        }
    }

    private function notify(Trade $trade, string $what): void
    {
        foreach ([$trade->initiator_id, $trade->recipient_id] as $id) {
            PlayerNotice::send($id, 'trade', ['tradeId' => $trade->id, 'what' => $what]);
        }
    }
}
