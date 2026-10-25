<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\WildEncounter;
use Illuminate\Database\Seeder;

/**
 * Les Pokémon sauvages de chaque carte.
 *
 *   php artisan db:seed --class=WildEncounterSeeder
 *
 * "weight" = probabilité relative : avec 45 / 45 / 10, on a 45 % de Rattata, 45 % de Roucool, 10 % de Pikachu.
 * Les rencontres ont lieu sur les tuiles qui ont la propriété "encounter" cochée dans Tiled.
 */
class WildEncounterSeeder extends Seeder
{
    public function run(): void
    {
        $this->zone('cremieu', [
            // [espèce (slug PokéAPI), niveau min, niveau max, poids]
            ['rattata', 2, 4, 45],
            ['pidgey', 2, 4, 45],
            ['pikachu', 3, 5, 10],
        ]);

        // Une autre carte, sur le même modèle :
        // $this->zone('route_1', [['caterpie', 3, 5, 50], ['weedle', 3, 5, 50]]);
    }

    /**
     * $zone : "default", ou le nom d'une zone dessinée dans Tiled
     * (calque d'objets "encounters", rectangle avec la propriété "zone")
     */
    private function zone(string $mapKey, array $pokemon, string $zone = 'default', string $method = 'grass'): void
    {
        // On remplace la table de cette zone (le seeder peut être relancé sans créer de doublons)
        WildEncounter::where(['map_key' => $mapKey, 'zone' => $zone, 'method' => $method])->delete();

        foreach ($pokemon as [$slug, $min, $max, $weight]) {
            $speciesId = PokemonSpecies::where('slug', $slug)->value('id');
            if (! $speciesId) {
                $this->command->warn("Espèce inconnue : {$slug}");
                continue;
            }

            WildEncounter::create([
                'map_key' => $mapKey,
                'zone' => $zone,
                'method' => $method,
                'pokemon_species_id' => $speciesId,
                'min_level' => $min,
                'max_level' => $max,
                'weight' => $weight,
            ]);
        }

        $this->command->info("{$mapKey} ({$zone}) : " . count($pokemon) . ' Pokémon sauvages');
    }
}
