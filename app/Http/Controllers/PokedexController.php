<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\PokemonSpecies;
use App\Services\PokedexService;
use Illuminate\Http\Request;

/**
 * Le Pokédex dans le jeu (étape 13), appelé par le menu :
 *   GET  /jouer/{id}/pokedex                    la liste (vus / capturés)
 *   GET  /jouer/{id}/pokedex/{espece}           la fiche d'un Pokémon VU (404 sinon)
 *   POST /jouer/{id}/pokedex/recompenses-vues   les messages de récompense ont été affichés
 */
class PokedexController extends Controller
{
    public function __construct(private PokedexService $pokedex)
    {
    }

    public function index(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return response()->json($this->pokedex->list($character));
    }

    public function show(Request $request, Character $character, PokemonSpecies $species)
    {
        $this->authorizeCharacter($request, $character);
        $detail = $this->pokedex->detail($character, $species);
        abort_unless($detail, 404);

        return response()->json($detail);
    }

    public function acknowledge(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $this->pokedex->acknowledge($character);

        return response()->json(['ok' => true]);
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
