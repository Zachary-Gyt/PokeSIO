<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

/**
 * Étape 20 : la Boîte Jetons (objet rare, effet "coin_case" : Sac -> Utiliser affiche les jetons).
 *
 *   php artisan db:seed --class=GameCornerSeeder
 *
 * Elle est normalement déjà importée de PokéAPI (slug coin-case) : on vérifie seulement sa poche et son effet.
 * Le seeder affiche aussi les lots de config/game_corner.php introuvables dans ta base.
 */
class GameCornerSeeder extends Seeder
{
    public function run(): void
    {
        $slug = config('game_corner.coin_case', 'coin-case');
        $always = ['pocket' => 'key_items', 'consumable' => false, 'tradable' => false, 'holdable' => false,
            'usable_overworld' => true, 'effect_code' => 'coin_case'];
        $item = Item::where('slug', $slug)->first();
        if ($item) {
            $item->forceFill($always)->save();
        } else {
            Item::forceCreate(['slug' => $slug, 'name' => 'Boîte Jetons', 'category' => 'gameplay', 'price' => 0, 'sell_price' => 0,
                'description' => 'Une boîte pour ranger les jetons du Casino. Elle peut en contenir 9 999.'] + $always);
        }
        $this->command->info('Boîte Jetons prête');

        // Vérifie les lots des comptoirs
        $corner = app(\App\Services\GameCornerService::class);
        foreach (config('game_corner.prizes', []) as $counter => $rows) {
            $found = count($corner->prizes($counter));
            $this->command->info("Comptoir « {$counter} » : {$found} lot(s) sur " . count($rows));
            if ($found < count($rows)) {
                $this->command->warn('  Lots introuvables (espèce, objet ou CT absents de la base) : ils ne seront pas proposés.');
            }
        }
    }
}
