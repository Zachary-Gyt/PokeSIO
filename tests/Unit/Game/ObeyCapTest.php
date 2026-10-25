<?php

namespace Tests\Unit\Game;

use App\Services\BattleService;
use PHPUnit\Framework\TestCase;

/** Niveau maximal d'obéissance selon le nombre de Badges (règle de Rouge Feu / Vert Feuille) */
class ObeyCapTest extends TestCase
{
    public function test_more_badges_means_a_higher_cap(): void
    {
        $previous = 0;
        for ($badges = 0; $badges <= 8; $badges++) {
            $cap = BattleService::obeyCap($badges);
            $this->assertGreaterThanOrEqual($previous, $cap);
            $previous = $cap;
        }
        $this->assertSame(100, BattleService::obeyCap(8));
    }
}
