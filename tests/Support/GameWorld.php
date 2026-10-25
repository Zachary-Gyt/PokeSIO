<?php

namespace Tests\Support;

use App\Models\Ability;
use App\Models\Character;
use App\Models\Evolution;
use App\Models\Item;
use App\Models\Move;
use App\Models\Nature;
use App\Models\Pokemon;
use App\Models\PokemonSpecies;
use App\Models\Type;
use App\Models\User;
use App\Services\PokemonFactory;
use Illuminate\Support\Facades\DB;

/**
 * Étape 21 : un PETIT monde Pokémon pour les tests (base vide en mémoire, rien n'est téléchargé).
 *
 * Quelques types, natures, talents, attaques, objets et espèces : juste ce qu'il faut pour tester
 * les règles du jeu. Les noms sont ceux du jeu (Bulbizarre, Charge...), les valeurs sont les vraies.
 *
 *   GameWorld::seed();
 *   $sacha = GameWorld::character();                 // un joueur avec un Bulbizarre N. 10
 *   $pika = GameWorld::give($sacha, 'pikachu', 20);
 */
class GameWorld
{
    public static function seed(): void
    {
        foreach (['normal' => 'Normal', 'fire' => 'Feu', 'water' => 'Eau', 'grass' => 'Plante', 'electric' => 'Électrik',
            'poison' => 'Poison', 'flying' => 'Vol', 'psychic' => 'Psy', 'steel' => 'Acier', 'ground' => 'Sol'] as $slug => $name) {
            Type::forceCreate(['slug' => $slug, 'name' => $name]);
        }
        $t = Type::pluck('id', 'slug');
        foreach ([['fire', 'grass', 2], ['water', 'fire', 2], ['grass', 'water', 2], ['fire', 'water', 0.5], ['electric', 'water', 2], ['electric', 'ground', 0]] as [$a, $d, $m]) {
            DB::table('type_efficacies')->insert(['attacking_type_id' => $t[$a], 'defending_type_id' => $t[$d], 'multiplier' => $m]);
        }

        Nature::forceCreate(['slug' => 'hardy', 'name' => 'Hardi']);
        Nature::forceCreate(['slug' => 'adamant', 'name' => 'Rigide', 'increased_stat' => 'attack', 'decreased_stat' => 'sp_attack']);
        Nature::forceCreate(['slug' => 'modest', 'name' => 'Modeste', 'increased_stat' => 'sp_attack', 'decreased_stat' => 'attack']);

        foreach (['overgrow' => 'Engrais', 'chlorophyll' => 'Chlorophylle', 'blaze' => 'Brasier', 'flame-body' => 'Corps Ardent',
            'torrent' => 'Torrent', 'static' => 'Statik', 'run-away' => 'Fuite', 'limber' => 'Échauffement', 'imposter' => 'Imposteur',
            'magnet-pull' => 'Magnépiège', 'pressure' => 'Pression'] as $slug => $name) {
            Ability::forceCreate(['slug' => $slug, 'name' => $name]);
        }

        $moves = [
            // slug => [nom, type, catégorie, puissance, précision, PP]
            'tackle' => ['Charge', 'normal', 'physical', 40, 100, 35],
            'growl' => ['Rugissement', 'normal', 'status', null, 100, 40],
            'vine-whip' => ['Fouet Lianes', 'grass', 'physical', 45, 100, 25],
            'leech-seed' => ['Vampigraine', 'grass', 'status', null, 90, 10],
            'razor-leaf' => ['Tranch\'Herbe', 'grass', 'physical', 55, 95, 25],
            'ember' => ['Flammèche', 'fire', 'special', 40, 100, 25],
            'water-gun' => ['Pistolet à O', 'water', 'special', 40, 100, 25],
            'thunder-shock' => ['Éclair', 'electric', 'special', 40, 100, 30],
            'quick-attack' => ['Vive-Attaque', 'normal', 'physical', 40, 100, 30],
            'cut' => ['Coupe', 'normal', 'physical', 50, 95, 30],
            'petal-dance' => ['Danse Fleurs', 'grass', 'special', 120, 100, 10],
            'transform' => ['Morphing', 'normal', 'status', null, null, 10],
            'rain-dance' => ['Danse Pluie', 'water', 'status', null, null, 5],
        ];
        foreach ($moves as $slug => [$name, $type, $category, $power, $accuracy, $pp]) {
            Move::forceCreate([
                'slug' => $slug, 'name' => $name, 'generation' => 1, 'type_id' => $t[$type], 'category' => $category,
                'power' => $power, 'accuracy' => $accuracy, 'pp' => $pp, 'makes_contact' => $category === 'physical',
                'effect_code' => $slug === 'growl' ? 'net-good-stats' : ($category === 'status' ? 'unique' : 'damage'),
                'meta' => $slug === 'growl' ? ['stat_changes' => [['stat' => 'attack', 'change' => -1]]] : [],
                'description' => "Capacité {$name}.",
            ]);
        }

        $items = [
            // slug => [nom, poche, effet, valeur, prix, attributs en plus]
            'poke-ball' => ['Poké Ball', 'balls', 'catch', 10, 200, ['usable_in_battle' => true]],
            'master-ball' => ['Master Ball', 'balls', 'catch', 255, 0, ['usable_in_battle' => true]],
            'potion' => ['Potion', 'medicine', 'heal_hp', 20, 300, ['usable_in_battle' => true, 'usable_overworld' => true]],
            'rare-candy' => ['Super Bonbon', 'medicine', 'level_up', 1, 4800, ['usable_overworld' => true]],
            'repel' => ['Repousse', 'misc', 'repel', 100, 350, ['usable_overworld' => true]],
            'super-repel' => ['Super Repousse', 'misc', 'repel', 200, 500, ['usable_overworld' => true]],
            'escape-rope' => ['Corde Sortie', 'misc', 'escape', null, 550, ['usable_overworld' => true]],
            'coin-case' => ['Boîte Jetons', 'key_items', 'coin_case', null, 0, ['consumable' => false, 'tradable' => false]],
            'oval-charm' => ['Charme Ovale', 'key_items', null, null, 0, ['consumable' => false, 'tradable' => false]],
            'destiny-knot' => ['Nœud Destin', 'held_items', null, null, 0, ['holdable' => true]],
            'everstone' => ['Pierre Stase', 'held_items', null, null, 0, ['holdable' => true]],
            'charcoal' => ['Charbon', 'held_items', null, null, 1000, ['holdable' => true]],
            'hm01' => ['CS01 Coupe', 'tms', 'teach', null, 0, ['consumable' => false, 'move_id' => Move::where('slug', 'cut')->value('id')]],
        ];
        foreach ($items as $slug => [$name, $pocket, $effect, $value, $price, $extra]) {
            Item::forceCreate(['slug' => $slug, 'name' => $name, 'pocket' => $pocket, 'effect_code' => $effect, 'effect_value' => $value,
                'price' => $price, 'sell_price' => intdiv($price, 2), 'description' => "Objet {$name}."] + $extra);
        }

        $species = [
            // slug => [n°, nom, types, stats (PV, Att, Déf, AtqS, DéfS, Vit), croissance, capture, sexe, groupes, cycles, talents]
            'bulbasaur' => [1, 'Bulbizarre', ['grass', 'poison'], [45, 49, 49, 65, 65, 45], 'medium_slow', 45, 1, 'monster,plant', 20, ['overgrow', 'chlorophyll*']],
            'ivysaur' => [2, 'Herbizarre', ['grass', 'poison'], [60, 62, 63, 80, 80, 60], 'medium_slow', 45, 1, 'monster,plant', 20, ['overgrow', 'chlorophyll*']],
            'charmander' => [4, 'Salamèche', ['fire'], [39, 52, 43, 60, 50, 65], 'medium_slow', 45, 1, 'monster,dragon', 20, ['blaze']],
            'squirtle' => [7, 'Carapuce', ['water'], [44, 48, 65, 50, 64, 43], 'medium_slow', 45, 1, 'monster,water1', 20, ['torrent']],
            'rattata' => [19, 'Rattata', ['normal'], [30, 56, 35, 25, 35, 72], 'medium_fast', 255, 4, 'ground', 15, ['run-away']],
            'pikachu' => [25, 'Pikachu', ['electric'], [35, 55, 40, 50, 50, 90], 'medium_fast', 190, 4, 'ground,fairy', 10, ['static']],
            'ponyta' => [77, 'Ponyta', ['fire'], [50, 85, 55, 65, 65, 90], 'medium_fast', 190, 4, 'ground', 20, ['flame-body']],
            'magnemite' => [81, 'Magnéti', ['electric', 'steel'], [25, 35, 70, 95, 55, 45], 'medium_fast', 190, -1, 'mineral', 20, ['magnet-pull']],
            'ditto' => [132, 'Métamorph', ['normal'], [48, 48, 48, 48, 48, 48], 'medium_fast', 35, -1, 'ditto', 20, ['limber']],
            'mewtwo' => [150, 'Mewtwo', ['psychic'], [106, 110, 90, 154, 90, 130], 'slow', 3, -1, 'no-eggs', 120, ['pressure']],
        ];
        foreach ($species as $slug => [$dex, $name, $types, $stats, $growth, $capture, $gender, $groups, $cycles, $abilities]) {
            $s = PokemonSpecies::forceCreate([
                'pokedex_number' => $dex, 'slug' => $slug, 'name' => $name, 'generation' => 1, 'is_obtainable' => true,
                'primary_type_id' => $t[$types[0]], 'secondary_type_id' => isset($types[1]) ? $t[$types[1]] : null,
                'base_hp' => $stats[0], 'base_attack' => $stats[1], 'base_defense' => $stats[2],
                'base_sp_attack' => $stats[3], 'base_sp_defense' => $stats[4], 'base_speed' => $stats[5],
                'base_experience' => 64, 'growth_rate' => $growth, 'capture_rate' => $capture, 'gender_rate' => $gender,
                'is_starter' => in_array($slug, ['bulbasaur', 'charmander', 'squirtle'], true), 'is_legendary' => $slug === 'mewtwo',
                'egg_groups' => $groups, 'hatch_cycles' => $cycles,
                'sprite_front' => "game/sprites/pokemon/front/{$slug}.png", 'sprite_icon' => "game/sprites/pokemon/icons/{$slug}.png",
            ]);
            foreach ($abilities as $ability) {
                $hidden = str_ends_with($ability, '*');
                $s->abilities()->attach(Ability::where('slug', rtrim($ability, '*'))->value('id'), ['is_hidden' => $hidden]);
            }
        }

        $learn = [
            'bulbasaur' => [['tackle', 1], ['growl', 3], ['vine-whip', 9], ['leech-seed', 7], ['razor-leaf', 19]],
            'ivysaur' => [['tackle', 1], ['growl', 1], ['vine-whip', 9], ['razor-leaf', 20], ['petal-dance', 40]],
            'charmander' => [['tackle', 1], ['growl', 1], ['ember', 7]],
            'squirtle' => [['tackle', 1], ['water-gun', 7]],
            'rattata' => [['tackle', 1], ['quick-attack', 4]],
            'pikachu' => [['thunder-shock', 1], ['growl', 1], ['quick-attack', 6]],
            'ponyta' => [['tackle', 1], ['ember', 10]],
            'magnemite' => [['tackle', 1], ['thunder-shock', 6]],
            'ditto' => [['transform', 1]],
            'mewtwo' => [['tackle', 1]],
        ];
        foreach ($learn as $slug => $rows) {
            $s = PokemonSpecies::where('slug', $slug)->first();
            foreach ($rows as [$move, $level]) {
                $s->moves()->attach(Move::where('slug', $move)->value('id'), ['learn_method' => 'level_up', 'level' => $level]);
            }
        }
        // Une capacité Œuf de Bulbizarre, et Coupe par CS
        PokemonSpecies::where('slug', 'bulbasaur')->first()->moves()->attach(Move::where('slug', 'petal-dance')->value('id'), ['learn_method' => 'egg']);
        foreach (['bulbasaur', 'ivysaur', 'rattata'] as $slug) {
            PokemonSpecies::where('slug', $slug)->first()->moves()->attach(Move::where('slug', 'cut')->value('id'), ['learn_method' => 'tm']);
        }

        Evolution::forceCreate([
            'from_species_id' => PokemonSpecies::where('slug', 'bulbasaur')->value('id'),
            'to_species_id' => PokemonSpecies::where('slug', 'ivysaur')->value('id'),
            'trigger' => 'level', 'min_level' => 16,
        ]);
    }

    private static int $users = 0;

    /** Un compte et un personnage, avec un premier Pokémon (Bulbizarre N. 10 par défaut) */
    public static function character(array $attributes = [], string $starter = 'bulbasaur', int $level = 10): Character
    {
        self::$users++;
        $user = User::forceCreate(['name' => 'Joueur ' . self::$users, 'email' => 'joueur' . self::$users . '-' . uniqid() . '@test.fr', 'password' => bcrypt('password')]);
        $character = new Character(array_merge([
            'slot' => 1, 'name' => 'Sacha', 'gender' => 'male', 'avatar' => 'red', 'trainer_id' => 10000 + self::$users,
            'starter_species_id' => PokemonSpecies::where('slug', $starter)->value('id'),
            'current_map' => 'test_ville', 'position_x' => 2, 'position_y' => 2, 'facing' => 'down',
        ], $attributes));
        $character->user_id = $user->id;
        $character->money = $attributes['money'] ?? 3000;
        $character->save();
        self::give($character, $starter, $level);

        return $character->fresh();
    }

    /** Donne un Pokémon (équipe, sinon PC). $options : gender, nature, shiny, ivs... ; $origin : trainer_id, trainer_name */
    public static function give(Character $character, string $slug, int $level = 5, array $options = [], array $origin = []): Pokemon
    {
        $factory = app(PokemonFactory::class);

        return $factory->giveTo($character, $factory->make(PokemonSpecies::where('slug', $slug)->firstOrFail(), $level, $options), $origin);
    }

    public static function item(string $slug): Item
    {
        return Item::where('slug', $slug)->firstOrFail();
    }

    /**
     * Écrit une carte Tiled de test dans le dossier public (remplacé pendant les tests, voir GameTestCase).
     * $objects : [calque => [[x, y, [propriétés]], ...]] ; $properties : propriétés de la carte.
     */
    public static function map(string $key, array $objects = [], array $properties = [], int $width = 12, int $height = 10, array $tileProps = []): void
    {
        $layers = [
            ['id' => 1, 'name' => 'ground', 'type' => 'tilelayer', 'width' => $width, 'height' => $height, 'data' => array_fill(0, $width * $height, 1)],
        ];
        $id = 2;
        foreach ($objects as $layer => $list) {
            $layerObjects = [];
            foreach ($list as $o) {
                // Étape 24 : chaque objet a son propre numéro (comme dans Tiled), et un nom facultatif ($o[3])
                $layerObjects[] = [
                    'id' => $id++, 'name' => $o[3] ?? '', 'x' => $o[0] * 32, 'y' => $o[1] * 32, 'width' => 32, 'height' => 32,
                    'properties' => collect($o[2] ?? [])->map(fn ($v, $k) => ['name' => $k, 'type' => is_bool($v) ? 'bool' : (is_int($v) ? 'int' : 'string'), 'value' => $v])->values()->all(),
                ];
            }
            $layers[] = ['id' => $id++, 'name' => $layer, 'type' => 'objectgroup', 'objects' => $layerObjects];
        }
        $tiles = collect($tileProps)->map(fn ($props, $tileId) => ['id' => $tileId, 'properties' => collect($props)->map(fn ($v, $k) => ['name' => $k, 'type' => 'bool', 'value' => $v])->values()->all()])->values()->all();
        $map = [
            'width' => $width, 'height' => $height, 'tilewidth' => 32, 'tileheight' => 32,
            'properties' => collect($properties)->map(fn ($v, $k) => ['name' => $k, 'type' => is_bool($v) ? 'bool' : 'string', 'value' => $v])->values()->all(),
            'tilesets' => [['firstgid' => 1, 'name' => 'test', 'tilecount' => 4, 'columns' => 2, 'tiles' => $tiles]],
            'layers' => $layers,
        ];
        $dir = public_path('game/maps');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents("{$dir}/{$key}.tmj", json_encode($map));
    }
}
