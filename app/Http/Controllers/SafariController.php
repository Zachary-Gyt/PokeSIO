<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\SafariService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Étape 15 : le Parc Safari.
 *   POST /jouer/{id}/safari/entrer   { map, x, y, facing }  le gardien : payer l'entrée
 *   POST /jouer/{id}/safari/pas      { steps }              des pas dans le Parc
 *   POST /jouer/{id}/safari/quitter                         le joueur est sorti du Parc
 */
class SafariController extends Controller
{
    public function __construct(private SafariService $safari)
    {
    }

    public function enter(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'facing' => ['required', Rule::in(['up', 'down', 'left', 'right'])],
            'zone' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],   // plusieurs zones : celle choisie
        ]);

        try {
            return response()->json($this->safari->enter($character, $data['map'], $data['x'], $data['y'], $data['facing'], $data['zone'] ?? null));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function steps(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['steps' => ['required', 'integer', 'min:1', 'max:50']]);

        return response()->json($this->safari->steps($character, $data['steps']));
    }

    public function leave(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $this->safari->end($character);

        return response()->json(['ended' => true]);
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
