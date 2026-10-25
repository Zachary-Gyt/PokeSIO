<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\ItemBallService;
use Illuminate\Http\Request;

/**
 * Étape 16 : les Poké Balls par terre.
 *   GET  /jouer/{id}/objets-carte?map=...   les Balls encore là (les autres ont été ramassées)
 *   POST /jouer/{id}/objets-carte           { map, key }  ramasser
 */
class ItemBallController extends Controller
{
    public function __construct(private ItemBallService $balls)
    {
    }

    public function index(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate(['map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/']]);

        return response()->json(['balls' => $this->balls->remaining($character, $data['map'])]);
    }

    public function pick(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'key' => ['required', 'string', 'max:100'],
        ]);

        try {
            return response()->json($this->balls->pick($character, $data['map'], $data['key']));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
