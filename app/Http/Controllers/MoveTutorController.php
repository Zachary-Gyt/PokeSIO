<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\MapInfo;
use App\Services\MoveTutorService;
use App\Services\PokemonPresenter;
use Illuminate\Http\Request;

/**
 * Étape 19 : le Maître des capacités (PNJ Tiled : action = move_tutor).
 *   GET  /jouer/{id}/maitre-capacites?map=...&pokemon_id=...    les capacités proposées
 *   POST /jouer/{id}/maitre-capacites  { map, pokemon_id, moves: [id, id...] }   1 à 4 capacités, dans l'ordre
 */
class MoveTutorController extends Controller
{
    public function __construct(private MoveTutorService $tutor)
    {
    }

    public function show(Request $request, Character $character)
    {
        $data = $this->check($request, $character);
        $pokemon = $this->pokemon($character, $data['pokemon_id']);

        return response()->json([
            'pokemon' => PokemonPresenter::detail($pokemon),
            'moves' => $this->tutor->options($pokemon),
            'price' => (int) config('move_tutor.price', 0),
        ]);
    }

    public function update(Request $request, Character $character)
    {
        $data = $this->check($request, $character, [
            'moves' => ['required', 'array', 'min:1', 'max:4'],
            'moves.*' => ['integer'],
        ]);
        $pokemon = $this->pokemon($character, $data['pokemon_id']);

        try {
            $price = $this->tutor->apply($character, $pokemon, $data['moves']);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'price' => $price,
            'money' => (int) $character->fresh()->money,
            'pokemon' => PokemonPresenter::detail($pokemon->fresh()),
        ]);
    }

    private function check(Request $request, Character $character, array $rules = []): array
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'pokemon_id' => ['required', 'integer'],
        ] + $rules);
        abort_unless(MapInfo::load($data['map'])?->hasAction('move_tutor'), 422, 'Il n\'y a pas de Maître des capacités ici.');

        return $data;
    }

    /** Un Pokémon de l'équipe (pas un Œuf) */
    private function pokemon(Character $character, int $id)
    {
        $pokemon = $character->party()->with(PokemonPresenter::RELATIONS)->findOrFail($id);
        abort_if($pokemon->is_egg, 422, 'Un Œuf ne connaît aucune capacité !');

        return $pokemon;
    }
}
