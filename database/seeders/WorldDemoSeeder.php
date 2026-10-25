<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\WildEncounter;
use Illuminate\Database\Seeder;

/**
 * Étape 25 : les Pokémon sauvages de la Route Démo (carte "route_demo", collée à l'ouest de Crémieu).
 *
 *   php artisan db:seed --class=WorldDemoSeeder
 */
class WorldDemoSeeder extends Seeder
{
    public function run(): void
    {
        // [espèce, niv. min, niv. max, poids, moment de la journée (null = toujours)]
        $grass = [
            ['pidgey', 2, 4, 30, null], ['rattata', 2, 4, 30, null], ['sentret', 2, 4, 20, 'day'],
            ['hoothoot', 2, 4, 20, 'night'], ['caterpie', 3, 4, 10, 'morning'], ['bidoof', 2, 4, 10, null],
        ];
        WildEncounter::where('map_key', 'route_demo')->delete();
        $count = 0;
        foreach ($grass as [$slug, $min, $max, $weight, $time]) {
            $id = PokemonSpecies::where('slug', $slug)->value('id');
            if (! $id) {
                $this->command->warn("Espèce inconnue (ou pas encore importée) : {$slug}");
                continue;
            }
            WildEncounter::create([
                'map_key' => 'route_demo', 'zone' => 'default', 'method' => 'grass', 'pokemon_species_id' => $id,
                'min_level' => $min, 'max_level' => $max, 'weight' => $weight, 'time_of_day' => $time,
            ]);
            $count++;
        }
        $this->command->info("Route Démo : {$count} Pokémon sauvages dans les hautes herbes");
    }
}
