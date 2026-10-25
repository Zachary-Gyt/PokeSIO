<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Item;
use App\Services\MapInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Étape 14 : un PNJ qui OFFRE un objet (la Bicyclette, un Multi Exp...), une seule fois par personnage.
 *
 * Dans Tiled, le PNJ a les propriétés :
 *   action   = gift
 *   item     = slug de l'objet (ex. bicycle)
 *   quantity = nombre (facultatif, 1 par défaut)
 *   gift     = identifiant du cadeau (facultatif : le slug de l'objet par défaut)
 *   text     = ce qu'il dit avant de donner l'objet ; after = ce qu'il dit ensuite (facultatif)
 *
 * Le serveur relit la carte : le navigateur ne peut pas inventer un cadeau qui n'existe pas.
 * Les cadeaux reçus sont notés dans characters.story_flags['gifts'].
 *
 *   POST /jouer/{id}/cadeau  { map, gift }  ->  { given: true, message }  ou  { given: false }
 */
class GiftController extends Controller
{
    public function claim(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'gift' => ['required', 'string', 'max:100'],
        ]);

        $gift = MapInfo::load($data['map'])?->gift($data['gift']);
        $item = $gift ? Item::where('slug', $gift['item'])->first() : null;
        if (! $item) {
            return response()->json(['message' => 'Ce cadeau n\'existe pas.'], 422);
        }

        $key = "{$data['map']}:{$data['gift']}";
        // Ligne verrouillée : deux appuis très rapides ne donnent pas deux fois le cadeau
        $given = DB::transaction(function () use ($character, $key, $item, $gift) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $flags = $character->story_flags ?? [];
            if (in_array($key, $flags['gifts'] ?? [], true)) {
                return false;
            }
            $flags['gifts'][] = $key;
            $character->forceFill(['story_flags' => $flags])->save();
            $character->addItem($item, $gift['quantity']);

            return true;
        });
        if (! $given) {
            return response()->json(['given' => false]);
        }

        $what = $gift['quantity'] > 1 ? "{$gift['quantity']} {$item->name}" : $item->name;

        return response()->json([
            'given' => true,
            'message' => "{$character->name} obtient {$what} !",
            'pocket' => $item->pocket === 'key_items' ? 'Objets rares' : null,
            'bike' => $item->effect_code === 'ride',
        ]);
    }
}
