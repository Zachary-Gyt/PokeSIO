<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Étape 19 : Repousse, Super Repousse, Max Repousse et Corde Sortie.
 *
 *   php artisan db:seed --class=FieldItemsSeeder
 *
 * Normalement déjà importés de PokéAPI (config/pokeapi.php : effets "repel" et "escape") : le seeder vérifie
 * seulement leur effet, leur poche et le nombre de pas. Un objet absent est créé. Il peut être relancé sans risque.
 */
class FieldItemsSeeder extends Seeder
{
    /** slug => [nom, effet, valeur (pas), prix, description] */
    private const ITEMS = [
        'repel' => ['Repousse', 'repel', 100, 350, 'Repousse les Pokémon sauvages faibles pendant 100 pas.'],
        'super-repel' => ['Super Repousse', 'repel', 200, 500, 'Repousse les Pokémon sauvages faibles pendant 200 pas.'],
        'max-repel' => ['Max Repousse', 'repel', 250, 700, 'Repousse les Pokémon sauvages faibles pendant 250 pas.'],
        'escape-rope' => ['Corde Sortie', 'escape', null, 550, 'Une longue corde solide. Permet de sortir instantanément d\'une grotte.'],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $slug => [$name, $effect, $value, $price, $description]) {
            $always = ['effect_code' => $effect, 'effect_value' => $value, 'pocket' => 'misc', 'usable_overworld' => true, 'consumable' => true];
            $item = Item::where('slug', $slug)->first();
            if ($item) {
                $item->forceFill($always)->save();
            } else {
                Item::forceCreate(['slug' => $slug, 'name' => $name, 'category' => 'spelunking', 'description' => $description,
                    'price' => $price, 'sell_price' => intdiv($price, 2), 'tradable' => true, 'holdable' => false, 'usable_in_battle' => false] + $always);
            }
            $this->command->info("{$name} : {$effect}" . ($value ? " ({$value} pas)" : ''));
        }
    }
}
