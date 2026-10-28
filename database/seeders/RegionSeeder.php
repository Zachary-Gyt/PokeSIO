<?php

namespace Database\Seeders;

use App\Models\PokemonSpecies;
use App\Models\WildEncounter;
use Illuminate\Database\Seeder;

/**
 * Les POKÉMON SAUVAGES du monde : Crémieu, Route 1 (et sa grotte), Bachut, la cour de l'ORT,
 * les abords du Parc Safari et le Parc Safari (4 zones : Kanto, Johto, Hoenn, Sinnoh).
 *
 *   php artisan db:seed --class=RegionSeeder
 *
 * Relançable sans doublon : TOUTES les rencontres de ces cartes sont effacées puis recréées.
 *
 * Une ligne : [espèce (slug PokéAPI), niveau min, niveau max, poids, moment ?]
 *   poids  = probabilité relative dans la table (à un moment donné) ;
 *   moment = 'morning' (matin), 'day' (journée) ou 'night' (nuit) : la ligne ne compte qu'à ce moment-là ;
 *            sans moment : à toute heure. Chaque table garde des Pokémon à toute heure.
 * Méthodes : grass (hautes herbes), cave (grotte), surf (sur l'eau), old_rod (Canne), good_rod (Super Canne),
 * super_rod (Méga Canne). Chaque étendue d'eau a les quatre tables d'eau.
 * Zones de terrain (propriété de tuile "terrain") : sable, roche (Parc Safari), gravier (cour de l'ORT).
 *
 * Les 212 familles de Pokémon des générations 1 à 4 (hors légendaires) sont toutes trouvables dans le monde :
 * les Pokémon courants près de Crémieu, puis de plus en plus variés ; chaque région a sa zone du Parc Safari
 * (starters, fossiles et raretés y compris). Les bébés (Pichu, Okéoké...) s'obtiennent à la Pension.
 * Une espèce absente de ta base est simplement ignorée.
 */
class RegionSeeder extends Seeder
{
    public const MAPS = [
        'cremieu', 'road_1', 'road_1_cave', 'bachut', 'ort_courtyard', 'cremieu_safari',
        'safari_kanto', 'safari_jotho', 'safari_hoenn', 'safari_sinnoh',
    ];

    public function run(): void
    {
        WildEncounter::whereIn('map_key', self::MAPS)->delete();

        // =================================================================
        // Crémieu : les herbes autour de la ville, et l'étang
        // =================================================================
        $this->table('cremieu', 'grass', [
            ['pidgey', 2, 4, 25], ['rattata', 2, 4, 20], ['sentret', 2, 4, 10], ['zigzagoon', 2, 4, 10], ['bidoof', 2, 4, 10], ['pikachu', 3, 5, 3],
            ['ledyba', 2, 4, 15, 'morning'], ['starly', 2, 4, 12, 'morning'], ['wurmple', 2, 4, 10, 'morning'],
            ['taillow', 2, 4, 10, 'day'], ['skitty', 3, 5, 6, 'day'],
            ['hoothoot', 2, 4, 15, 'night'], ['spinarak', 2, 4, 12, 'night'], ['kricketot', 2, 4, 10, 'night'], ['poochyena', 3, 5, 8, 'night'],
        ]);
        $this->water('cremieu',
            surf: [['psyduck', 10, 20, 35], ['poliwag', 10, 20, 30], ['lotad', 10, 20, 15], ['surskit', 10, 20, 10], ['wooper', 10, 20, 15, 'night']],
            old: [['magikarp', 5, 10, 80], ['goldeen', 5, 10, 20]],
            good: [['goldeen', 10, 20, 40], ['poliwag', 10, 20, 30], ['barboach', 10, 20, 20], ['magikarp', 10, 20, 10]],
            super: [['seaking', 20, 30, 25], ['poliwhirl', 20, 30, 20], ['gyarados', 20, 30, 15], ['corphish', 20, 30, 15],
                ['carvanha', 20, 30, 15], ['whiscash', 25, 30, 10]],
        );

        // =================================================================
        // Route 1
        // =================================================================
        $this->table('road_1', 'grass', [
            ['pidgey', 3, 6, 18], ['rattata', 3, 6, 18], ['caterpie', 3, 5, 10], ['weedle', 3, 5, 10], ['nidoran-f', 4, 6, 6], ['nidoran-m', 4, 6, 6],
            ['ekans', 4, 6, 6], ['mankey', 4, 6, 6], ['shinx', 4, 6, 5], ['pikachu', 4, 6, 3], ['abra', 5, 7, 3], ['eevee', 5, 7, 1],
            ['ledyba', 4, 6, 10, 'morning'], ['wurmple', 3, 5, 10, 'morning'], ['starly', 4, 6, 8, 'morning'], ['hoppip', 4, 6, 8, 'morning'], ['combee', 4, 6, 5, 'morning'],
            ['spearow', 4, 6, 8, 'day'], ['taillow', 4, 6, 6, 'day'], ['mareep', 4, 6, 6, 'day'],
            ['hoothoot', 4, 6, 12, 'night'], ['spinarak', 4, 6, 10, 'night'], ['kricketot', 4, 6, 8, 'night'], ['poochyena', 4, 6, 8, 'night'],
            ['oddish', 4, 6, 8, 'night'], ['meowth', 4, 6, 5, 'night'], ['gastly', 5, 7, 4, 'night'], ['volbeat', 5, 7, 3, 'night'], ['illumise', 5, 7, 3, 'night'],
        ]);
        $this->water('road_1',
            surf: [['psyduck', 10, 20, 30], ['goldeen', 10, 20, 25], ['lotad', 10, 20, 15], ['surskit', 10, 20, 10], ['wooper', 10, 20, 10], ['marill', 10, 20, 10]],
            old: [['magikarp', 5, 10, 80], ['goldeen', 5, 10, 20]],
            good: [['goldeen', 10, 20, 35], ['poliwag', 10, 20, 25], ['magikarp', 10, 20, 15], ['barboach', 10, 20, 15], ['carvanha', 10, 20, 10]],
            super: [['seaking', 20, 30, 25], ['poliwhirl', 20, 30, 20], ['gyarados', 20, 30, 15], ['whiscash', 20, 30, 15], ['corphish', 20, 30, 15],
                ['dratini', 15, 20, 3]],
        );

        // La grotte de la Route 1 (des Pokémon sur toutes les cases)
        $this->table('road_1_cave', 'cave', [
            ['zubat', 4, 8, 30], ['geodude', 5, 8, 25], ['paras', 5, 8, 10], ['whismur', 5, 8, 10], ['machop', 5, 8, 8], ['makuhita', 5, 8, 8],
            ['onix', 7, 9, 4], ['aron', 6, 9, 5], ['meditite', 6, 9, 4], ['bronzor', 6, 9, 3], ['dunsparce', 6, 9, 3], ['nosepass', 6, 9, 3],
            ['sableye', 7, 9, 2], ['mawile', 7, 9, 2],
        ]);

        // =================================================================
        // Bachut : les hautes herbes du parc (le soir, les Pokémon Spectre et Ténèbres sortent)
        // =================================================================
        $this->table('bachut', 'grass', [
            ['pidgey', 8, 12, 20], ['rattata', 8, 12, 15], ['meowth', 9, 12, 10], ['spearow', 9, 12, 10], ['skitty', 9, 12, 6], ['glameow', 9, 12, 6],
            ['buneary', 9, 12, 5], ['pachirisu', 9, 12, 5], ['jigglypuff', 9, 12, 5], ['snubbull', 9, 12, 5], ['spinda', 9, 12, 4],
            ['clefairy', 10, 12, 2], ['smeargle', 10, 12, 2], ['kecleon', 10, 12, 2], ['chatot', 10, 12, 2],
            ['natu', 9, 12, 6, 'morning'], ['cherubi', 9, 12, 6, 'morning'], ['swablu', 9, 12, 5, 'morning'], ['togepi', 8, 10, 1, 'morning'],
            ['growlithe', 10, 12, 5, 'day'], ['vulpix', 10, 12, 5, 'day'],
            ['hoothoot', 9, 12, 8, 'night'], ['zubat', 9, 12, 6, 'night'], ['murkrow', 10, 12, 5, 'night'], ['stunky', 10, 12, 5, 'night'],
            ['houndour', 10, 12, 4, 'night'], ['drifloon', 10, 12, 4, 'night'], ['duskull', 10, 12, 4, 'night'], ['shuppet', 10, 12, 4, 'night'],
            ['misdreavus', 10, 12, 3, 'night'],
        ]);

        // =================================================================
        // La cour de l'ORT : hautes herbes sur le gravier (Roche, Sol, Combat... et les Pokémon de la ville)
        // =================================================================
        $this->table('ort_courtyard', 'grass', [['pidgey', 10, 13, 40], ['rattata', 10, 13, 40], ['meowth', 11, 13, 20]]);
        $this->table('ort_courtyard', 'grass', [
            ['geodude', 10, 14, 20], ['sandshrew', 10, 14, 15], ['diglett', 10, 14, 12], ['cubone', 11, 14, 10], ['phanpy', 11, 14, 8],
            ['machop', 11, 14, 6], ['magnemite', 11, 14, 6], ['drowzee', 11, 14, 6], ['trapinch', 11, 14, 6], ['hippopotas', 11, 14, 6],
            ['nincada', 10, 13, 6], ['numel', 11, 14, 6], ['slugma', 11, 14, 6], ['spoink', 11, 14, 5], ['nosepass', 11, 14, 5], ['voltorb', 11, 14, 5],
            ['koffing', 11, 14, 5], ['onix', 12, 14, 4], ['meditite', 11, 14, 4], ['tyrogue', 11, 14, 2], ['unown', 12, 14, 2], ['larvitar', 12, 14, 2],
            ['riolu', 12, 14, 1],
            ['grimer', 11, 14, 8, 'night'], ['gastly', 11, 14, 6, 'night'], ['rotom', 13, 14, 1, 'night'],
        ], 'gravier');

        // =================================================================
        // Les abords du Parc Safari : plantes et insectes
        // =================================================================
        $this->table('cremieu_safari', 'grass', [
            ['oddish', 5, 8, 15], ['bellsprout', 5, 8, 15], ['venonat', 6, 8, 10], ['hoppip', 5, 8, 10], ['paras', 5, 8, 8], ['sunkern', 5, 8, 8],
            ['seedot', 5, 8, 8], ['budew', 5, 8, 8], ['shroomish', 5, 8, 8], ['cherubi', 5, 8, 6], ['exeggcute', 7, 9, 6], ['tangela', 7, 9, 3],
            ['ledyba', 5, 8, 8, 'morning'], ['combee', 6, 8, 6, 'morning'], ['burmy', 6, 8, 5, 'morning'],
            ['nincada', 5, 8, 6, 'day'], ['caterpie', 5, 8, 6, 'day'],
            ['hoothoot', 5, 8, 8, 'night'], ['venonat', 6, 8, 8, 'night'], ['kricketot', 5, 8, 6, 'night'], ['spinarak', 5, 8, 6, 'night'],
            ['volbeat', 6, 9, 4, 'night'], ['illumise', 6, 9, 4, 'night'],
        ]);

        // =================================================================
        // Le Parc Safari : une zone par région (tous les Pokémon de la région, starters et fossiles compris)
        // =================================================================

        // ---- Kanto ----
        $this->table('safari_kanto', 'grass', [
            ['nidoran-f', 22, 26, 12], ['nidoran-m', 22, 26, 12], ['exeggcute', 22, 26, 12], ['doduo', 22, 26, 10], ['ponyta', 22, 26, 8],
            ['rhyhorn', 23, 27, 8], ['venonat', 22, 25, 8], ['drowzee', 22, 26, 6], ['tangela', 23, 27, 4], ['kangaskhan', 25, 28, 4],
            ['scyther', 25, 28, 3], ['pinsir', 25, 28, 3], ['tauros', 25, 28, 3], ['lickitung', 24, 27, 2], ['farfetchd', 23, 26, 2],
            ['ditto', 23, 27, 2], ['chansey', 25, 28, 2], ['eevee', 22, 25, 1], ['snorlax', 28, 30, 1],
            ['bulbasaur', 20, 22, 1], ['charmander', 20, 22, 1], ['squirtle', 20, 22, 1],
            ['doduo', 22, 26, 6, 'morning'], ['nidoran-f', 22, 26, 4, 'morning'], ['nidoran-m', 22, 26, 4, 'morning'],
            ['electabuzz', 24, 27, 2, 'day'], ['mr-mime', 24, 27, 2, 'day'], ['growlithe', 22, 26, 4, 'day'],
            ['gastly', 22, 26, 8, 'night'], ['venonat', 22, 25, 6, 'night'], ['jynx', 24, 27, 1, 'night'],
        ]);
        $this->table('safari_kanto', 'grass', [
            ['sandshrew', 22, 26, 30], ['diglett', 22, 26, 25], ['cubone', 23, 27, 20], ['sandslash', 25, 28, 8], ['ponyta', 22, 26, 10], ['magmar', 24, 27, 3],
        ], 'sable');
        $this->table('safari_kanto', 'grass', [
            ['geodude', 22, 26, 30], ['machop', 22, 26, 15], ['onix', 23, 27, 15], ['graveler', 25, 28, 12], ['rhyhorn', 23, 27, 12],
            ['tyrogue', 22, 25, 3], ['aerodactyl', 25, 28, 2],
        ], 'roche');
        $this->water('safari_kanto',
            surf: [['psyduck', 20, 30, 35], ['slowpoke', 20, 30, 25], ['tentacool', 20, 30, 15], ['seel', 20, 30, 12], ['dratini', 20, 25, 6], ['lapras', 25, 30, 2]],
            old: [['magikarp', 5, 10, 70], ['krabby', 5, 10, 15], ['horsea', 5, 10, 15]],
            good: [['goldeen', 15, 25, 35], ['poliwag', 15, 25, 20], ['krabby', 15, 25, 15], ['horsea', 15, 25, 15], ['shellder', 15, 25, 10], ['dratini', 15, 20, 5]],
            super: [['seaking', 25, 35, 15], ['gyarados', 25, 35, 15], ['staryu', 25, 35, 15], ['kingler', 25, 35, 10], ['seadra', 25, 35, 10],
                ['tentacruel', 25, 35, 10], ['slowbro', 30, 35, 5], ['dragonair', 30, 35, 5], ['omanyte', 25, 30, 5], ['kabuto', 25, 30, 5],
                ['dratini', 20, 25, 5]],
        );

        // ---- Johto ----
        $this->table('safari_jotho', 'grass', [
            ['sunkern', 22, 26, 10], ['aipom', 22, 26, 10], ['girafarig', 23, 27, 10], ['phanpy', 22, 26, 10], ['teddiursa', 22, 26, 8],
            ['yanma', 23, 27, 8], ['snubbull', 22, 26, 6], ['dunsparce', 22, 26, 4], ['gligar', 24, 27, 5], ['heracross', 25, 28, 3],
            ['stantler', 24, 27, 4], ['miltank', 25, 28, 2], ['chikorita', 20, 22, 1], ['cyndaquil', 20, 22, 1], ['totodile', 20, 22, 1],
            ['natu', 22, 26, 6, 'morning'], ['pineco', 22, 26, 8, 'morning'], ['hoppip', 22, 26, 6, 'morning'],
            ['mareep', 22, 26, 10, 'day'], ['girafarig', 23, 27, 4, 'day'],
            ['murkrow', 23, 27, 5, 'night'], ['houndour', 23, 27, 6, 'night'], ['misdreavus', 23, 27, 4, 'night'], ['hoothoot', 22, 26, 6, 'night'],
        ]);
        $this->table('safari_jotho', 'grass', [
            ['phanpy', 22, 26, 30], ['gligar', 23, 27, 25], ['swinub', 22, 26, 15], ['donphan', 25, 28, 12], ['larvitar', 22, 25, 8],
        ], 'sable');
        $this->table('safari_jotho', 'grass', [
            ['sudowoodo', 23, 27, 25], ['shuckle', 22, 26, 20], ['slugma', 22, 26, 15], ['onix', 23, 27, 12], ['larvitar', 22, 25, 10],
            ['unown', 22, 26, 5], ['delibird', 23, 27, 4], ['skarmory', 25, 28, 3],
            ['sneasel', 23, 27, 6, 'night'],
        ], 'roche');
        $this->water('safari_jotho',
            surf: [['marill', 20, 30, 35], ['wooper', 20, 30, 30], ['quagsire', 25, 30, 10], ['corsola', 20, 30, 10], ['mantine', 25, 30, 3]],
            old: [['magikarp', 5, 10, 80], ['remoraid', 5, 10, 20]],
            good: [['chinchou', 15, 25, 35], ['qwilfish', 15, 25, 25], ['remoraid', 15, 25, 20], ['corsola', 15, 25, 10], ['mantyke', 15, 20, 10]],
            super: [['lanturn', 25, 35, 15], ['octillery', 25, 35, 15], ['qwilfish', 25, 35, 15], ['remoraid', 25, 30, 15], ['gyarados', 25, 35, 15],
                ['chinchou', 25, 30, 10], ['corsola', 25, 35, 10], ['mantine', 30, 35, 5]],
        );

        // ---- Hoenn ----
        $this->table('safari_hoenn', 'grass', [
            ['zigzagoon', 22, 26, 10], ['shroomish', 22, 26, 10], ['seedot', 22, 26, 8], ['lotad', 22, 26, 8], ['electrike', 23, 27, 8],
            ['gulpin', 22, 26, 6], ['spoink', 22, 26, 6], ['wynaut', 22, 25, 4], ['zangoose', 24, 27, 3], ['tropius', 25, 28, 4],
            ['absol', 25, 28, 3], ['bagon', 24, 26, 2], ['treecko', 20, 22, 1], ['torchic', 20, 22, 1], ['mudkip', 20, 22, 1],
            ['swablu', 22, 26, 8, 'morning'], ['slakoth', 22, 26, 6, 'morning'], ['chingling', 22, 25, 3, 'morning'],
            ['ralts', 22, 25, 8, 'day'], ['plusle', 23, 27, 6, 'day'], ['minun', 23, 27, 6, 'day'], ['castform', 24, 27, 2, 'day'],
            ['seviper', 24, 27, 4, 'night'], ['shuppet', 23, 27, 5, 'night'], ['duskull', 23, 27, 5, 'night'], ['snorunt', 23, 27, 3, 'night'],
        ]);
        $this->table('safari_hoenn', 'grass', [
            ['trapinch', 22, 26, 25], ['cacnea', 22, 26, 25], ['baltoy', 23, 27, 20], ['numel', 22, 26, 15], ['sandshrew', 22, 26, 8],
            ['lileep', 24, 27, 2], ['anorith', 24, 27, 2],
        ], 'sable');
        $this->table('safari_hoenn', 'grass', [
            ['nosepass', 22, 26, 25], ['aron', 22, 26, 25], ['lairon', 25, 28, 10], ['torkoal', 24, 27, 10], ['lunatone', 24, 27, 10, 'night'],
            ['solrock', 24, 27, 10, 'day'], ['solrock', 24, 27, 5], ['lunatone', 24, 27, 5], ['beldum', 25, 27, 1],
        ], 'roche');
        $this->water('safari_hoenn',
            surf: [['wingull', 20, 30, 30], ['marill', 20, 30, 25], ['spheal', 20, 30, 15], ['wailmer', 20, 30, 15], ['corphish', 20, 30, 10]],
            old: [['magikarp', 5, 10, 75], ['luvdisc', 5, 10, 25]],
            good: [['barboach', 15, 25, 30], ['corphish', 15, 25, 20], ['luvdisc', 15, 25, 15], ['carvanha', 15, 25, 15], ['clamperl', 15, 25, 10],
                ['feebas', 15, 20, 10]],
            super: [['whiscash', 25, 35, 15], ['gyarados', 25, 35, 15], ['wailmer', 25, 35, 15], ['crawdaunt', 25, 35, 10], ['sharpedo', 25, 35, 10],
                ['corphish', 25, 30, 10], ['clamperl', 25, 30, 10], ['relicanth', 25, 30, 3], ['feebas', 20, 25, 2]],
        );

        // ---- Sinnoh ----
        $this->table('safari_sinnoh', 'grass', [
            ['starly', 22, 26, 10], ['bidoof', 22, 26, 10], ['shinx', 22, 26, 8], ['budew', 22, 26, 8], ['buneary', 22, 26, 8], ['pachirisu', 23, 27, 6],
            ['croagunk', 24, 27, 5], ['snover', 23, 27, 4], ['carnivine', 25, 28, 3], ['riolu', 22, 25, 2], ['gible', 24, 26, 1],
            ['turtwig', 20, 22, 1], ['chimchar', 20, 22, 1], ['piplup', 20, 22, 1],
            ['burmy', 22, 26, 6, 'morning'], ['combee', 22, 26, 6, 'morning'], ['munchlax', 22, 25, 2, 'morning'],
            ['cherubi', 22, 26, 6, 'day'], ['glameow', 22, 26, 6, 'day'], ['chatot', 23, 27, 3, 'day'],
            ['kricketot', 22, 26, 6, 'night'], ['stunky', 22, 26, 6, 'night'], ['drifloon', 23, 27, 4, 'night'], ['spiritomb', 25, 28, 1, 'night'],
        ]);
        $this->table('safari_sinnoh', 'grass', [
            ['hippopotas', 22, 26, 35], ['skorupi', 22, 26, 30], ['gligar', 23, 27, 20], ['gible', 22, 25, 8],
        ], 'sable');
        $this->table('safari_sinnoh', 'grass', [
            ['geodude', 22, 26, 25], ['cranidos', 22, 26, 20], ['shieldon', 22, 26, 20], ['bonsly', 22, 25, 15], ['bronzor', 22, 26, 15],
        ], 'roche');
        $this->water('safari_sinnoh',
            surf: [['buizel', 20, 30, 35], ['shellos', 20, 30, 35], ['floatzel', 25, 30, 10], ['gastrodon', 25, 30, 10], ['mantyke', 20, 25, 5]],
            old: [['magikarp', 5, 10, 80], ['finneon', 5, 10, 20]],
            good: [['finneon', 15, 25, 35], ['buizel', 15, 25, 25], ['magikarp', 15, 25, 20], ['shellos', 15, 25, 10], ['gyarados', 20, 25, 10]],
            super: [['lumineon', 25, 35, 20], ['gyarados', 25, 35, 20], ['floatzel', 25, 35, 15], ['gastrodon', 25, 35, 15], ['finneon', 25, 30, 15],
                ['shellos', 25, 30, 15]],
        );
    }

    /** Les quatre tables d'une étendue d'eau : surf, Canne, Super Canne, Méga Canne */
    private function water(string $map, array $surf, array $old, array $good, array $super, string $zone = 'default'): void
    {
        $this->table($map, 'surf', $surf, $zone);
        $this->table($map, 'old_rod', $old, $zone);
        $this->table($map, 'good_rod', $good, $zone);
        $this->table($map, 'super_rod', $super, $zone);
    }

    private function table(string $mapKey, string $method, array $pokemon, string $zone = 'default'): void
    {
        $count = 0;
        foreach ($pokemon as $row) {
            [$slug, $min, $max, $weight] = $row;
            $speciesId = PokemonSpecies::where('slug', $slug)->value('id');
            if (! $speciesId) {
                $this->command?->warn("  {$mapKey} : espèce inconnue {$slug} (ignorée)");
                continue;
            }
            WildEncounter::create([
                'map_key' => $mapKey, 'zone' => $zone, 'method' => $method, 'pokemon_species_id' => $speciesId,
                'min_level' => $min, 'max_level' => $max, 'weight' => $weight, 'time_of_day' => $row[4] ?? null,
            ]);
            $count++;
        }
        $this->command?->info("{$mapKey} ({$method}" . ($zone !== 'default' ? ", terrain {$zone}" : '') . ") : {$count} Pokémon");
    }
}
