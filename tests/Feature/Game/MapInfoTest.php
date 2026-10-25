<?php

namespace Tests\Feature\Game;

use App\Services\MapInfo;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** La lecture des cartes Tiled par le serveur (MapInfo) */
class MapInfoTest extends GameTestCase
{
    public function test_unknown_map_is_null(): void
    {
        $this->assertNull(MapInfo::load('carte_inexistante'));
    }

    public function test_size_properties_and_actions(): void
    {
        GameWorld::map('test_casino', [
            'npcs' => [
                [1, 1, ['action' => 'coins']],
                [2, 1, ['action' => 'prizes', 'prizes' => 'tms']],
            ],
            'machines' => [
                [4, 4, ['action' => 'slots', 'difficulty' => 2]],
                [6, 4, ['action' => 'roulette', 'wager' => 3]],
            ],
        ], ['displayName' => 'Casino', 'cave' => false], 15, 11);

        $map = MapInfo::load('test_casino');

        $this->assertSame(15, $map->width());
        $this->assertSame(11, $map->height());
        $this->assertSame('Casino', $map->property('displayName'));
        $this->assertTrue($map->hasAction('coins'));
        $this->assertFalse($map->hasAction('shop'));
        $this->assertSame(['tms'], $map->casinoOptions('prizes'));
        $this->assertSame(['2'], $map->casinoOptions('slots'));
        $this->assertSame(['3'], $map->casinoOptions('roulette'));
    }

    public function test_warps_cover_their_whole_area(): void
    {
        GameWorld::map('test_ville', ['warps' => [[3, 9, ['to_map' => 'test_route']]]]);

        $map = MapInfo::load('test_ville');

        $this->assertSame('test_route', $map->warpAt(3, 9)['toMap']);
        $this->assertNull($map->warpAt(4, 9));
    }

    public function test_tile_properties(): void
    {
        GameWorld::map('test_herbe', [], [], 4, 4, [0 => ['grass' => true]]);

        $this->assertTrue(MapInfo::load('test_herbe')->tileHasProperty(1, 1, 'grass'));
        $this->assertFalse(MapInfo::load('test_herbe')->tileHasProperty(1, 1, 'water'));
    }
}
