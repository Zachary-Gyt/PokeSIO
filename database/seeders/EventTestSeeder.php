<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Étape 24 : la dresseuse de la carte "evenements_test" (salle des événements).
 * Elle n'a pas de propriété "trainer" dans Tiled : c'est le script (resources/scenario/evenements_test.txt)
 * qui lance le combat avec « combat dresseur evenements_lina perdable ».
 *
 *   php artisan db:seed --class=EventTestSeeder
 */
class EventTestSeeder extends Seeder
{
    public function run(): void
    {
        $trainer = Trainer::updateOrCreate(['slug' => 'evenements_lina'], [
            'name' => 'Lina',
            'trainer_class' => 'Fillette',
            'map_key' => 'evenements_test',
            'sprite_overworld' => 'lass',
            'sprite_battle' => 'lass',
            'reward_money' => 200,
            'sight_range' => 0,   // elle ne repère personne toute seule : c'est l'événement qui la fait venir
            'ai_level' => 2,
            'intro_text' => null,
            'defeat_text' => 'Oh non ! Mes Pokémon...',
            'after_text' => null,
        ]);
        $trainer->pokemon()->where('version', 0)->delete();   // étape 26 : on garde les équipes de revanche
        foreach ([['pidgey', 8], ['clefairy', 10]] as $i => [$slug, $level]) {
            $id = PokemonSpecies::where('slug', $slug)->value('id');
            if ($id) {
                $trainer->pokemon()->create(['slot' => $i + 1, 'pokemon_species_id' => $id, 'level' => $level]);
            }
        }
        $this->command->info('Fillette Lina (salle des événements) : 2 Pokémon');
    }
}
