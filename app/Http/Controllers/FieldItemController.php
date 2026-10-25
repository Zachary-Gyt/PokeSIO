<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Services\BagService;
use App\Services\FieldItemService;
use Illuminate\Http\Request;

/**
 * Étape 19 : utiliser un objet de terrain depuis le Sac (sans choisir de Pokémon).
 *   POST /jouer/{id}/objet-terrain   { item_id, map }
 *     Repousse     -> { repel: 100, message, bag, status }
 *     Corde Sortie -> { to: { map, x, y, facing }, message, bag }
 */
class FieldItemController extends Controller
{
    public function __construct(private FieldItemService $fieldItems, private BagService $bag)
    {
    }

    public function use(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $item = Item::find($data['item_id']);
        if (! $item || ! $character->hasItem($item)) {
            return response()->json(['message' => 'Vous n\'avez pas cet objet.'], 422);
        }

        try {
            $result = match ($item->effect_code) {
                'repel' => $this->fieldItems->useRepel($character, $item),
                'escape' => $this->fieldItems->useEscapeRope($character, $item, $data['map']),
                // Étape 20 : la Boîte Jetons n'est pas consommée, elle dit juste combien de jetons on a
                'coin_case' => ['coins' => (int) $character->coins, 'message' => 'Jetons : ' . number_format((int) $character->coins, 0, ',', ' ') . '.'],
                // Étape 22 : Cendre Sacrée, Flûtes Noire et Blanche
                'revive_all' => $this->fieldItems->useSacredAsh($character, $item),
                'encounter_flute' => $this->fieldItems->useFlute($character, $item, $data['map']),
                default => throw new InvalidAction('Ce n\'est pas le moment d\'utiliser ça !'),
            };
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result + [
            'bag' => $this->bag->contents($character->fresh()),
            'status' => $this->fieldItems->stepStatus($character->fresh()),
        ]);
    }
}
