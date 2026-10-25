<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Models\WildEncounter;
use Illuminate\Database\Seeder;

/**
 * Étape 16 : ce dont la carte "dev_room" (salle de test des maîtres du jeu) a besoin en base :
 * son dresseur, et ses Pokémon sauvages (hautes herbes, Surf, les 3 cannes, Éclate-Roc).
 *
 *   php artisan db:seed --class=DevRoomSeeder
 *
 * On y va avec la commande de chat /carte dev_room (aucune sortie ne mène à cette carte).
 */
class DevRoomSeeder extends Seeder
{
    public function run(): void
    {
        $trainer = Trainer::updateOrCreate(['slug' => 'dev_room_dresseur'], [
            'name' => 'Léo',
            'trainer_class' => 'Campeur',
            'map_key' => 'dev_room',
            'sprite_overworld' => 'camper',
            'sprite_battle' => 'camper',
            'reward_money' => 20,
            'sight_range' => 4,
            'ai_level' => 2,
            'intro_text' => 'Hé ! Tu es dans mon champ de vision : combat !',
            'defeat_text' => 'Bien joué, la salle de test fonctionne !',
            'after_text' => 'Tu peux me redéfier : je ne repère plus personne, mais parle-moi.',
        ]);
        $trainer->pokemon()->where('version', 0)->delete();   // étape 26 : on garde les équipes de revanche
        foreach ([['rattata', 6], ['pidgey', 7]] as $i => [$slug, $level]) {
            $id = PokemonSpecies::where('slug', $slug)->value('id');
            if ($id) {
                $trainer->pokemon()->create(['slot' => $i + 1, 'pokemon_species_id' => $id, 'level' => $level]);
            }
        }
        $this->command->info('Campeur Léo : 2 Pokémon');

        // [zone, méthode, [[espèce, niv. min, niv. max, poids], ...]]
        $tables = [
            ['default', 'grass', [['rattata', 3, 5, 40], ['pidgey', 3, 5, 40], ['pikachu', 4, 6, 20]]],
            ['etang', 'surf', [['tentacool', 10, 15, 70], ['psyduck', 10, 15, 30]]],
            ['etang', 'old_rod', [['magikarp', 5, 10, 100]]],
            ['etang', 'good_rod', [['poliwag', 10, 15, 60], ['goldeen', 10, 15, 40]]],
            ['etang', 'super_rod', [['dratini', 15, 20, 30], ['slowpoke', 15, 20, 70]]],
            ['default', 'rock_smash', [['geodude', 8, 12, 100]]],
        ];
        WildEncounter::where('map_key', 'dev_room')->delete();
        foreach ($tables as [$zone, $method, $pokemon]) {
            foreach ($pokemon as [$slug, $min, $max, $weight]) {
                $id = PokemonSpecies::where('slug', $slug)->value('id');
                if (! $id) {
                    $this->command->warn("Espèce inconnue : {$slug}");
                    continue;
                }
                WildEncounter::create([
                    'map_key' => 'dev_room', 'zone' => $zone, 'method' => $method, 'pokemon_species_id' => $id,
                    'min_level' => $min, 'max_level' => $max, 'weight' => $weight,
                ]);
            }
        }
        $this->command->info('dev_room : Pokémon sauvages (herbes, Surf, cannes, Éclate-Roc)');
    }
}
