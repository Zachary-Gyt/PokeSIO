<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Services\BerryBushService;
use Database\Seeders\ItemsGen4Seeder;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 22 : les buissons à Baies (chaque joueur a les siens, ils repoussent avec le temps) */
class BerryBushTest extends GameTestCase
{
    private BerryBushService $bushes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ItemsGen4Seeder::class);
        config(['berries.hours' => 24, 'berries.yield' => [1, 3]]);
        GameWorld::map('test_route', ['berries' => [
            [3, 4, ['berry' => 'oran-berry', 'id' => 'oran']],
            [5, 4, ['berry' => 'sitrus-berry', 'id' => 'sitrus', 'quantity' => 2, 'hours' => 4]],
        ]]);
        $this->bushes = app(BerryBushService::class);
    }

    public function test_a_new_bush_has_berries(): void
    {
        $sacha = GameWorld::character();
        $list = collect($this->bushes->bushes($sacha, 'test_route'))->keyBy('key');

        $this->assertSame(4, $list['oran']['stage']);
        $this->assertSame('berrytree_ORANBERRY', $list['oran']['sprite']);
        $this->assertSame(['x' => 3, 'y' => 4], ['x' => $list['oran']['x'], 'y' => $list['oran']['y']]);
    }

    public function test_picking_gives_the_announced_quantity(): void
    {
        $sacha = GameWorld::character();
        $look = $this->bushes->inspect($sacha, 'test_route', 'oran');
        $r = $this->bushes->pick($sacha, 'test_route', 'oran');

        $this->assertSame($look['quantity'], $r['quantity']);
        $this->assertGreaterThanOrEqual(1, $r['quantity']);
        $this->assertLessThanOrEqual(3, $r['quantity']);
        $this->assertSame($r['quantity'], $sacha->quantityOf(GameWorld::item('oran-berry')));
    }

    public function test_the_bush_regrows_over_time(): void
    {
        $sacha = GameWorld::character();
        $this->bushes->pick($sacha, 'test_route', 'sitrus');   // 4 heures pour repousser
        $stage = fn () => $this->bushes->inspect($sacha, 'test_route', 'sitrus')['stage'];

        $this->assertSame(0, $stage());
        $this->travel(61)->minutes();
        $this->assertSame(1, $stage());
        $this->travel(2)->hours();
        $this->assertSame(3, $stage());
        $this->travel(1)->hours();
        $this->assertSame(4, $stage());
        $this->assertSame(2, $this->bushes->pick($sacha, 'test_route', 'sitrus')['quantity']);
    }

    public function test_cannot_pick_twice(): void
    {
        $sacha = GameWorld::character();
        $this->bushes->pick($sacha, 'test_route', 'oran');

        $this->expectException(InvalidAction::class);
        $this->bushes->pick($sacha->fresh(), 'test_route', 'oran');
    }

    public function test_each_player_has_their_own_bushes(): void
    {
        $sacha = GameWorld::character();
        $ondine = GameWorld::character(['name' => 'Ondine']);
        $this->bushes->pick($sacha, 'test_route', 'oran');

        $this->assertSame(4, $this->bushes->inspect($ondine, 'test_route', 'oran')['stage']);
    }

    public function test_http_routes(): void
    {
        $sacha = GameWorld::character();
        $as = $this->actingAs($sacha->user);

        $as->getJson("/jouer/{$sacha->id}/baies?map=test_route")->assertOk()->assertJsonCount(2, 'bushes');
        $as->postJson("/jouer/{$sacha->id}/baies", ['map' => 'test_route', 'key' => 'oran'])->assertOk();
        $as->postJson("/jouer/{$sacha->id}/baies", ['map' => 'test_route', 'key' => 'oran'])->assertUnprocessable();
        $as->postJson("/jouer/{$sacha->id}/baies", ['map' => 'test_route', 'key' => 'nope'])->assertUnprocessable();
    }
}
