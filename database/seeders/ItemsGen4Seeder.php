<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Étape 22 : les objets des générations 1 à 4 et leur effet (database/data/items_gen4.php).
 *
 *   php artisan db:seed --class=ItemsGen4Seeder
 *
 * - Un objet absent de ta base est CRÉÉ (nom, description, prix, poche, effet).
 * - Un objet déjà présent (importé de PokéAPI) garde son nom, sa description et son prix ;
 *   on complète seulement sa poche, ses options (combat, hors combat, tenu) et son effet s'il n'en avait pas.
 * Tu peux le relancer autant de fois que tu veux.
 */
class ItemsGen4Seeder extends Seeder
{
    /** Poches à corriger sur un objet existant (PokéAPI range les objets X dans "medicine", les objets tenus dans "misc"...) */
    private const FIX_POCKETS = ['battle', 'mail', 'held_items', 'berries', 'balls', 'medicine'];

    public function run(): void
    {
        $created = 0;
        $updated = 0;
        foreach (require database_path('data/items_gen4.php') as [$slug, $name, $pocket, $category, $price, $description, $consumable, $battle, $field, $holdable, $effect, $value]) {
            $options = [
                'consumable' => $consumable,
                'usable_in_battle' => $battle,
                'usable_overworld' => $field,
                'holdable' => $holdable,
            ];
            $item = Item::where('slug', $slug)->first();
            if (! $item) {
                Item::forceCreate([
                    'slug' => $slug, 'name' => $name, 'pocket' => $pocket, 'category' => $category,
                    'description' => $description, 'price' => $price, 'sell_price' => intdiv($price, 2),
                    'tradable' => true, 'effect_code' => $effect, 'effect_value' => $value,
                ] + $options);
                $created++;
                continue;
            }

            $changes = $options;
            if (in_array($pocket, self::FIX_POCKETS, true) && $item->pocket !== 'key_items') {
                $changes['pocket'] = $pocket;
            }
            if ($effect !== null && $item->effect_code === null) {
                $changes['effect_code'] = $effect;
                $changes['effect_value'] = $value;
            }
            // Effets nouveaux de l'étape 22 : toujours remis (ils remplacent un effet générique d'import)
            if (in_array($effect, ['revive_all', 'pp_up', 'battle_stat', 'guard_spec', 'dire_hit', 'cure_confusion', 'cure_attract',
                'encounter_flute', 'flee_wild', 'ev_down'], true)) {
                $changes['effect_code'] = $effect;
                $changes['effect_value'] = $value;
            }
            $item->forceFill($changes);
            if ($item->isDirty()) {
                $item->save();
                $updated++;
            }
        }

        $this->command?->info("Objets : {$created} ajoutés, {$updated} complétés.");
    }
}
