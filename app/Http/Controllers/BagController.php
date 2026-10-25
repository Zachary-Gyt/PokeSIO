<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Services\BagService;
use App\Services\ItemUseService;
use App\Services\PokemonPresenter;
use Illuminate\Http\Request;

/**
 * Le Sac, depuis le menu du jeu : contenu, utiliser un objet sur un Pokémon, jeter.
 */
class BagController extends Controller
{
    public function __construct(private BagService $bag, private ItemUseService $items)
    {
    }

    public function index(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return response()->json($this->bag->contents($character) + ['money' => $character->money]);
    }

    public function use(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'pokemon_id' => ['required', 'integer'],
            'move_slot' => ['nullable', 'integer', 'between:1,4'],
        ]);

        $item = Item::find($data['item_id']);
        if (! $item || ! $character->hasItem($item)) {
            return response()->json(['message' => 'Vous n\'avez pas cet objet.'], 422);
        }
        $pokemon = $character->pokemon()->where('location', 'party')->with(PokemonPresenter::RELATIONS)->findOrFail($data['pokemon_id']);

        try {
            $result = $this->items->use($character, $item, $pokemon, $data['move_slot'] ?? null);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result + [
            'pokemon' => PokemonPresenter::detail($pokemon->fresh()),
            'bag' => $this->bag->contents($character),
        ]);
    }

    /** Jeter des objets (pas les objets rares) */
    public function toss(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $item = Item::find($data['item_id']);
        if (! $item || $item->pocket === 'key_items') {
            return response()->json(['message' => 'Impossible de jeter cet objet.'], 422);
        }
        if (! $character->removeItem($item, $data['quantity'])) {
            return response()->json(['message' => 'Vous n\'en avez pas autant.'], 422);
        }

        return response()->json(['bag' => $this->bag->contents($character)]);
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
