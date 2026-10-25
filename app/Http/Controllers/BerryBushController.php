<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\BerryBushService;
use Illuminate\Http\Request;

/**
 * Étape 22 : les buissons à Baies.
 *   GET  /jouer/{id}/baies?map=...            les buissons de la carte et leur étape de pousse
 *   POST /jouer/{id}/baies/regarder { map, key }   ce que dit le buisson (et combien de Baies il porte)
 *   POST /jouer/{id}/baies          { map, key }   cueillir
 */
class BerryBushController extends Controller
{
    public function __construct(private BerryBushService $bushes)
    {
    }

    public function index(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate(['map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/']]);

        return response()->json(['bushes' => $this->bushes->bushes($character, $data['map'])]);
    }

    public function inspect(Request $request, Character $character)
    {
        return $this->attempt($request, $character, fn ($data) => $this->bushes->inspect($character, $data['map'], $data['key']));
    }

    public function pick(Request $request, Character $character)
    {
        return $this->attempt($request, $character, fn ($data) => $this->bushes->pick($character, $data['map'], $data['key']));
    }

    private function attempt(Request $request, Character $character, callable $action)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'key' => ['required', 'string', 'max:100'],
        ]);

        try {
            return response()->json($action($data));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
