<?php

namespace App\Http\Controllers;

use App\Battle\BattlePresenter;
use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Services\BattleService;
use App\Services\FishingService;
use Illuminate\Http\Request;

/**
 * Étape 15 : la pêche.
 *   POST /jouer/{id}/peche/lancer  { map, x, y, item_id }  ->  { bite, wait, reaction }
 *   POST /jouer/{id}/peche/ferrer                          ->  { battle: { events, battle } }  ou  { escaped: true }
 */
class FishingController extends Controller
{
    public function __construct(private FishingService $fishing, private BattleService $battles)
    {
    }

    public function cast(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
        ]);

        try {
            return response()->json($this->fishing->cast($character, $data['map'], $data['x'], $data['y'], Item::find($data['item_id'])));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function hook(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $battle = $this->fishing->hook($character);
        if (! $battle) {
            return response()->json(['escaped' => true]);
        }

        return response()->json(['battle' => [
            'events' => BattlePresenter::events($battle->log[0]['events'] ?? []),
            'battle' => $this->battles->view($battle),
        ]]);
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
