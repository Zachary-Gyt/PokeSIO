<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Models\Move;
use App\Services\MoveTutorService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le Maître des capacités (étape 19) */
class MoveTutorTest extends GameTestCase
{
    private function ids(array $slugs): array
    {
        return array_map(fn ($s) => (int) Move::where('slug', $s)->value('id'), $slugs);
    }

    public function test_options_include_known_and_forgotten_level_up_moves(): void
    {
        $sacha = GameWorld::character();   // Bulbizarre N. 10
        $slugs = collect(app(MoveTutorService::class)->options($sacha->party()->first()))->pluck('id')->all();

        foreach ($this->ids(['tackle', 'growl', 'leech-seed', 'vine-whip']) as $id) {
            $this->assertContains($id, $slugs);
        }
        $this->assertNotContains($this->ids(['razor-leaf'])[0], $slugs, 'appris au N. 19 : pas encore');
    }

    public function test_an_evolution_can_relearn_its_pre_evolution_moves(): void
    {
        config(['move_tutor.include_pre_evolutions' => true]);
        $sacha = GameWorld::character();
        $ivy = GameWorld::give($sacha, 'ivysaur', 20);

        $ids = collect(app(MoveTutorService::class)->options($ivy))->pluck('id')->all();
        $this->assertContains($this->ids(['leech-seed'])[0], $ids);   // seulement dans le learnset de Bulbizarre
    }

    public function test_apply_reorders_and_keeps_pp(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $vine = $bulbi->moves()->whereHas('move', fn ($q) => $q->where('slug', 'vine-whip'))->first();
        $vine->forceFill(['current_pp' => 3])->save();

        app(MoveTutorService::class)->apply($sacha, $bulbi->fresh(), $this->ids(['vine-whip', 'tackle']));

        $moves = $bulbi->fresh()->moves()->with('move')->orderBy('slot')->get();
        $this->assertSame(['vine-whip', 'tackle'], $moves->pluck('move.slug')->all());
        $this->assertSame(3, (int) $moves[0]->current_pp);
    }

    public function test_cannot_choose_an_unknown_move_or_more_than_four(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();

        try {
            app(MoveTutorService::class)->apply($sacha, $bulbi, $this->ids(['ember']));
            $this->fail('Flammèche ne devrait pas être proposée à Bulbizarre');
        } catch (InvalidAction) {
        }
        $this->expectException(InvalidAction::class);
        app(MoveTutorService::class)->apply($sacha, $bulbi, $this->ids(['tackle', 'growl', 'vine-whip', 'leech-seed', 'tackle']));
    }

    public function test_a_price_is_charged_when_configured(): void
    {
        config(['move_tutor.price' => 500]);
        $sacha = GameWorld::character(['money' => 600]);

        $paid = app(MoveTutorService::class)->apply($sacha, $sacha->party()->first(), $this->ids(['tackle']));

        $this->assertSame(500, $paid);
        $this->assertSame(100, (int) $sacha->fresh()->money);
    }
}
