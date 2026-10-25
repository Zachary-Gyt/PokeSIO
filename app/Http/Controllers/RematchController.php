<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\RematchService;
use Illuminate\Http\Request;

/**
 * Étape 26 : le Cherche VS.
 *   POST /jouer/{id}/cherche-vs { map, x, y }  ->  { ready: [slugs des dresseurs qui veulent une revanche], message, charge }
 */
class RematchController extends Controller
{
    public function vsSeeker(Request $request, Character $character, RematchService $rematches)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'x' => ['required', 'integer'],
            'y' => ['required', 'integer'],
        ]);

        try {
            return response()->json($rematches->use($character, $data['map'], $data['x'], $data['y']));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
