<?php

namespace Tests\Feature\Game;

use App\Models\Pokemon;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le petit monde des tests est-il bien construit ? (si ce test casse, tous les autres casseront) */
class WorldFixtureTest extends GameTestCase
{
    public function test_a_character_starts_with_one_pokemon(): void
    {
        $sacha = GameWorld::character();

        $this->assertSame(1, $sacha->party()->count());
        $bulbi = $sacha->party()->first();
        $this->assertSame('Bulbizarre', $bulbi->display_name);
        $this->assertSame(10, $bulbi->level);
        $this->assertGreaterThan(0, $bulbi->current_hp);
        $this->assertGreaterThan(0, $bulbi->moves()->count());
    }

    public function test_the_seventh_pokemon_goes_to_the_pc(): void
    {
        $sacha = GameWorld::character();
        for ($i = 0; $i < 6; $i++) {
            GameWorld::give($sacha, 'rattata', 3);
        }

        $this->assertSame(Pokemon::PARTY_SIZE, $sacha->party()->count());
        $this->assertSame(1, $sacha->boxedPokemon()->count());
    }
}
