<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Services\ItemBallService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Les Poké Balls posées sur les cartes (étape 16) : une seule fois par joueur */
class ItemBallTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GameWorld::map('test_route', ['items' => [
            [3, 4, ['id' => 'potion1', 'item' => 'potion', 'quantity' => 2]],
            [5, 1, ['id' => 'cache', 'item' => 'rare-candy', 'hidden' => true]],
            [7, 7, ['id' => 'argent', 'money' => 500]],
        ]]);
    }

    public function test_remaining_balls_are_listed_with_their_position(): void
    {
        $sacha = GameWorld::character();
        $balls = collect(app(ItemBallService::class)->remaining($sacha, 'test_route'))->keyBy('key');

        $this->assertSame(['x' => 3, 'y' => 4], ['x' => $balls['potion1']['x'], 'y' => $balls['potion1']['y']]);
        $this->assertTrue($balls['cache']['hidden']);
    }

    public function test_a_ball_can_only_be_picked_once(): void
    {
        $sacha = GameWorld::character();
        $balls = app(ItemBallService::class);

        $balls->pick($sacha, 'test_route', 'potion1');
        $this->assertSame(2, $sacha->quantityOf(GameWorld::item('potion')));
        $this->assertCount(2, $balls->remaining($sacha->fresh(), 'test_route'));

        $this->expectException(InvalidAction::class);
        $balls->pick($sacha->fresh(), 'test_route', 'potion1');
    }

    public function test_each_player_gets_their_own_ball(): void
    {
        $sacha = GameWorld::character();
        $ondine = GameWorld::character(['name' => 'Ondine']);
        app(ItemBallService::class)->pick($sacha, 'test_route', 'potion1');

        $this->assertCount(3, app(ItemBallService::class)->remaining($ondine, 'test_route'));
    }

    public function test_money_balls_give_money(): void
    {
        $sacha = GameWorld::character(['money' => 100]);
        app(ItemBallService::class)->pick($sacha, 'test_route', 'argent');

        $this->assertSame(600, (int) $sacha->fresh()->money);
    }

    public function test_unknown_ball(): void
    {
        $this->expectException(InvalidAction::class);
        app(ItemBallService::class)->pick(GameWorld::character(), 'test_route', 'nope');
    }
}
