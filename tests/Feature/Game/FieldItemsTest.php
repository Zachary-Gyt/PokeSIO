<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Services\FieldItemService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Repousse et Corde Sortie (étape 19) */
class FieldItemsTest extends GameTestCase
{
    private FieldItemService $field;

    protected function setUp(): void
    {
        parent::setUp();
        $this->field = app(FieldItemService::class);
    }

    public function test_a_repel_lasts_100_steps(): void
    {
        $sacha = GameWorld::character();
        $sacha->addItem(GameWorld::item('repel'));

        $this->field->useRepel($sacha, GameWorld::item('repel'));
        $this->assertSame(100, $this->field->repelSteps($sacha->fresh()));

        $r = $this->field->steps($sacha->fresh(), 60);
        $this->assertSame(40, $r['repel']);
        $this->assertFalse($r['repelEnded']);
    }

    public function test_the_end_of_a_repel_offers_another_one(): void
    {
        $sacha = GameWorld::character();
        $sacha->addItem(GameWorld::item('repel'), 2);
        $this->field->useRepel($sacha, GameWorld::item('repel'));

        $r = $this->field->steps($sacha->fresh(), 150);

        $this->assertSame(0, $r['repel']);
        $this->assertTrue($r['repelEnded']);
        $this->assertSame('Repousse', $r['repelNext']['name']);
    }

    public function test_cannot_stack_two_repels(): void
    {
        $sacha = GameWorld::character();
        $sacha->addItem(GameWorld::item('repel'), 2);
        $this->field->useRepel($sacha, GameWorld::item('repel'));

        $this->expectException(InvalidAction::class);
        $this->field->useRepel($sacha->fresh(), GameWorld::item('repel'));
    }

    public function test_a_repel_only_blocks_weaker_wild_pokemon(): void
    {
        config(['move_tutor.repel_mode' => 'level']);
        $sacha = GameWorld::character();   // Bulbizarre N. 10
        $sacha->setFlag('repel', 50);

        $this->assertTrue($this->field->repels($sacha->fresh(), 5));
        $this->assertFalse($this->field->repels($sacha->fresh(), 10));
        $this->assertFalse($this->field->repels($sacha->fresh(), 15));
    }

    public function test_escape_rope_only_works_in_a_cave(): void
    {
        GameWorld::map('test_route', [], []);
        $sacha = GameWorld::character(['current_map' => 'test_route']);
        $sacha->addItem(GameWorld::item('escape-rope'));

        $this->expectException(InvalidAction::class);
        $this->field->useEscapeRope($sacha, GameWorld::item('escape-rope'), 'test_route');
    }

    public function test_escape_rope_brings_back_to_the_cave_entrance(): void
    {
        GameWorld::map('test_ville');
        GameWorld::map('test_grotte', [], ['cave' => true]);
        $sacha = GameWorld::character(['current_map' => 'test_grotte']);
        $sacha->setFlag('escape_point', ['map' => 'test_ville', 'x' => 4, 'y' => 6, 'facing' => 'down']);
        $sacha->addItem(GameWorld::item('escape-rope'));

        $r = $this->field->useEscapeRope($sacha->fresh(), GameWorld::item('escape-rope'), 'test_grotte');

        $this->assertSame(['map' => 'test_ville', 'x' => 4, 'y' => 6, 'facing' => 'down'], $r['to']);
        $this->assertSame(0, $sacha->quantityOf(GameWorld::item('escape-rope')));
    }
}
