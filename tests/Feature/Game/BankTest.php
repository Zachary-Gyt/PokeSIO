<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\BankService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** La Banque : dépôts, retraits, intérêts composés au prorata du temps, plafonds */
class BankTest extends GameTestCase
{
    private BankService $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();   // le temps n'avance que quand le test le dit (travel)
        $this->bank = app(BankService::class);
        config(['bank.daily_rate' => 0.10, 'bank.max_balance' => 9_999_999]);
        GameWorld::map('test_banque', ['signs' => [[2, 1, ['action' => 'bank']]]]);
    }

    public function test_deposit_and_withdraw_move_money(): void
    {
        $sacha = GameWorld::character(['money' => 5000]);
        $account = $this->bank->deposit($sacha, 2000);
        $this->assertSame(2000, $account['balance']);
        $this->assertSame(3000, $account['money']);

        $account = $this->bank->withdraw($sacha->fresh(), 500);
        $this->assertSame(1500, $account['balance']);
        $this->assertSame(3500, $sacha->fresh()->money);

        foreach ([fn () => $this->bank->deposit($sacha->fresh(), 4000), fn () => $this->bank->withdraw($sacha->fresh(), 1600)] as $bad) {
            try {
                $bad();
                $this->fail('Opération refusée attendue');
            } catch (InvalidAction $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_interest_is_compound_and_counts_offline_time(): void
    {
        $sacha = GameWorld::character(['money' => 20000]);
        $this->bank->deposit($sacha, 10000);

        $this->travel(1)->days();
        $status = $this->bank->status($sacha->fresh());
        $this->assertSame(11000, $status['balance']);
        $this->assertSame(1000, $status['earned']);

        $this->travel(12)->hours();   // une demi-journée : (1,1)^0,5
        $this->assertSame((int) floor(11000 * 1.1 ** 0.5), $this->bank->status($sacha->fresh())['balance']);

        $this->travel(2)->days();
        $this->assertSame((int) floor(11000 * 1.1 ** 2.5), $this->bank->status($sacha->fresh())['balance']);   // les intérêts s'enchaînent
    }

    public function test_caps(): void
    {
        config(['bank.max_balance' => 12000]);
        $sacha = GameWorld::character(['money' => 20000]);
        $this->bank->deposit($sacha, 10000);
        $this->assertSame(2000, $this->bank->status($sacha->fresh())['canDeposit']);

        $this->travel(10)->days();
        $status = $this->bank->status($sacha->fresh());
        $this->assertSame(12000, $status['balance']);   // plafonné
        $this->assertSame(0, $status['canDeposit']);

        // Le porte-monnaie ne dépasse pas 999 999 ₽
        $rich = GameWorld::character(['money' => Character::MAX_MONEY]);
        $rich->forceFill(['bank_balance' => 5000, 'bank_updated_at' => now()])->save();
        $this->assertSame(0, $this->bank->status($rich)['canWithdraw']);
        $this->expectException(InvalidAction::class);
        $this->bank->withdraw($rich, 10);
    }

    public function test_http_needs_a_bank_on_the_map(): void
    {
        $sacha = GameWorld::character(['money' => 3000]);
        $this->actingAs($sacha->user);
        $base = "/jouer/{$sacha->id}/banque";

        $this->getJson("{$base}?map=test_ville")->assertStatus(422);
        $this->getJson("{$base}?map=test_banque")->assertOk()->assertJsonPath('balance', 0)->assertJsonPath('money', 3000);
        $this->postJson("{$base}/deposer", ['map' => 'test_banque', 'amount' => 1000])->assertOk()->assertJsonPath('balance', 1000);
        $this->postJson("{$base}/retirer", ['map' => 'test_banque', 'amount' => 5000])->assertStatus(422);
        $this->postJson("{$base}/retirer", ['map' => 'test_banque', 'amount' => 400])->assertOk()->assertJsonPath('money', 2400);
        $this->get('/wiki/activites/banque')->assertOk()->assertSee('174 494');
    }
}
