<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Les dresseurs de la carte d'exemple "arene_test" (et le Tenant du titre).
 *
 *   php artisan db:seed --class=AreneTestSeeder
 *
 * Inspire-toi de ce fichier pour les dresseurs de TES arènes.
 */
class AreneTestSeeder extends Seeder
{
    public function run(): void
    {
        // Deux dresseurs dans l'arène, avant le Champion (comme à Argenta)
        $this->trainer([
            'slug' => 'arene_test_campeur_jules',
            'name' => 'Jules',
            'trainer_class' => 'Campeur',
            'map_key' => 'arene_test',
            'sprite_overworld' => 'camper',
            'sprite_battle' => 'camper',
            'reward_money' => 20,
            'sight_range' => 4,
            'ai_level' => 2,
            'intro_text' => "Halte ! Tu es encore à des années-lumière de Pierre !",
            'defeat_text' => 'Pfff... Tu es plus fort que prévu.',
            'after_text' => "Pierre est bien plus coriace que moi, crois-moi !",
        ], [['diglett', 10], ['sandshrew', 11]]);

        $this->trainer([
            'slug' => 'arene_test_montagnard_marc',
            'name' => 'Marc',
            'trainer_class' => 'Montagnard',
            'map_key' => 'arene_test',
            'sprite_overworld' => 'hiker',
            'sprite_battle' => 'hiker',
            'reward_money' => 36,
            'sight_range' => 3,
            'ai_level' => 2,
            'intro_text' => "Ho ho ! Mes Pokémon Roche sont durs comme la montagne !",
            'defeat_text' => 'Ma montagne s\'est effondrée...',
            'after_text' => 'Les Pokémon Eau et Plante sont redoutables contre la Roche.',
        ], [['geodude', 10], ['geodude', 11]]);

        // Le Maître de l'ORT (ancien "Tenant du titre") : son équipe de départ est celle-ci, jusqu'à ce qu'un joueur le batte.
        // Ensuite, c'est l'équipe du dernier vainqueur (table title_holders).
        $this->trainer([
            'slug' => 'tenant_du_titre',
            'name' => 'Professeur Plantefeve',      // étape 16
            'trainer_class' => 'Maître de l\'ORT',
            'map_key' => null,
            'sprite_overworld' => 'cooltrainer_m',
            'sprite_battle' => 'cooltrainer_m',
            'reward_money' => 60,
            'sight_range' => 0,          // il ne repère personne : on vient lui parler
            'ai_level' => 3,
            'is_title' => true,
            'level_cap' => 50,           // Pokémon de niveau 51 ou plus refusés pour le défier
            'intro_text' => null,        // les phrases du tenant sont écrites par TitleService
            'defeat_text' => null,
            'after_text' => null,
        ], [['pidgeotto', 22], ['raticate', 22], ['ivysaur', 24]]);
    }

    private function trainer(array $data, array $team): void
    {
        $trainer = Trainer::updateOrCreate(['slug' => $data['slug']], $data);
        $trainer->pokemon()->where('version', 0)->delete();   // étape 26 : on garde les équipes de revanche

        foreach ($team as $index => [$speciesSlug, $level]) {
            $speciesId = PokemonSpecies::where('slug', $speciesSlug)->value('id');
            if (! $speciesId) {
                $this->command->warn("Espèce inconnue : {$speciesSlug} (dresseur {$data['slug']})");
                continue;
            }
            $trainer->pokemon()->create(['slot' => $index + 1, 'pokemon_species_id' => $speciesId, 'level' => $level]);
        }

        $this->command->info("{$data['trainer_class']} {$data['name']} : " . count($team) . ' Pokémon');
    }
}
