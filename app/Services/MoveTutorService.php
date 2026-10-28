<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Move;
use App\Models\Pokemon;
use App\Models\PokemonMove;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CHANGER LES CAPACITÉS d'un Pokémon, depuis le Résumé (Menu > Pokémon > Résumé > Capacités).
 * (Remplace l'ancien Maître des capacités : le nom de la classe est resté.)
 *
 * Le Pokémon peut reprendre, gratuitement et à tout moment hors combat :
 *   - les capacités que son espèce apprend par niveau jusqu'à son niveau (et ses formes précédentes, voir config) ;
 *   - toutes celles qu'il a apprises au moins une fois (CT, CS, Œuf, Gribouille...) : table pokemon_learned_moves.
 * Les CS se remplacent ici librement : on peut toujours les reprendre de la même façon.
 */
class MoveTutorService
{
    /** Toutes les capacités possibles pour ce Pokémon (celles qu'il connaît ont leur place "slot" 1..4) */
    public function options(Pokemon $pokemon): array
    {
        $pokemon->loadMissing('species', 'moves.move.type');
        $known = $pokemon->moves->sortBy('slot')->values();

        $list = [];
        foreach ($known as $pm) {
            $list[$pm->move_id] = $this->row($pm->move, $pm->current_pp, $pm->max_pp, $pm->slot);
        }
        $others = $this->learnable($pokemon)->concat($this->learnedBefore($pokemon))
            ->unique('id')->sortBy(fn (Move $m) => $m->name, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($others as $move) {
            $list[$move->id] ??= $this->row($move, $move->pp, $move->pp, null);
        }

        return array_values($list);
    }

    /**
     * Met la capacité $moveId à la place $slot (1..4). Une place vide (le Pokémon connaît moins de 4 capacités)
     * reçoit la capacité à la suite des autres. La nouvelle capacité a ses PP au maximum.
     */
    public function replace(Character $character, Pokemon $pokemon, int $slot, int $moveId): void
    {
        if ($pokemon->is_egg) {
            throw new InvalidAction('Un Œuf ne connaît aucune capacité !');
        }
        if (Battle::where('character_id', $character->id)->where('status', 'active')->exists()) {
            throw new InvalidAction('Impossible pendant un combat !');
        }
        $pokemon->loadMissing('moves');
        $known = $pokemon->moves->sortBy('slot')->values();
        if ($slot < 1 || $slot > min(PokemonMove::MAX_MOVES, $known->count() + 1)) {
            throw new InvalidAction('Emplacement de capacité inconnu.');
        }
        if ($known->contains('move_id', $moveId)) {
            throw new InvalidAction("{$pokemon->name} connaît déjà cette capacité.");
        }
        $allowed = collect($this->options($pokemon))->pluck('id')->all();
        if (! in_array($moveId, $allowed, true)) {
            throw new InvalidAction("{$pokemon->name} ne peut pas apprendre cette capacité.");
        }

        $move = Move::findOrFail($moveId);
        DB::transaction(function () use ($pokemon, $known, $slot, $move) {
            $old = $known->firstWhere('slot', $slot);
            if ($old) {
                $old->forceFill(['move_id' => $move->id, 'current_pp' => $move->pp, 'pp_ups' => 0])->save();
            } else {
                $pokemon->moves()->create(['move_id' => $move->id, 'slot' => $known->count() + 1, 'current_pp' => $move->pp, 'pp_ups' => 0]);
            }
        });
        $pokemon->unsetRelation('moves');
    }

    /**
     * Enregistre 1 à 4 capacités d'un coup, dans l'ordre (ancien écran du Maître ; gardé pour les scripts).
     * Les capacités gardées conservent leurs PP. Renvoie le prix payé (config move_tutor.price).
     */
    public function apply(Character $character, Pokemon $pokemon, array $moveIds): int
    {
        $moveIds = array_values(array_map('intval', $moveIds));
        if (count($moveIds) < 1 || count($moveIds) > 4 || count(array_unique($moveIds)) !== count($moveIds)) {
            throw new InvalidAction('Choisissez de 1 à 4 capacités différentes.');
        }
        $allowed = collect($this->options($pokemon))->pluck('id')->all();
        foreach ($moveIds as $id) {
            if (! in_array($id, $allowed, true)) {
                throw new InvalidAction('Ce Pokémon ne peut pas apprendre cette capacité ici.');
            }
        }

        $current = $pokemon->moves->sortBy('slot')->pluck('move_id')->map(fn ($id) => (int) $id)->values()->all();
        if ($current === $moveIds) {
            return 0;
        }

        return DB::transaction(function () use ($character, $pokemon, $moveIds) {
            $price = (int) config('move_tutor.price', 0);
            if ($price > 0 && ! Character::whereKey($character->id)->lockForUpdate()->first()->spendMoney($price)) {
                throw new InvalidAction("Il vous faut {$price} ₽.");
            }

            $kept = $pokemon->moves->keyBy('move_id');
            $moves = Move::whereIn('id', $moveIds)->get()->keyBy('id');
            $pokemon->moves()->delete();
            foreach ($moveIds as $i => $id) {
                $old = $kept[$id] ?? null;
                $pokemon->moves()->create([
                    'move_id' => $id,
                    'slot' => $i + 1,
                    'current_pp' => $old ? $old->current_pp : $moves[$id]->pp,
                    'pp_ups' => $old ? $old->pp_ups : 0,
                ]);
            }
            $pokemon->unsetRelation('moves');

            return $price;
        });
    }

    /** Capacités apprises par niveau jusqu'au niveau actuel (espèce, et formes précédentes si réglé) */
    private function learnable(Pokemon $pokemon): Collection
    {
        $species = collect([$pokemon->species]);
        if (config('move_tutor.include_pre_evolutions', true)) {
            $current = $pokemon->species;
            for ($i = 0; $i < 5 && ($from = $current->preEvolution?->from); $i++) {
                $species->push($from);
                $current = $from;
            }
        }

        return $species->flatMap(fn ($s) => $s->levelUpMovesUpTo($pokemon->level))
            ->each(fn (Move $m) => $m->loadMissing('type'))
            ->values();
    }

    /** Capacités que ce Pokémon a déjà connues (CT, CS, Œuf...) */
    private function learnedBefore(Pokemon $pokemon): Collection
    {
        $ids = DB::table('pokemon_learned_moves')->where('pokemon_id', $pokemon->id)->pluck('move_id');

        return Move::with('type')->whereIn('id', $ids)->get();
    }

    private function row(Move $move, int $pp, int $maxPp, ?int $slot): array
    {
        $move->loadMissing('type');

        return [
            'id' => $move->id,
            'name' => $move->name,
            'type' => $move->type?->slug,
            'category' => $move->category,
            'power' => $move->power,
            'accuracy' => $move->accuracy,
            'pp' => $pp,
            'maxPp' => $maxPp,
            'description' => PokemonPresenter::clean($move->description),
            'slot' => $slot,                       // 1..4 si le Pokémon la connaît, sinon null
            'hm' => Move::isLockedHm($move->id),
        ];
    }
}
