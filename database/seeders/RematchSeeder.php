<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Étape 26 : les revanches.
 *   - l'objet rare "Cherche VS" (vs-seeker), s'il n'existe pas encore ;
 *   - des ÉQUIPES DE REVANCHE d'exemple (trainer_pokemon.version) pour le Campeur Léo (salle de test)
 *     et le Champion Pierre (arène de test).
 *
 *   php artisan db:seed --class=RematchSeeder
 *
 * Pour tes propres dresseurs : même principe, une ligne de trainer_pokemon par Pokémon, avec version = 1, 2...
 * La version est débloquée par le nombre de badges (config/rematches.php, "versions").
 */
class RematchSeeder extends Seeder
{
    public function run(): void
    {
        Item::updateOrCreate(['slug' => 'vs-seeker'], [
            'name' => 'Cherche VS',
            'pocket' => 'key_items',
            'effect_code' => 'vs_seeker',
            'price' => 0,
            'sell_price' => 0,
            'consumable' => false,
            'tradable' => false,
            'usable_overworld' => true,
            'description' => 'Un appareil qui repère les Dresseurs qui veulent une revanche. Se recharge en marchant.',
        ]);
        $this->command->info('Objet rare : Cherche VS (vs-seeker)');

        // [slug du dresseur => [version => [[espèce, niveau], ...]]]
        $teams = [
            'dev_room_dresseur' => [
                1 => [['rattata', 14], ['pidgeotto', 16]],
                2 => [['raticate', 24], ['pidgeotto', 25], ['sandshrew', 24]],
                4 => [['raticate', 40], ['pidgeot', 42], ['sandslash', 41]],
            ],
            'champion_pierre' => [
                5 => [['golem', 58], ['omastar', 57], ['kabutops', 57], ['rhydon', 60], ['onix', 62]],
            ],
        ];
        foreach ($teams as $slug => $versions) {
            $trainer = Trainer::where('slug', $slug)->first();
            if (! $trainer) {
                $this->command->warn("Dresseur introuvable : {$slug} (lance d'abord son seeder)");
                continue;
            }
            $trainer->pokemon()->where('version', '>', 0)->delete();
            foreach ($versions as $version => $list) {
                foreach ($list as $i => [$species, $level]) {
                    $id = PokemonSpecies::where('slug', $species)->value('id');
                    if (! $id) {
                        $this->command->warn("Espèce inconnue : {$species}");
                        continue;
                    }
                    $trainer->pokemon()->create(['version' => $version, 'slot' => $i + 1, 'pokemon_species_id' => $id, 'level' => $level]);
                }
            }
            $this->command->info("{$trainer->trainer_class} {$trainer->name} : revanches " . implode(', ', array_map(fn ($v) => "version {$v}", array_keys($versions))));
        }
    }
}
