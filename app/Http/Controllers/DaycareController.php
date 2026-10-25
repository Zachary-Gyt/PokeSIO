<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Pokemon;
use App\Services\BreedingService;
use App\Services\MapInfo;
use Illuminate\Http\Request;

/**
 * Étape 18 : la Pension, appelée par le jeu.
 *   GET  /jouer/{id}/pension?map=...          ce que la Pension garde, l'entente des deux Pokémon
 *   POST /jouer/{id}/pension/confier          { map, pokemon_id }
 *   POST /jouer/{id}/pension/reprendre        { map, pokemon_id }
 *   POST /jouer/{id}/pas                      { steps }  les pas du joueur (Exp., Œufs, éclosions)
 *
 * Confier et reprendre : seulement sur une carte où un PNJ a la propriété Tiled action = daycare.
 */
class DaycareController extends Controller
{
    public function __construct(private BreedingService $breeding)
    {
    }

    public function show(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return response()->json($this->breeding->state($character));
    }

    public function deposit(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $this->validated($request);

        try {
            $message = $this->breeding->deposit($character, Pokemon::findOrFail($data['pokemon_id']));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message, 'state' => $this->breeding->state($character->fresh()), 'status' => app(\App\Services\FieldItemService::class)->stepStatus($character)]);
    }

    public function withdraw(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $this->validated($request);

        try {
            $result = $this->breeding->withdraw($character, $data['pokemon_id']);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result + ['state' => $this->breeding->state($character->fresh()), 'status' => app(\App\Services\FieldItemService::class)->stepStatus($character)]);
    }

    public function steps(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['steps' => ['required', 'integer', 'min:1', 'max:200']]);

        // Étape 19 : les mêmes pas font aussi baisser le Repousse
        $fieldItems = app(\App\Services\FieldItemService::class);
        $result = $this->breeding->steps($character, $data['steps']);
        $result += $fieldItems->steps($character->fresh(), $result['steps']);
        // Étape 22 : marcher rend les Pokémon de l'équipe plus heureux (+1 tous les 128 pas)
        app(\App\Services\FriendshipService::class)->steps($character->fresh(), $result['steps']);
        // Étape 26 : la batterie du Cherche VS se recharge
        app(\App\Services\RematchService::class)->steps($character->fresh(), $result['steps']);
        $result['status'] = $fieldItems->stepStatus($character->fresh());

        return response()->json($result);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'pokemon_id' => ['required', 'integer'],
        ]);
        abort_unless(MapInfo::load($data['map'])?->hasAction('daycare'), 422, 'Il n\'y a pas de Pension ici.');

        return $data;
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
