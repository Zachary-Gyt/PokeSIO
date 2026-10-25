<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Crée (ou met à jour) les dresseurs PNJ et leur équipe.
 *
 *   php artisan db:seed --class=TrainerSeeder
 *
 * On peut le relancer autant de fois qu'on veut : updateOrCreate retrouve
 * chaque dresseur par son slug au lieu de créer un doublon.
 */
class TrainerSeeder extends Seeder
{
    public function run(): void
    {
        $this->trainer(
            [
                'slug' => 'cremieu_gamin_leo',          // à écrire dans Tiled : propriété "trainer"
                'name' => 'Léo',
                'trainer_class' => 'Gamin',
                'map_key' => 'cremieu',
                'sprite_overworld' => 'youngster',      // public/game/sprites/trainers/overworld/youngster.png
                'sprite_battle' => 'youngster',         // public/game/sprites/trainers/battle/youngster.png
                'reward_money' => 80,
                'sight_range' => 4,                     // il voit à 4 cases devant lui
                'intro_text' => "Hé ! Toi là !\n\nTu as l'air d'un Dresseur.\nOn fait un combat ?",
                'defeat_text' => "Oh non... j'ai perdu !",
                'after_text' => "Je vais m'entraîner encore plus dur !",
            ],
            // Équipe : [espèce (slug PokéAPI), niveau]
            [
                ['rattata', 4],
                ['pidgey', 3],
            ],
        );

        $this->trainer(
            [
                'slug' => 'cremieu_fillette_lea',
                'name' => 'Léa',
                'trainer_class' => 'Fillette',
                'map_key' => 'cremieu',
                'sprite_overworld' => 'lass',
                'sprite_battle' => 'lass',
                'reward_money' => 64,
                'sight_range' => 3,
                'intro_text' => "Mes Pokémon sont les plus mignons de Crémieu !\n\nTu veux voir ?",
                'defeat_text' => 'Oh... Ils étaient si mignons pourtant !',
                'after_text' => "Mignon ET fort, c'est possible ?",
            ],
            [
                ['nidoran-f', 4],
                ['clefairy', 5],
            ],
        );

        // Ajoute tes dresseurs ici, sur le même modèle :
        // $this->trainer([...], [['caterpie', 3], ['weedle', 3]]);
    }

    private function trainer(array $data, array $team): void
    {
        $trainer = Trainer::updateOrCreate(['slug' => $data['slug']], $data);

        // On remplace l'équipe entière (plus simple que de comparer l'ancienne et la nouvelle)
        $trainer->pokemon()->delete();

        foreach ($team as $index => [$speciesSlug, $level]) {
            $speciesId = PokemonSpecies::where('slug', $speciesSlug)->value('id');

            if (! $speciesId) {
                $this->command->warn("Espèce inconnue : {$speciesSlug} (dresseur {$data['slug']})");
                continue;
            }

            $trainer->pokemon()->create([
                'slot' => $index + 1,
                'pokemon_species_id' => $speciesId,
                'level' => $level,
            ]);
        }

        $this->command->info("Dresseur {$data['trainer_class']} {$data['name']} : " . count($team) . ' Pokémon');
    }
}
