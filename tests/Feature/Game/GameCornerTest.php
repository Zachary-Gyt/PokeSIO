<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Services\GameCornerService;
use App\Services\RouletteService;
use App\Services\SlotMachineService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le Casino (étape 20) : jetons, lots, machines à sous, roulette. Le tirage est fait par le serveur. */
class GameCornerTest extends GameTestCase
{
    private function gambler(int $coins = 0, int $money = 3000)
    {
        $c = GameWorld::character(['money' => $money]);
        $c->addItem(GameWorld::item('coin-case'));
        $c->forceFill(['coins' => $coins])->save();

        return $c->fresh();
    }

    public function test_no_coins_without_a_coin_case(): void
    {
        $sacha = GameWorld::character();
        $this->expectException(InvalidAction::class);

        app(GameCornerService::class)->buy($sacha, 0);
    }

    public function test_50_coins_cost_1000(): void
    {
        $sacha = $this->gambler(0, 1500);

        $r = app(GameCornerService::class)->buy($sacha, 0);

        $this->assertSame(50, $r['coins']);
        $this->assertSame(500, $r['money']);
    }

    public function test_the_coin_case_holds_9999_coins_at_most(): void
    {
        $sacha = $this->gambler(9990, 5000);

        $this->expectException(InvalidAction::class);
        app(GameCornerService::class)->buy($sacha, 0);
    }

    public function test_add_coins_is_capped(): void
    {
        $sacha = $this->gambler(9990);

        $this->assertSame(9999, app(GameCornerService::class)->addCoins($sacha, 500));
        $this->assertFalse(app(GameCornerService::class)->spendCoins($sacha, 10000));
    }

    public function test_exchange_a_prize_item(): void
    {
        config(['game_corner.prizes.test' => [['item' => 'charcoal', 'price' => 1000]]]);
        $sacha = $this->gambler(1200);

        app(GameCornerService::class)->exchange($sacha, 'test', 0, 'test_ville');

        $this->assertSame(200, (int) $sacha->fresh()->coins);
        $this->assertSame(1, $sacha->quantityOf(GameWorld::item('charcoal')));
    }

    public function test_exchange_a_prize_pokemon(): void
    {
        config(['game_corner.prizes.test' => [['pokemon' => 'pikachu', 'level' => 9, 'price' => 180]]]);
        $sacha = $this->gambler(200);

        app(GameCornerService::class)->exchange($sacha, 'test', 0, 'test_ville');

        $this->assertSame(20, (int) $sacha->fresh()->coins);
        $this->assertTrue($sacha->pokemon()->whereHas('species', fn ($q) => $q->where('slug', 'pikachu'))->where('level', 9)->exists());
    }

    public function test_slot_payout_lines_follow_essentials(): void
    {
        $slots = app(SlotMachineService::class);
        // 3 Pikachu sur la ligne du milieu (rouleaux : [haut, milieu, bas])
        $pika = [[0, 3, 1], [2, 3, 4], [4, 3, 0]];

        $this->assertSame(15, $slots->payout($pika, 1)['payout']);
        // Ligne du milieu Magnéti / Psykokwak / Kokiyas : rien (mise de 1 : seule la ligne du milieu compte)
        $this->assertSame(0, $slots->payout([[0, 1, 2], [3, 4, 5], [6, 2, 1]], 1)['payout']);
        // Mise de 3 : les diagonales comptent aussi (3 Pikachu en diagonale)
        $this->assertSame(15, $slots->payout([[3, 1, 2], [4, 3, 5], [6, 2, 3]], 3)['payout']);
    }

    public function test_slot_machines_return_less_than_the_wager_over_time(): void
    {
        $sacha = $this->gambler(9999);
        $slots = app(SlotMachineService::class);
        $slots->open($sacha, 1);

        $spent = 0;
        $won = 0;
        for ($i = 0; $i < 2000; $i++) {
            $before = (int) $sacha->fresh()->coins;
            if ($before < 3) {
                $sacha->forceFill(['coins' => 9999])->save();
                $before = 9999;
            }
            $r = $slots->play($sacha->fresh(), 3);
            $after = (int) $r['coins'];
            $won += max(0, $after - $before + 3);
            $spent += 3;
        }

        $this->assertLessThan(1.1, $won / $spent, 'la machine ne doit pas donner des jetons à l\'infini');
    }

    public function test_roulette_bets_and_multipliers(): void
    {
        $roulette = app(RouletteService::class);
        $empty = array_fill(0, 12, false);

        $this->assertCount(1, $roulette->bet(2, 2));            // une case : x12
        $this->assertCount(3, $roulette->bet(3, 0));            // un Pokémon (colonne) : x4
        $this->assertCount(4, $roulette->bet(0, 1));            // une couleur (ligne) : x3
        $this->assertSame(12, $roulette->multiplier($empty, $roulette->bet(2, 2)));
        $this->assertSame(4, $roulette->multiplier($empty, $roulette->bet(3, 0)));
        $this->assertSame(3, $roulette->multiplier($empty, $roulette->bet(0, 1)));
    }

    public function test_roulette_never_lands_twice_on_the_same_slot(): void
    {
        $sacha = $this->gambler(9999);
        $roulette = app(RouletteService::class);
        $roulette->open($sacha, 1);

        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $roulette->play($sacha->fresh(), 0, 1 + ($i % 3));   // une couleur : 4 cases, jamais toutes sorties en 5 balles
            $this->assertNotContains($r['result'], $seen);
            $seen[] = $r['result'];
        }
    }

    public function test_roulette_returns_about_the_configured_rate(): void
    {
        config(['game_corner.roulette.payout_rate' => 0.9]);
        $sacha = $this->gambler(9999);
        $roulette = app(RouletteService::class);
        $roulette->open($sacha, 1);

        $spent = 0;
        $won = 0;
        for ($i = 0; $i < 3000; $i++) {
            if ($sacha->fresh()->coins < 10) {
                $sacha->forceFill(['coins' => 9999])->save();
            }
            // Toujours une case seule (x12 sur un plateau vide), choisie parmi les cases pas encore sorties
            $free = array_keys(array_filter($played ?? array_fill(0, 12, false), fn ($p) => ! $p));
            $cell = $free[array_rand($free)];
            $r = $roulette->play($sacha->fresh(), 1 + $cell % 4, 1 + intdiv($cell, 4));
            $played = $r['played'];
            $spent++;
            $won += $r['payout'];
        }

        $this->assertEqualsWithDelta(0.9, $won / $spent, 0.15);
    }
}
