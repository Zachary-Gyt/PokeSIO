<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\WildEncounter;
use Illuminate\Database\Seeder;

/**
 * Étape 11 : les Pokémon sauvages de la carte de test des CS (public/game/maps/field_test.tmj).
 *   - dans l'étang (zone "etang"), en Surf ;
 *   - sous les rochers brisés par Éclate-Roc (méthode "rock_smash").
 *
 *   php artisan db:seed --class=FieldTestSeeder
 *
 * Pour tes propres cartes, c'est le même principe : une ligne par Pokémon dans wild_encounters,
 * avec method = 'surf' (eau) ou 'rock_smash' (rochers), en plus de 'grass' (hautes herbes).
 */
class FieldTestSeeder extends Seeder
{
    public function run(): void
    {
        WildEncounter::where('map_key', 'field_test')->delete();

        $rows = [
            // [zone, méthode, Pokémon, niveau min, niveau max, poids]
            ['etang', 'surf', 'tentacool', 15, 25, 50],
            ['etang', 'surf', 'psyduck', 15, 25, 30],
            ['etang', 'surf', 'magikarp', 10, 20, 20],
            ['default', 'rock_smash', 'geodude', 10, 15, 70],
            ['default', 'rock_smash', 'onix', 10, 15, 30],
        ];

        foreach ($rows as [$zone, $method, $slug, $min, $max, $weight]) {
            $species = PokemonSpecies::where('slug', $slug)->first();
            if (! $species) {
                continue;
            }
            WildEncounter::create([
                'map_key' => 'field_test', 'zone' => $zone, 'method' => $method,
                'pokemon_species_id' => $species->id, 'min_level' => $min, 'max_level' => $max,
                'weight' => $weight, 'time_of_day' => null,
            ]);
        }
    }
}
