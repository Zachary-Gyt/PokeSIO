<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Models\Pokemon;
use Illuminate\Support\Facades\DB;

/**
 * Étape 14 : les OBJETS TENUS. Un Pokémon tient un seul objet (colonne pokemon.held_item_id).
 *
 *   donner : l'objet quitte le Sac ; si le Pokémon tenait déjà quelque chose, cet objet revient dans le Sac
 *   prendre : l'objet revient dans le Sac
 *
 * Ce que fait chaque objet en combat : config/held_items.php (lu par BattleFactory, puis par le moteur).
 */
class HeldItemService
{
    /** Donne un objet du Sac à un Pokémon ; renvoie le message à afficher */
    public function give(Character $character, Pokemon $pokemon, Item $item): string
    {
        if ($pokemon->is_egg) {
            throw new InvalidAction('Un Œuf ne peut pas tenir d\'objet.');   // étape 18
        }
        if (! $item->holdable) {
            throw new InvalidAction("{$item->name} ne peut pas être tenu par un Pokémon.");
        }
        if (! $character->hasItem($item)) {
            throw new InvalidAction('Vous n\'avez pas cet objet.');
        }

        return DB::transaction(function () use ($character, $pokemon, $item) {
            $name = $pokemon->nickname ?: $pokemon->species->name;
            $old = $pokemon->heldItem;

            $character->removeItem($item);
            if ($old) {
                $character->addItem($old);
            }
            $pokemon->forceFill(['held_item_id' => $item->id])->save();

            return $old
                ? "{$name} tient maintenant {$item->name}. {$old->name} a été rangé dans le Sac."
                : "{$name} tient maintenant {$item->name}.";
        });
    }

    /** Reprend l'objet tenu ; renvoie le message à afficher */
    public function take(Character $character, Pokemon $pokemon): string
    {
        $item = $pokemon->heldItem;
        $name = $pokemon->nickname ?: $pokemon->species->name;
        if (! $item) {
            throw new InvalidAction("{$name} ne tient aucun objet.");
        }

        DB::transaction(function () use ($character, $pokemon, $item) {
            $pokemon->forceFill(['held_item_id' => null])->save();
            $character->addItem($item);
        });

        return "Vous prenez {$item->name} à {$name}.";
    }

    /**
     * L'objet tenu, tel que le moteur de combat le lit :
     *   ['id' => 12, 'slug' => 'leftovers', 'name' => 'Restes', 'effect' => ['effect' => 'leftovers', 'value' => 16]]
     * effect = null : l'objet n'a pas d'effet en combat (Potion tenue...).
     */
    public static function battleData(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }

        return [
            'id' => $item->id,
            'slug' => $item->slug,
            'name' => $item->name,
            'effect' => config("held_items.items.{$item->slug}"),
        ];
    }

    /** L'effet de l'objet tenu par ce Pokémon (ex. 'no_evolve' pour la Pierre Stase), ou null */
    public static function effectOf(Pokemon $pokemon): ?string
    {
        $slug = $pokemon->heldItem?->slug;

        return $slug ? config("held_items.items.{$slug}.effect") : null;
    }
}
