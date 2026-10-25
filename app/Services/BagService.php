<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;

/**
 * Le Sac, rangé comme celui d'Essentials : 8 poches.
 *   1 Objets   2 Soins   3 Poké Balls   4 CT et CS   5 Baies   6 Lettres   7 Objets combat   8 Objets rares
 */
class BagService
{
    public const POCKETS = [
        1 => 'Objets', 2 => 'Soins', 3 => 'Poké Balls', 4 => 'CT et CS',
        5 => 'Baies', 6 => 'Lettres', 7 => 'Objets combat', 8 => 'Objets rares',
    ];

    /** Notre colonne items.pocket -> numéro de poche d'Essentials */
    public const POCKET_OF = [
        'misc' => 1, 'held_items' => 1, 'medicine' => 2, 'balls' => 3, 'tms' => 4, 'berries' => 5, 'key_items' => 8,
        'mail' => 6, 'battle' => 7,   // étape 22 : Lettres, Objets combat
    ];

    /** Effets utilisables hors combat sur un Pokémon */
    public const FIELD_EFFECTS = [
        'heal_hp', 'heal_hp_full', 'heal_hp_percent', 'full_restore', 'cure_status', 'revive',
        'restore_pp', 'restore_pp_all', 'level_up', 'add_ev', 'evolve', 'teach',
        'pp_up', 'ev_down',   // étape 22 : PP Plus / PP Max, Baies qui baissent les EV
    ];

    /** Étape 22 : effets utilisables hors combat SANS choisir de Pokémon (traités par FieldItemService) */
    public const FIELD_TOOLS = ['repel', 'escape', 'coin_case', 'revive_all', 'encounter_flute', 'vs_seeker'];   // étape 26 : Cherche VS

    /** Effets utilisables en combat */
    public const BATTLE_EFFECTS = [
        'heal_hp', 'heal_hp_full', 'heal_hp_percent', 'full_restore', 'cure_status', 'revive', 'catch',
        // Étape 22 : Huiles / Élixirs, objets X, Muscle +, Garde-Stats, Flûtes, Poupée Pokémon, Baie Kika
        'restore_pp', 'restore_pp_all', 'battle_stat', 'dire_hit', 'guard_spec', 'cure_confusion', 'cure_attract', 'flee_wild',
    ];

    /** Étape 22 : en combat, ces objets s'utilisent sur le Pokémon au combat (pas de choix dans l'équipe) */
    public const ON_ACTIVE = ['catch', 'battle_stat', 'dire_hit', 'guard_spec', 'cure_confusion', 'cure_attract', 'flee_wild'];

    /** Le contenu du Sac : ['names' => [...], 'pockets' => [1 => [objets...], ...]] */
    public function contents(Character $character): array
    {
        $pockets = array_fill_keys(array_keys(self::POCKETS), []);

        $items = $character->items()->orderBy('items.id')->get();
        foreach ($items as $item) {
            $pocket = self::POCKET_OF[$item->pocket] ?? 1;
            $pockets[$pocket][] = $this->itemData($item, $item->pivot->quantity);
        }

        return ['names' => self::POCKETS, 'pockets' => $pockets];
    }

    public function itemData(Item $item, int $quantity): array
    {
        return [
            'id' => $item->id,
            'slug' => $item->slug,
            'name' => $item->name,
            'icon' => PokemonPresenter::itemIcon($item),
            'description' => PokemonPresenter::clean($item->description),
            'quantity' => $quantity,
            'sellPrice' => ShopService::sellPriceOf($item), // prix de revente à la Boutique
            'effect' => $item->effect_code,
            'key' => $item->pocket === 'key_items',
            // Étape 14 : peut être donné à tenir à un Pokémon ; la Bicyclette s'"utilise" pour monter dessus
            'holdable' => (bool) $item->holdable,
            'ride' => $item->effect_code === 'ride',
            'useField' => in_array($item->effect_code, self::FIELD_EFFECTS, true),
            // Étape 19 : Repousse et Corde Sortie s'utilisent sans choisir de Pokémon
            // Étape 20 : la Boîte Jetons affiche les jetons
            // Étape 22 : Cendre Sacrée (toute l'équipe), Flûtes Noire / Blanche
            'fieldTool' => in_array($item->effect_code, self::FIELD_TOOLS, true),
            'useBattle' => in_array($item->effect_code, self::BATTLE_EFFECTS, true),
            // Une Ball se lance sur l'adversaire ; tout le reste se donne à un de ses Pokémon
            'needsPokemon' => ! in_array($item->effect_code, self::ON_ACTIVE, true),
            // Huile, Huile Max, PP Plus, PP Max : il faut aussi choisir l'attaque
            'needsMove' => in_array($item->effect_code, ['restore_pp', 'pp_up'], true),
            // CT / CS : la capacité enseignée (l'écran d'équipe affiche APTE / PAS APTE)
            'moveId' => $item->effect_code === 'teach' ? $item->move_id : null,
        ];
    }
}
