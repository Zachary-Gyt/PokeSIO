<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Services\BagService;
use App\Services\MapInfo;
use App\Services\ShopService;
use Illuminate\Http\Request;

/**
 * La Boutique, appelée par le jeu quand le joueur parle à un vendeur (PNJ action = shop).
 * Le serveur vérifie que la carte a bien un vendeur de cette boutique.
 */
class ShopController extends Controller
{
    public function __construct(private ShopService $shops, private BagService $bag)
    {
    }

    /** Ce que vend la boutique, l'argent du joueur et son Sac (pour vendre) */
    public function index(Request $request, Character $character)
    {
        $shop = $this->authorizeShop($request, $character);

        try {
            return response()->json($this->state($character, $shop));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function buy(Request $request, Character $character)
    {
        $shop = $this->authorizeShop($request, $character);
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $messages = $this->shops->buy($character, $shop, Item::findOrFail($data['item_id']), $data['quantity']);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['messages' => $messages] + $this->state($character->fresh(), $shop));
    }

    public function sell(Request $request, Character $character)
    {
        $shop = $this->authorizeShop($request, $character);
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        try {
            $total = $this->shops->sell($character, Item::findOrFail($data['item_id']), $data['quantity']);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['earned' => $total] + $this->state($character->fresh(), $shop));
    }

    private function state(Character $character, string $shop): array
    {
        return [
            'stock' => $this->shops->stock($character, $shop),
            'money' => $character->money,
            'bag' => $this->bag->contents($character),
        ];
    }

    /** La boutique demandée, si la carte a bien un vendeur pour elle (sinon 422) */
    private function authorizeShop(Request $request, Character $character): string
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'map' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
            'shop' => ['nullable', 'string', 'regex:/^[a-z0-9_]+$/'],
        ]);
        $shop = $data['shop'] ?? 'default';
        $map = MapInfo::load($data['map']);
        abort_unless($map && $map->hasShop($shop), 422, 'Il n\'y a pas de vendeur ici.');

        return $shop;
    }
}
