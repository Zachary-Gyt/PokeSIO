<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Move;
use App\Models\Pokemon;
use App\Models\PokemonMove;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Étape 19 : le MAÎTRE DES CAPACITÉS (PNJ Tiled : action = move_tutor).
 *
 * Le joueur voit toutes les capacités que son Pokémon peut connaître :
 *   - celles qu'il connaît déjà (dont les CS et les CT) ;
 *   - celles que son espèce apprend par niveau jusqu'à son niveau (et ses formes précédentes, voir config).
 * Il en choisit 1 à 4, dans l'ordre qu'il veut : c'est ainsi qu'on réapprend une capacité oubliée,
 * qu'on oublie une CS, ou qu'on change l'ordre des capacités.
 */
class MoveTutorService
{
    /** Les capacités proposées pour ce Pokémon (avec, pour celles qu'il connaît, leur place 1..4) */
    public function options(Pokemon $pokemon): array
    {
        $pokemon->loadMissing('species', 'moves.move.type');
        $known = $pokemon->moves->sortBy('slot')->values();

        $list = [];
        foreach ($known as $pm) {
            $list[$pm->move_id] = $this->row($pm->move, $pm->current_pp, $pm->max_pp, $pm->slot);
        }
        foreach ($this->learnable($pokemon) as $move) {
            $list[$move->id] ??= $this->row($move, $move->pp, $move->pp, null);
        }

        return array_values($list);
    }

    /**
     * Enregistre les capacités choisies ($moveIds : 1 à 4 ids, dans l'ordre).
     * Les capacités gardées conservent leurs PP (et leurs PP Plus) ; les nouvelles ont leurs PP au maximum.
     * Renvoie le prix payé.
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
            return 0;   // rien ne change : gratuit
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
                /** @var PokemonMove|null $old */
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
            ->unique('id')
            ->sortBy(fn (Move $m) => $m->name)
            ->values();
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
