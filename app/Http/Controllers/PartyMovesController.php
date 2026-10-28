<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\MoveTutorService;
use App\Services\PokemonPresenter;
use Illuminate\Http\Request;

/**
 * Changer les capacités d'un Pokémon de l'équipe, depuis le Résumé (page Capacités).
 *   GET  /jouer/{id}/equipe/capacites?pokemon_id=...              les capacités qu'il peut reprendre
 *   POST /jouer/{id}/equipe/capacites  { pokemon_id, slot, move_id }   remplace la capacité n° slot (1..4)
 */
class PartyMovesController extends Controller
{
    public function __construct(private MoveTutorService $moves)
    {
    }

    public function index(Request $request, Character $character)
    {
        $pokemon = $this->pokemon($request, $character);

        return response()->json(['moves' => $this->moves->options($pokemon)]);
    }

    public function replace(Request $request, Character $character)
    {
        $data = $request->validate([
            'slot' => ['required', 'integer', 'between:1,4'],
            'move_id' => ['required', 'integer'],
        ]);
        $pokemon = $this->pokemon($request, $character);

        try {
            $this->moves->replace($character, $pokemon, $data['slot'], $data['move_id']);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['pokemon' => PokemonPresenter::detail($pokemon->fresh(PokemonPresenter::RELATIONS))]);
    }

    /** Un Pokémon de l'équipe du joueur (pas un Œuf) */
    private function pokemon(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $id = (int) $request->validate(['pokemon_id' => ['required', 'integer']])['pokemon_id'];
        $pokemon = $character->party()->with(PokemonPresenter::RELATIONS)->findOrFail($id);
        abort_if($pokemon->is_egg, 422, 'Un Œuf ne connaît aucune capacité !');

        return $pokemon;
    }
}
