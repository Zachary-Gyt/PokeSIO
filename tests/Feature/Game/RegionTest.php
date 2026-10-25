<?php

namespace Tests\Feature\Game;

use App\Models\Character;
use App\Services\MapInfo;
use App\Services\SafariService;
use App\Support\RubyMarshal;
use Illuminate\Support\Facades\File;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** La nouvelle région : collisions partagées (tilesets/*.tsj, lues dans Essentials), grottes, Parc Safari à plusieurs zones */
class RegionTest extends GameTestCase
{
    /** Un Tilesets.rxdata minuscule (fabriqué avec Ruby) : un tileset "Outside" de 8 tuiles */
    private const RXDATA = 'BAhbCDBvOhFSUEc6OlRpbGVzZXQLOghAaWRpBjoKQG5hbWVJIgtEZWhvcnMGOgZFVDoSQHRpbGVzZXRfbmFtZUkiDE91dHNpZGUGOwhUOg5AcGFzc2FnZXN1OgpUYWJsZQIkAwEAAACIAQAAAQAAAAEAAACIAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAPAIAAAAAHAAAAAAAAADoQQHByaW9yaXRpZXN1OwsCJAMBAAAAiAEAAAEAAAABAAAAiAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAA6EkB0ZXJyYWluX3RhZ3N1OwsCJAMBAAAAiAEAAAEAAAABAAAAiAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAQAHAAAAAAAw';

    public function test_ruby_marshal_reads_rpg_maker_tilesets(): void
    {
        $data = RubyMarshal::load(base64_decode(self::RXDATA));
        $this->assertNull($data[0]);
        $this->assertSame('Outside', $data[1]['@tileset_name']);
        $passages = RubyMarshal::table($data[1]['@passages']);
        $this->assertCount(392, $passages);
        $this->assertSame(0x0F, $passages[385]);
    }

    public function test_the_command_writes_shared_tile_properties(): void
    {
        // Un faux dossier Essentials, et la même image dans le jeu (reconnue par son contenu)
        $root = sys_get_temp_dir() . '/essentials-' . uniqid();
        File::ensureDirectoryExists("{$root}/Data");
        File::ensureDirectoryExists("{$root}/Graphics/Tilesets");
        File::ensureDirectoryExists(public_path('game/tilesets'));
        File::put("{$root}/Data/Tilesets.rxdata", base64_decode(self::RXDATA));
        $image = imagecreatetruecolor(256, 160);
        imagepng($image, "{$root}/Graphics/Tilesets/Outside.png");
        File::copy("{$root}/Graphics/Tilesets/Outside.png", public_path('game/tilesets/outside.png'));

        $this->artisan('game:tilesets', ['essentials' => $root])->assertSuccessful();
        $tiles = collect(json_decode(File::get(public_path('game/tilesets/outside.tsj')), true)['tiles'])
            ->mapWithKeys(fn ($t) => [$t['id'] => collect($t['properties'])->pluck('value', 'name')->all()]);

        $this->assertSame(['collides' => true], $tiles[1]);
        $this->assertSame(['counter' => true], $tiles[2]);
        $this->assertSame(['encounter' => true], $tiles[3]);
        $this->assertSame(['ledge' => 'down'], $tiles[4]);          // on n'y entre qu'en allant vers le bas
        $this->assertSame(['surf' => true, 'collides' => true], $tiles[5]);
        $this->assertSame(['over' => true], $tiles[6]);
        $this->assertSame(['encounter' => true, 'over' => true], $tiles[39]);   // tes hautes herbes sans fond

        // Une carte qui utilise ce tileset reçoit ces propriétés (sans rien cocher dans Tiled)
        GameWorld::map('test_herbe', [], [], 4, 4);
        $map = json_decode(File::get(public_path('game/maps/test_herbe.tmj')), true);
        $map['tilesets'] = [['firstgid' => 1, 'name' => 'outside', 'image' => '../tilesets/outside.png', 'columns' => 8, 'tilecount' => 40, 'tilewidth' => 32, 'tileheight' => 32]];
        $map['layers'][0]['data'] = array_fill(0, 16, 4);   // la tuile 3 (gid 4) : de l'herbe
        File::put(public_path('game/maps/test_herbe.tmj'), json_encode($map));
        $this->assertTrue(MapInfo::load('test_herbe')->tileHasProperty(1, 1, 'encounter'));
    }

    public function test_safari_gate_with_several_zones(): void
    {
        GameWorld::map('test_accueil', ['npcs' => [[3, 2, ['name' => 'Accueil', 'action' => 'safari', 'to_map' => 'test_zone_a,test_zone_b', 'to_x' => 2, 'to_y' => 2]]]]);
        GameWorld::map('test_zone_a', [], ['safari' => true]);
        GameWorld::map('test_zone_b', [], ['safari' => true]);
        $safari = app(SafariService::class);

        $sacha = GameWorld::character(['money' => 5000]);
        $this->assertSame('test_zone_b', $safari->enter($sacha, 'test_accueil', 3, 3, 'up', 'test_zone_b')['to']['map']);

        $ondine = GameWorld::character(['money' => 5000]);
        $this->assertSame('test_zone_a', $safari->enter($ondine, 'test_accueil', 3, 3, 'up')['to']['map']);   // par défaut : la première

        $pierre = GameWorld::character(['money' => 5000]);
        $this->expectException(\App\Battle\InvalidAction::class);
        $safari->enter($pierre, 'test_accueil', 3, 3, 'up', 'test_ville');
    }

    public function test_encounters_everywhere_in_a_cave(): void
    {
        GameWorld::map('test_grotte', [], ['cave' => true, 'encounters_everywhere' => true]);
        \App\Models\WildEncounter::create(['map_key' => 'test_grotte', 'zone' => 'default', 'method' => 'cave',
            'pokemon_species_id' => \App\Models\PokemonSpecies::where('slug', 'rattata')->value('id'), 'min_level' => 3, 'max_level' => 3, 'weight' => 1]);
        $sacha = GameWorld::character();
        $battle = app(\App\Services\BattleService::class)->startWild($sacha, 'test_grotte', 2, 2);
        $this->assertNotNull($battle);
        $this->assertSame('Rattata', $battle->state['foe']['party'][0]['speciesName']);
    }

    public function test_tall_grass_on_sand_uses_the_sand_table(): void
    {
        // Une carte 4 x 1 : sol (gid 1 = sable, gid 2 = herbe), hautes herbes partout sur le décor (gid 3)
        File::ensureDirectoryExists(public_path('game/tilesets'));
        File::put(public_path('game/tilesets/terrains.tsj'), json_encode(['name' => 'terrains', 'tiles' => [
            ['id' => 0, 'properties' => [['name' => 'terrain', 'type' => 'string', 'value' => 'sable']]],
            ['id' => 2, 'properties' => [['name' => 'encounter', 'type' => 'bool', 'value' => true]]],
        ]]));
        GameWorld::map('test_dunes', [], [], 4, 1);
        $map = json_decode(File::get(public_path('game/maps/test_dunes.tmj')), true);
        $map['tilesets'] = [['firstgid' => 1, 'name' => 'terrains', 'columns' => 8, 'tilecount' => 8, 'tilewidth' => 32, 'tileheight' => 32]];
        $map['layers'] = [
            ['name' => 'ground', 'type' => 'tilelayer', 'width' => 4, 'height' => 1, 'data' => [1, 1, 2, 2]],
            ['name' => 'decor', 'type' => 'tilelayer', 'width' => 4, 'height' => 1, 'data' => [3, 3, 3, 3]],
        ];
        File::put(public_path('game/maps/test_dunes.tmj'), json_encode($map));
        $species = \App\Models\PokemonSpecies::where('slug', 'rattata')->value('id');
        \App\Models\WildEncounter::create(['map_key' => 'test_dunes', 'zone' => 'sable', 'method' => 'grass', 'pokemon_species_id' => $species, 'min_level' => 3, 'max_level' => 3, 'weight' => 1]);

        $info = MapInfo::load('test_dunes');
        $this->assertTrue($info->tileHasProperty(0, 0, 'encounter'));
        $this->assertSame('sable', $info->encounterZone(0, 0));     // herbes sur le sable : la table « sable »
        $this->assertSame('default', $info->encounterZone(2, 0));   // herbes sur l'herbe : la table normale
    }
}
