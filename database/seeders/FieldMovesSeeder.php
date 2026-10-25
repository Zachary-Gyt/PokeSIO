<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Models\Type;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Étape 11 : les capacités utilisées hors combat (CS).
 *
 * Ta liste d'attaques vient d'un jeu récent qui a retiré Coupe, Flash et Éclate-Roc : on les ajoute,
 * avec leurs CS (CS01, CS05, CS06), et la compatibilité CS de Rouge Feu / Vert Feuille pour les 151
 * (données PokeAPI, version FireRed / LeafGreen). Vol, Surf et Force existent déjà : on complète
 * seulement leur compatibilité.
 *
 *   php artisan db:seed --class=FieldMovesSeeder
 *
 * On peut le relancer sans risque : rien n'est créé en double.
 */
class FieldMovesSeeder extends Seeder
{
    private const NO_META = [
        'ailment' => 'none', 'ailment_chance' => 0, 'flinch_chance' => 0, 'crit_rate' => 0, 'drain' => 0, 'healing' => 0,
        'min_hits' => null, 'max_hits' => null, 'min_turns' => null, 'max_turns' => null, 'stat_chance' => 0, 'stat_changes' => [],
    ];

    /** Numéros de Pokédex qui peuvent apprendre chaque CS (Rouge Feu / Vert Feuille) */
    private const COMPATIBLE = [
        'cut' => [
            1, 2, 3, 4, 5, 6, 15, 19, 20, 27, 28, 29, 30, 31, 32, 33, 34, 43, 44, 45,
            46, 47, 50, 51, 52, 53, 69, 70, 71, 72, 73, 83, 98, 99, 108, 112, 114, 115, 123, 127,
            141, 149, 151,
        ],
        'fly' => [
            6, 16, 17, 18, 21, 22, 83, 84, 85, 142, 144, 145, 146, 149, 151,
        ],
        'surf' => [
            7, 8, 9, 31, 34, 54, 55, 60, 61, 62, 72, 73, 79, 80, 86, 87, 90, 91, 98, 99,
            108, 112, 115, 116, 117, 118, 119, 120, 121, 128, 130, 131, 134, 138, 139, 140, 141, 143, 147, 148,
            149, 151,
        ],
        'strength' => [
            1, 2, 3, 4, 5, 6, 7, 8, 9, 20, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32,
            33, 34, 35, 36, 39, 40, 54, 55, 56, 57, 58, 59, 61, 62, 66, 67, 68, 74, 75, 76,
            77, 78, 79, 80, 89, 94, 95, 98, 99, 102, 103, 104, 105, 106, 107, 108, 111, 112, 113, 115,
            125, 126, 127, 128, 130, 131, 142, 143, 149, 150, 151,
        ],
        'flash' => [
            1, 2, 3, 12, 25, 26, 35, 36, 39, 40, 43, 44, 45, 46, 47, 48, 49, 52, 53, 54,
            55, 63, 64, 65, 69, 70, 71, 79, 80, 81, 82, 96, 97, 100, 101, 102, 103, 109, 110, 113,
            114, 120, 121, 122, 124, 125, 135, 137, 145, 150, 151,
        ],
        'rock-smash' => [
            1, 2, 3, 4, 5, 6, 7, 8, 9, 15, 19, 20, 25, 26, 27, 28, 29, 30, 31, 32,
            33, 34, 46, 47, 50, 51, 54, 55, 56, 57, 58, 59, 61, 62, 66, 67, 68, 74, 75, 76,
            80, 89, 94, 95, 98, 99, 104, 105, 106, 107, 108, 111, 112, 113, 114, 115, 123, 125, 126, 127,
            128, 130, 131, 138, 139, 140, 141, 142, 144, 145, 146, 149, 150, 151,
        ],
    ];

    /** Les CS de Kanto : numéro => capacité */
    private const HMS = [
        'hm01' => 'cut', 'hm02' => 'fly', 'hm03' => 'surf', 'hm04' => 'strength', 'hm05' => 'flash', 'hm06' => 'rock-smash',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $this->moves();
            $this->hiddenMachines();
            $this->compatibility();
        });
    }

    private function moves(): void
    {
        $normal = Type::where('slug', 'normal')->value('id');
        $fighting = Type::where('slug', 'fighting')->value('id');

        $moves = [
            'cut' => [
                'name' => 'Coupe', 'generation' => 1, 'type_id' => $normal, 'category' => 'physical',
                'power' => 50, 'accuracy' => 95, 'pp' => 30, 'makes_contact' => true,
                'effect_code' => 'damage', 'effect_chance' => null, 'meta' => self::NO_META,
                'description' => 'Coupe l’ennemi avec des lames ou des griffes. Hors combat, coupe les petits arbres.',
            ],
            'flash' => [
                'name' => 'Flash', 'generation' => 1, 'type_id' => $normal, 'category' => 'status',
                'power' => null, 'accuracy' => 100, 'pp' => 20, 'makes_contact' => false,
                'effect_code' => 'net-good-stats', 'effect_chance' => null,
                'meta' => ['stat_changes' => [['stat' => 'accuracy', 'change' => -1]]] + self::NO_META,
                'description' => 'Une lumière aveuglante qui baisse la Précision de l’ennemi. Hors combat, éclaire les grottes sombres.',
            ],
            'rock-smash' => [
                'name' => 'Éclate-Roc', 'generation' => 2, 'type_id' => $fighting, 'category' => 'physical',
                'power' => 40, 'accuracy' => 100, 'pp' => 15, 'makes_contact' => true,
                'effect_code' => 'damage+lower', 'effect_chance' => 50,
                'meta' => ['stat_chance' => 50, 'stat_changes' => [['stat' => 'defense', 'change' => -1]]] + self::NO_META,
                'description' => 'Porte un coup de poing qui peut baisser la Défense de l’ennemi. Hors combat, brise les rochers fissurés.',
            ],
        ];

        foreach ($moves as $slug => $data) {
            $move = Move::firstOrNew(['slug' => $slug]);
            $move->forceFill($data + ['priority' => 0, 'target' => 'selected-pokemon'])->save();
        }
    }

    private function hiddenMachines(): void
    {
        foreach (self::HMS as $slug => $moveSlug) {
            $move = Move::where('slug', $moveSlug)->first();
            if (! $move || Item::where('slug', $slug)->exists()) {
                continue; // les CS déjà présentes (CS02, CS03, CS04) ne sont pas touchées
            }
            Item::forceCreate([
                'slug' => $slug,
                'name' => 'CS' . substr($slug, 2) . ' ' . $move->name,
                'pocket' => 'tms',
                'category' => 'hidden-machines',
                'description' => "Apprend la capacité {$move->name} à un Pokémon compatible. Elle peut être utilisée autant de fois que vous le voulez.",
                'price' => 0, 'sell_price' => 0,
                'consumable' => false, 'tradable' => false,
                'usable_in_battle' => false, 'usable_overworld' => true, 'holdable' => false,
                'effect_code' => 'teach', 'effect_value' => null, 'move_id' => $move->id,
            ]);
        }
    }

    private function compatibility(): void
    {
        $species = PokemonSpecies::pluck('id', 'pokedex_number');

        foreach (self::COMPATIBLE as $moveSlug => $numbers) {
            $moveId = Move::where('slug', $moveSlug)->value('id');
            if (! $moveId) {
                continue;
            }
            $known = DB::table('pokemon_species_move')->where('move_id', $moveId)->where('learn_method', 'tm')
                ->pluck('pokemon_species_id')->map(fn ($id) => (int) $id)->all();
            $rows = collect($numbers)
                ->map(fn ($n) => $species[$n] ?? null)
                ->filter(fn ($id) => $id && ! in_array((int) $id, $known, true))
                ->map(fn ($id) => ['pokemon_species_id' => $id, 'move_id' => $moveId, 'learn_method' => 'tm', 'level' => null])
                ->values()->all();
            DB::table('pokemon_species_move')->insert($rows);
        }
    }
}
