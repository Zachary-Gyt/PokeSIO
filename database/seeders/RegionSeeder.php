<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\WildEncounter;
use Illuminate\Database\Seeder;

/**
 * Les POKÉMON SAUVAGES de la nouvelle région : Crémieu, Route 1 (et sa grotte), le Parc Safari (4 zones).
 *
 *   php artisan db:seed --class=RegionSeeder
 *
 * Relançable sans doublon : chaque table (carte + méthode) est remplacée.
 * [espèce (slug PokéAPI), niveau min, niveau max, poids] ; poids = probabilité relative.
 * Méthodes : grass (hautes herbes, et toute la grotte), surf (sur l'eau), old_rod / good_rod / super_rod (pêche).
 * Une espèce absente de ta base (génération pas encore importée) est simplement ignorée.
 */
class RegionSeeder extends Seeder
{
    public function run(): void
    {
        // Crémieu : les herbes autour de la ville, et l'étang
        $this->table('cremieu', 'grass', [['pidgey', 2, 4, 40], ['rattata', 2, 4, 40], ['sentret', 2, 4, 15], ['pikachu', 3, 5, 5]]);
        $this->table('cremieu', 'surf', [['psyduck', 10, 20, 60], ['poliwag', 10, 20, 40]]);
        $this->table('cremieu', 'old_rod', [['magikarp', 5, 10, 100]]);

        // Route 1
        $this->table('road_1', 'grass', [
            ['pidgey', 3, 6, 25], ['rattata', 3, 6, 25], ['caterpie', 3, 5, 15], ['weedle', 3, 5, 15],
            ['nidoran-f', 4, 6, 8], ['nidoran-m', 4, 6, 8], ['pikachu', 4, 6, 4],
        ]);
        $this->table('road_1', 'surf', [['psyduck', 10, 20, 50], ['goldeen', 10, 20, 50]]);
        $this->table('road_1', 'old_rod', [['magikarp', 5, 10, 100]]);

        // La grotte de la Route 1 (des Pokémon sur toutes les cases : propriété encounters_everywhere)
        $this->table('road_1_cave', 'cave', [['zubat', 4, 8, 45], ['geodude', 5, 8, 35], ['paras', 5, 8, 15], ['onix', 7, 9, 5]]);

        // Bachut (les hautes herbes du parc) et la cour de l'ORT (hautes herbes sur le gravier : Pokémon Roche / Sol)
        $this->table('bachut', 'grass', [['pidgey', 8, 12, 35], ['rattata', 8, 12, 30], ['meowth', 9, 12, 20], ['spearow', 9, 12, 15]]);
        $this->table('ort_courtyard', 'grass', [['pidgey', 10, 13, 40], ['rattata', 10, 13, 40], ['meowth', 11, 13, 20]]);
        $this->table('ort_courtyard', 'grass', [['geodude', 10, 14, 35], ['sandshrew', 10, 14, 25], ['diglett', 10, 14, 20], ['cubone', 11, 14, 15], ['onix', 12, 14, 5]], 'gravier');

        // Les abords du Parc Safari
        $this->table('cremieu_safari', 'grass', [['oddish', 5, 8, 35], ['bellsprout', 5, 8, 35], ['venonat', 6, 8, 20], ['exeggcute', 7, 9, 10]]);

        // Le Parc Safari : une zone par région
        $zones = [
            'safari_kanto' => [
                'grass' => [['nidoran-f', 22, 26, 15], ['nidoran-m', 22, 26, 15], ['exeggcute', 22, 26, 15], ['rhyhorn', 23, 27, 15],
                    ['venonat', 22, 25, 12], ['doduo', 22, 26, 10], ['kangaskhan', 25, 28, 5], ['scyther', 25, 28, 4],
                    ['pinsir', 25, 28, 4], ['tauros', 25, 28, 3], ['chansey', 25, 28, 2]],
                'surf' => [['psyduck', 20, 30, 50], ['slowpoke', 20, 30, 40], ['dratini', 20, 25, 10]],
                'old_rod' => [['magikarp', 5, 10, 100]],
                'good_rod' => [['goldeen', 15, 25, 60], ['poliwag', 15, 25, 30], ['dratini', 15, 20, 10]],
            ],
            'safari_jotho' => [
                'grass' => [['sunkern', 22, 26, 15], ['aipom', 22, 26, 15], ['girafarig', 23, 27, 15], ['phanpy', 22, 26, 15],
                    ['teddiursa', 22, 26, 12], ['yanma', 23, 27, 10], ['gligar', 24, 27, 8], ['heracross', 25, 28, 4],
                    ['stantler', 24, 27, 4], ['miltank', 25, 28, 2]],
                'surf' => [['marill', 20, 30, 50], ['wooper', 20, 30, 40], ['quagsire', 25, 30, 10]],
                'old_rod' => [['magikarp', 5, 10, 100]],
                'good_rod' => [['chinchou', 15, 25, 60], ['qwilfish', 15, 25, 30], ['remoraid', 15, 25, 10]],
            ],
            'safari_hoenn' => [
                'grass' => [['zigzagoon', 22, 26, 15], ['shroomish', 22, 26, 15], ['seedot', 22, 26, 12], ['lotad', 22, 26, 12],
                    ['ralts', 22, 25, 10], ['electrike', 23, 27, 10], ['plusle', 23, 27, 8], ['minun', 23, 27, 8],
                    ['tropius', 25, 28, 5], ['absol', 25, 28, 3], ['bagon', 24, 26, 2]],
                'surf' => [['wingull', 20, 30, 50], ['marill', 20, 30, 40], ['corphish', 20, 30, 10]],
                'old_rod' => [['magikarp', 5, 10, 100]],
                'good_rod' => [['barboach', 15, 25, 60], ['corphish', 15, 25, 30], ['feebas', 15, 20, 10]],
            ],
            'safari_sinnoh' => [
                'grass' => [['starly', 22, 26, 15], ['bidoof', 22, 26, 15], ['shinx', 22, 26, 12], ['budew', 22, 26, 12],
                    ['buneary', 22, 26, 10], ['pachirisu', 23, 27, 10], ['hippopotas', 23, 27, 8], ['skorupi', 23, 27, 8],
                    ['croagunk', 24, 27, 6], ['carnivine', 25, 28, 3], ['gible', 24, 26, 1]],
                'surf' => [['buizel', 20, 30, 50], ['shellos', 20, 30, 40], ['floatzel', 25, 30, 10]],
                'old_rod' => [['magikarp', 5, 10, 100]],
                'good_rod' => [['finneon', 15, 25, 60], ['buizel', 15, 25, 30], ['gyarados', 20, 25, 10]],
            ],
        ];
        // Les hautes herbes posées sur le SABLE et sur la ROCHE (propriété de tuile "terrain") ont leurs propres Pokémon
        $terrains = [
            'safari_kanto' => [
                'sable' => [['sandshrew', 22, 26, 35], ['diglett', 22, 26, 30], ['cubone', 23, 27, 25], ['sandslash', 25, 28, 10]],
                'roche' => [['geodude', 22, 26, 35], ['graveler', 25, 28, 20], ['onix', 23, 27, 25], ['rhyhorn', 23, 27, 20]],
            ],
            'safari_jotho' => [
                'sable' => [['phanpy', 22, 26, 35], ['gligar', 23, 27, 30], ['larvitar', 22, 25, 15], ['donphan', 25, 28, 20]],
                'roche' => [['sudowoodo', 23, 27, 35], ['shuckle', 22, 26, 30], ['larvitar', 22, 25, 20], ['onix', 23, 27, 15]],
            ],
            'safari_hoenn' => [
                'sable' => [['trapinch', 22, 26, 35], ['cacnea', 22, 26, 30], ['baltoy', 23, 27, 25], ['sandshrew', 22, 26, 10]],
                'roche' => [['nosepass', 22, 26, 30], ['aron', 22, 26, 30], ['lairon', 25, 28, 15], ['lunatone', 24, 27, 15], ['solrock', 24, 27, 10]],
            ],
            'safari_sinnoh' => [
                'sable' => [['hippopotas', 22, 26, 40], ['skorupi', 22, 26, 30], ['gible', 22, 25, 10], ['gligar', 23, 27, 20]],
                'roche' => [['cranidos', 22, 26, 25], ['shieldon', 22, 26, 25], ['bonsly', 22, 25, 25], ['geodude', 22, 26, 25]],
            ],
        ];
        foreach ($terrains as $map => $byTerrain) {
            foreach ($byTerrain as $terrain => $list) {
                $this->table($map, 'grass', $list, $terrain);
            }
        }

        foreach ($zones as $map => $methods) {
            foreach ($methods as $method => $list) {
                $this->table($map, $method, $list);
            }
        }
    }

    private function table(string $mapKey, string $method, array $pokemon, string $zone = 'default'): void
    {
        WildEncounter::where(['map_key' => $mapKey, 'zone' => $zone, 'method' => $method])->delete();
        $count = 0;
        foreach ($pokemon as [$slug, $min, $max, $weight]) {
            $speciesId = PokemonSpecies::where('slug', $slug)->value('id');
            if (! $speciesId) {
                $this->command?->warn("  {$mapKey} : espèce inconnue {$slug} (ignorée)");
                continue;
            }
            WildEncounter::create([
                'map_key' => $mapKey, 'zone' => $zone, 'method' => $method, 'pokemon_species_id' => $speciesId,
                'min_level' => $min, 'max_level' => $max, 'weight' => $weight,
            ]);
            $count++;
        }
        $this->command?->info("{$mapKey} ({$method}" . ($zone !== 'default' ? ", terrain {$zone}" : '') . ") : {$count} Pokémon");
    }
}
