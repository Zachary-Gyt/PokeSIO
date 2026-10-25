<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\MapInfo;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le PC de stockage. On ne peut l'ouvrir que sur une carte qui a un PC
 * (objet Tiled avec la propriété action = pc), comme dans les jeux.
 */
class StorageController extends Controller
{
    public function __construct(private StorageService $storage)
    {
    }

    public function index(Request $request, Character $character)
    {
        $this->authorizeAccess($request, $character);

        return response()->json($this->storage->contents($character));
    }

    public function move(Request $request, Character $character)
    {
        $this->authorizeAccess($request, $character);
        $data = $request->validate([
            'pokemon_id' => ['required', 'integer'],
            'location' => ['required', Rule::in(['party', 'box'])],
            'box' => ['nullable', 'integer', 'between:1,8'],
            'slot' => ['nullable', 'integer', 'between:1,30'],
        ]);

        $pokemon = $character->pokemon()->whereIn('location', ['party', 'box'])->findOrFail($data['pokemon_id']); // étape 18 : pas ceux de la Pension
        try {
            $this->storage->move($character, $pokemon, $data);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->storage->contents($character));
    }

    public function release(Request $request, Character $character)
    {
        $this->authorizeAccess($request, $character);
        $data = $request->validate(['pokemon_id' => ['required', 'integer']]);

        $pokemon = $character->pokemon()->whereIn('location', ['party', 'box'])->findOrFail($data['pokemon_id']); // étape 18 : pas ceux de la Pension
        try {
            $this->storage->release($character, $pokemon);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->storage->contents($character));
    }

    private function authorizeAccess(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);

        $map = MapInfo::load($character->current_map);
        abort_unless($map && $map->hasAction('pc'), 403, 'Il n\'y a pas de PC ici.');
    }
}
