<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Pokemon;
use Illuminate\Support\Facades\DB;

/**
 * Le PC de stockage : 8 boîtes de 30 cases (6 x 5), et l'équipe de 6.
 *
 * Règles (comme dans les jeux) :
 *   - l'équipe garde toujours au moins un Pokémon en état de se battre ;
 *   - les Pokémon de l'équipe restent "tassés" (cases 1, 2, 3... sans trou) ;
 *   - poser un Pokémon sur une case occupée les ÉCHANGE.
 */
class StorageService
{
    /** Tout le PC : ['boxes' => [8 x 30 cases], 'party' => [...]] */
    public function contents(Character $character): array
    {
        $all = $character->pokemon()->with(PokemonPresenter::RELATIONS)->get();

        $boxes = [];
        for ($b = 1; $b <= Pokemon::BOX_COUNT; $b++) {
            $slots = array_fill(0, Pokemon::BOX_SIZE, null);
            foreach ($all->where('location', 'box')->where('box_number', $b) as $p) {
                $slots[$p->box_slot - 1] = PokemonPresenter::detail($p);
            }
            $boxes[] = ['name' => "Boîte {$b}", 'wallpaper' => ($b - 1) % 40, 'slots' => $slots];
        }

        $party = $all->where('location', 'party')->sortBy('party_slot')->map(fn ($p) => PokemonPresenter::detail($p))->values()->all();

        return ['boxes' => $boxes, 'party' => $party];
    }

    /**
     * Déplace un Pokémon. $to = ['location' => 'party'] (à la fin de l'équipe, ou sur 'slot')
     *                     ou ['location' => 'box', 'box' => 1..8, 'slot' => 1..30]
     */
    public function move(Character $character, Pokemon $pokemon, array $to): void
    {
        DB::transaction(function () use ($character, $pokemon, $to) {
            $from = ['location' => $pokemon->location, 'party_slot' => $pokemon->party_slot, 'box_number' => $pokemon->box_number, 'box_slot' => $pokemon->box_slot];

            if ($to['location'] === 'party') {
                $partyCount = $character->party()->count();
                $slot = isset($to['slot']) ? (int) $to['slot'] : null;
                if ($slot === null || $slot > $partyCount) {
                    // Pas de case précise (ou case vide) : à la fin de l'équipe
                    if ($pokemon->location !== 'party' && $partyCount >= Pokemon::PARTY_SIZE) {
                        throw new InvalidAction('Votre équipe est pleine !');
                    }
                    $slot = $pokemon->location === 'party' ? $partyCount : $partyCount + 1;
                }
                $target = ['location' => 'party', 'party_slot' => $slot, 'box_number' => null, 'box_slot' => null];
            } else {
                $box = (int) $to['box'];
                // Pas de case précise ("Déposer") : première case libre de cette boîte, sinon de n'importe laquelle
                if (empty($to['slot'])) {
                    $free = $this->firstFreeSlot($character, $box) ?? $character->nextFreeBoxSlot();
                    if (! $free) {
                        throw new InvalidAction('Le PC est plein !');
                    }
                    ['box_number' => $box, 'box_slot' => $to['slot']] = $free;
                }
                $slot = (int) $to['slot'];
                if ($box < 1 || $box > Pokemon::BOX_COUNT || $slot < 1 || $slot > Pokemon::BOX_SIZE) {
                    throw new InvalidAction('Case inconnue.');
                }
                $target = ['location' => 'box', 'party_slot' => null, 'box_number' => $box, 'box_slot' => $slot];
            }

            // Le Pokémon déjà sur la case d'arrivée (il prendra la place de l'autre)
            $occupant = $character->pokemon()
                ->where('id', '!=', $pokemon->id)
                ->when($target['location'] === 'party',
                    fn ($q) => $q->where('location', 'party')->where('party_slot', $target['party_slot']),
                    fn ($q) => $q->where('location', 'box')->where('box_number', $target['box_number'])->where('box_slot', $target['box_slot']))
                ->first();

            // On libère les cases (index uniques) avant de réécrire
            $pokemon->forceFill(['location' => 'box', 'party_slot' => null, 'box_number' => null, 'box_slot' => null])->save();
            if ($occupant) {
                $occupant->forceFill(['party_slot' => null, 'box_number' => null, 'box_slot' => null])->save();
                $occupant->forceFill($from)->save();
            }
            $pokemon->forceFill($target)->save();

            $this->compactParty($character);
            $this->checkParty($character);
        });
    }

    /** Relâcher un Pokémon (définitif) */
    public function release(Character $character, Pokemon $pokemon): void
    {
        if ($pokemon->is_egg) {
            throw new InvalidAction('Vous ne pouvez pas relâcher un Œuf !');   // étape 18
        }
        DB::transaction(function () use ($character, $pokemon) {
            $pokemon->delete();
            $this->compactParty($character);
            $this->checkParty($character);
        });
    }

    /** Première case libre d'une boîte : ['box_number' => b, 'box_slot' => s] ou null */
    private function firstFreeSlot(Character $character, int $box): ?array
    {
        $used = $character->pokemon()->where('location', 'box')->where('box_number', $box)->pluck('box_slot')->all();
        for ($slot = 1; $slot <= Pokemon::BOX_SIZE; $slot++) {
            if (! in_array($slot, $used, true)) {
                return ['box_number' => $box, 'box_slot' => $slot];
            }
        }

        return null;
    }

    /** Équipe sans trou : 1, 2, 3... */
    private function compactParty(Character $character): void
    {
        $party = $character->pokemon()->where('location', 'party')->orderBy('party_slot')->get();
        // Deux passes pour ne jamais violer l'index unique (character_id, party_slot)
        foreach ($party as $i => $p) {
            $p->forceFill(['party_slot' => 100 + $i])->save();
        }
        foreach ($party as $i => $p) {
            $p->forceFill(['party_slot' => $i + 1])->save();
        }
    }

    /** L'équipe doit garder au moins un Pokémon capable de se battre (sinon on annule tout) */
    private function checkParty(Character $character): void
    {
        if (! $character->party()->where('current_hp', '>', 0)->exists()) {
            throw new InvalidAction('C\'est votre dernier Pokémon en état de se battre !');
        }
    }
}
