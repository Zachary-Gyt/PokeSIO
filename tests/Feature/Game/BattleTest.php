<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Models\PokemonSpecies;
use App\Services\BattleService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Les combats contre un Pokémon sauvage, joués par le serveur */
class BattleTest extends GameTestCase
{
    private function wild(string $slug, int $level, $character)
    {
        return app(BattleService::class)->startSpawn($character, PokemonSpecies::where('slug', $slug)->first(), $level);
    }

    public function test_a_wild_battle_starts_and_is_active(): void
    {
        $sacha = GameWorld::character();
        $battle = $this->wild('rattata', 2, $sacha);

        $this->assertTrue($battle->isActive());
        $this->assertSame($battle->id, app(BattleService::class)->activeBattle($sacha)->id);
    }

    public function test_only_one_battle_at_a_time(): void
    {
        $sacha = GameWorld::character();
        $this->wild('rattata', 2, $sacha);

        $this->expectException(InvalidAction::class);
        $this->wild('rattata', 2, $sacha);
    }

    public function test_cannot_battle_without_a_healthy_pokemon(): void
    {
        $sacha = GameWorld::character();
        $sacha->party()->update(['current_hp' => 0]);

        $this->expectException(InvalidAction::class);
        $this->wild('rattata', 2, $sacha);
    }

    public function test_fighting_a_weak_wild_pokemon_ends_with_a_win_and_exp(): void
    {
        $sacha = GameWorld::character([], 'bulbasaur', 30);
        $bulbi = $sacha->party()->first();
        $exp = (int) $bulbi->experience;
        $battle = $this->wild('rattata', 2, $sacha);
        $service = app(BattleService::class);
        // Une capacité offensive (Rugissement ne fait pas de dégâts)
        $attack = $bulbi->moves()->with('move')->orderBy('slot')->get()->search(fn ($pm) => $pm->move->power > 0);

        for ($turn = 0; $turn < 30 && $battle->fresh()->isActive(); $turn++) {
            $state = $battle->fresh()->state;
            $input = ($state['phase'] ?? '') === 'learn' ? ['type' => 'learn', 'forget' => null] : ['type' => 'move', 'slot' => $attack];
            $service->act($battle->fresh(), $input);
        }

        $this->assertFalse($battle->fresh()->isActive(), 'le combat aurait dû se terminer');
        $this->assertGreaterThan($exp, (int) $bulbi->fresh()->experience);
    }

    public function test_running_from_a_wild_battle(): void
    {
        $sacha = GameWorld::character([], 'bulbasaur', 50);
        $battle = $this->wild('rattata', 2, $sacha);
        $service = app(BattleService::class);

        // Bien plus rapide : la fuite réussit à coup sûr
        $service->act($battle, ['type' => 'run']);

        $this->assertFalse($battle->fresh()->isActive());
    }
}
