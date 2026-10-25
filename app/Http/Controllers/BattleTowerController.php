<?php

namespace App\Http\Controllers;

use App\Battle\BattlePresenter;
use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\BattleService;
use App\Services\BattleTowerService;
use App\Services\MapInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Étape 28 : la Tour de Combat (le comptoir : PNJ avec la propriété Tiled action = battle_tower).
 *   GET  /jouer/{id}/tour                                   la série en cours, le record, les Pokémon possibles
 *   POST /jouer/{id}/tour/commencer  { map, pokemon_ids }   commencer une série avec 3 Pokémon
 *   POST /jouer/{id}/tour/combat     { map }                le combat suivant
 *   POST /jouer/{id}/tour/abandonner                        arrêter la série
 *   GET  /jouer/{id}/tour/classement                        les 10 meilleures séries (panneau du hall)
 */
class BattleTowerController extends Controller
{
    public function __construct(private BattleTowerService $tower)
    {
    }

    public function show(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return response()->json($this->tower->status($character));
    }

    public function start(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'pokemon_ids' => ['required', 'array', 'max:6'],
            'pokemon_ids.*' => ['integer'],
        ]);
        $this->checkMap($data['map']);

        return $this->attempt(fn () => $this->tower->start($character, $data['pokemon_ids']));
    }

    public function battle(Request $request, Character $character, BattleService $battles)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/']]);
        $this->checkMap($data['map']);

        return $this->attempt(function () use ($character, $battles) {
            $battle = DB::transaction(fn () => $this->tower->nextBattle(Character::whereKey($character->id)->lockForUpdate()->first()));
            $foe = $battle->state['foe'];

            return [
                'events' => BattlePresenter::events($battle->turn === 0 ? ($battle->log[0]['events'] ?? []) : []),
                'battle' => $battles->view($battle),
                'trainer' => ['name' => "{$foe['class']} {$foe['name']}", 'intro' => $foe['intro'] ?? null, 'tycoon' => $foe['tycoon'] ?? null],
                'number' => ($this->tower->run($character->fresh())['streak'] ?? 0) + 1,
            ];
        });
    }

    public function quit(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $run = $this->tower->run($character);
        if ($run) {
            $this->tower->end($character, $run['streak']);
        }

        return response()->json(['ended' => true, 'streak' => $run['streak'] ?? 0, 'best' => $this->tower->best($character)]);
    }

    public function leaderboard(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return response()->json(['records' => $this->tower->leaderboard()->map(fn ($r) => [
            'name' => $r->character?->name ?? '?', 'streak' => $r->best_streak, 'team' => $r->best_team ?? [],
        ])->values()]);
    }

    private function checkMap(string $map): void
    {
        abort_unless(MapInfo::load($map)?->hasAction('battle_tower'), 422, 'Il n\'y a pas de Tour de Combat ici.');
    }

    private function attempt(callable $action)
    {
        try {
            return response()->json($action());
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
