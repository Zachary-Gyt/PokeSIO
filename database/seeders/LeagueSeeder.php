<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Étape 27 : la LIGUE POKÉMON de démonstration (cartes ligue_hall et ligue_salle_*, entrée par la maison
 * rouge du Bourg Démo) : le Conseil 4 de Kanto et un Maître, avec une équipe "version 1" plus forte
 * pour les tentatives qui suivent la première victoire (config league.rematch_version).
 *
 *   php artisan db:seed --class=LeagueSeeder
 *
 * L'ordre des combats est celui de config/league.php ("members").
 */
class LeagueSeeder extends Seeder
{
    public function run(): void
    {
        $members = [
            ['ligue_olga', 'Olga', 'Conseil 4', 'elitefour_lorelei', 'ligue_salle_1', 5000,
                'Bienvenue à la Ligue Pokémon ! Je suis Olga du Conseil 4. Mes Pokémon de glace vont te geler sur place !',
                'Tu es... vraiment fort ! Continue, la salle suivante t\'attend.',
                [0 => [['dewgong', 52], ['cloyster', 51], ['slowbro', 52], ['jynx', 54], ['lapras', 54]],
                 1 => [['dewgong', 64], ['cloyster', 63], ['piloswine', 63], ['jynx', 65], ['lapras', 66]]]],
            ['ligue_aldo', 'Aldo', 'Conseil 4', 'elitefour_bruno', 'ligue_salle_2', 5100,
                'Je suis Aldo du Conseil 4 ! Mes Pokémon et moi, nous nous entraînons sans relâche. Prépare-toi !',
                'Pourquoi ? Comment ai-je pu perdre ?',
                [0 => [['onix', 51], ['hitmonchan', 53], ['hitmonlee', 53], ['onix', 54], ['machamp', 56]],
                 1 => [['steelix', 65], ['hitmontop', 65], ['hitmonlee', 65], ['hitmonchan', 65], ['machamp', 68]]]],
            ['ligue_agatha', 'Agatha', 'Conseil 4', 'elitefour_agatha', 'ligue_salle_3', 5300,
                'Je suis Agatha du Conseil 4. Les Pokémon Spectre, c\'est fait pour les combats... Laisse-moi te montrer !',
                'Oh oh ! Tu es quelqu\'un de spécial, toi !',
                [0 => [['gengar', 54], ['golbat', 54], ['haunter', 53], ['arbok', 56], ['gengar', 58]],
                 1 => [['gengar', 66], ['crobat', 66], ['mismagius', 65], ['arbok', 66], ['gengar', 70]]]],
            ['ligue_peter', 'Peter', 'Conseil 4', 'elitefour_lance', 'ligue_salle_4', 5600,
                'Je suis Peter, le dernier du Conseil 4. Les dragons sont des Pokémon mythiques... Affronte-les !',
                'C\'est fini... Va, le Maître t\'attend.',
                [0 => [['gyarados', 56], ['dragonair', 54], ['dragonair', 54], ['aerodactyl', 58], ['dragonite', 60]],
                 1 => [['gyarados', 68], ['kingdra', 68], ['salamence', 68], ['aerodactyl', 69], ['dragonite', 72]]]],
            ['ligue_maitre', 'Blue', 'Maître', 'champion', 'ligue_salle_maitre', 7500,
                'Te voilà enfin ! Je suis le Maître de la Ligue Pokémon. Tu sais ce que ça veut dire ? Je suis le meilleur dresseur du monde !',
                'Non... Ce n\'est pas possible... Tu es le nouveau Maître !',
                [0 => [['pidgeot', 59], ['alakazam', 57], ['rhydon', 59], ['exeggutor', 59], ['gyarados', 61], ['arcanine', 63]],
                 1 => [['pidgeot', 72], ['alakazam', 72], ['rhyperior', 73], ['exeggutor', 72], ['gyarados', 73], ['arcanine', 75]]]],
        ];
        $items = Item::whereIn('slug', ['hyper-potion', 'full-restore'])->pluck('slug')->all();

        foreach ($members as [$slug, $name, $class, $sprite, $map, $reward, $intro, $defeat, $teams]) {
            $trainer = Trainer::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'trainer_class' => $class,
                'map_key' => $map,
                'sprite_overworld' => $sprite,
                'sprite_battle' => $sprite,
                'reward_money' => $reward,
                'sight_range' => 0,   // on va lui parler, comme dans les jeux
                'ai_level' => 3,
                'intro_text' => $intro,
                'defeat_text' => $defeat,
                'after_text' => $defeat,
                'items' => $slug === 'ligue_maitre' ? array_values(array_intersect(['full-restore', 'full-restore'], $items) ?: []) : array_values(array_intersect(['hyper-potion'], $items)),
            ]);
            $trainer->pokemon()->delete();   // ses équipes sont toutes définies ici (versions 0 et 1)
            foreach ($teams as $version => $team) {
                foreach ($team as $i => [$species, $level]) {
                    $id = PokemonSpecies::where('slug', $species)->value('id');
                    if (! $id) {
                        $this->command->warn("Espèce inconnue : {$species} ({$name})");
                        continue;
                    }
                    $trainer->pokemon()->create(['version' => $version, 'slot' => $i + 1, 'pokemon_species_id' => $id, 'level' => $level, 'iv' => 31]);
                }
            }
            $this->command->info("{$class} {$name} : " . count($teams[0]) . ' Pokémon (et une équipe de revanche)');
        }
    }
}
