<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Services\StorageService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le PC : déposer, retirer, échanger, relâcher, et toujours un Pokémon en état de se battre */
class StorageTest extends GameTestCase
{
    public function test_deposit_then_withdraw(): void
    {
        $sacha = GameWorld::character();
        $rattata = GameWorld::give($sacha, 'rattata', 3);
        $pc = app(StorageService::class);

        $pc->move($sacha, $rattata, ['location' => 'box', 'box' => 1]);
        $this->assertSame('box', $rattata->fresh()->location);
        $this->assertSame(1, $rattata->fresh()->box_slot);
        $this->assertSame(1, $sacha->party()->count());

        $pc->move($sacha, $rattata->fresh(), ['location' => 'party']);
        $this->assertSame('party', $rattata->fresh()->location);
        $this->assertSame(2, $rattata->fresh()->party_slot);
    }

    public function test_cannot_deposit_the_last_healthy_pokemon(): void
    {
        $sacha = GameWorld::character();
        $this->expectException(InvalidAction::class);

        app(StorageService::class)->move($sacha, $sacha->party()->first(), ['location' => 'box', 'box' => 1]);
    }

    public function test_party_has_no_gap_after_a_deposit(): void
    {
        $sacha = GameWorld::character();
        $a = GameWorld::give($sacha, 'rattata', 3);
        $b = GameWorld::give($sacha, 'pikachu', 3);

        app(StorageService::class)->move($sacha, $a, ['location' => 'box', 'box' => 2]);

        $this->assertSame([1, 2], $sacha->party()->orderBy('party_slot')->pluck('party_slot')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(2, (int) $b->fresh()->party_slot);
    }

    public function test_moving_onto_an_occupied_slot_swaps_the_two(): void
    {
        $sacha = GameWorld::character();
        $pc = app(StorageService::class);
        $a = GameWorld::give($sacha, 'rattata', 3);
        $b = GameWorld::give($sacha, 'pikachu', 3);
        $pc->move($sacha, $a, ['location' => 'box', 'box' => 1, 'slot' => 5]);

        $pc->move($sacha, $b->fresh(), ['location' => 'box', 'box' => 1, 'slot' => 5]);

        $this->assertSame(5, (int) $b->fresh()->box_slot);
        $this->assertSame('party', $a->fresh()->location);
    }

    public function test_an_egg_cannot_be_released(): void
    {
        $sacha = GameWorld::character();
        $egg = GameWorld::give($sacha, 'rattata', 1);
        $egg->forceFill(['is_egg' => true, 'egg_steps' => 100])->save();

        $this->expectException(InvalidAction::class);
        app(StorageService::class)->release($sacha, $egg);
    }

    public function test_release_removes_the_pokemon(): void
    {
        $sacha = GameWorld::character();
        $r = GameWorld::give($sacha, 'rattata', 3);

        app(StorageService::class)->release($sacha, $r);

        $this->assertNull($r->fresh());
    }
}
