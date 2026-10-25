<?php

namespace Tests\Feature\Game;

use App\Services\BreedingService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** La Pension : entente, chances d'Œuf, prix, Exp. par pas */
class BreedingTest extends GameTestCase
{
    private BreedingService $daycare;

    protected function setUp(): void
    {
        parent::setUp();
        $this->daycare = app(BreedingService::class);
    }

    public function test_male_and_female_of_a_shared_egg_group_get_along(): void
    {
        $sacha = GameWorld::character();
        $m = GameWorld::give($sacha, 'rattata', 10, ['gender' => 'male']);
        $f = GameWorld::give($sacha, 'pikachu', 10, ['gender' => 'female']);

        $this->assertSame('little', $this->daycare->compatibility($m, $f));   // espèces différentes, même dresseur
    }

    public function test_same_species_from_different_trainers_get_along_very_well(): void
    {
        $sacha = GameWorld::character();
        $m = GameWorld::give($sacha, 'rattata', 10, ['gender' => 'male']);
        $f = GameWorld::give($sacha, 'rattata', 10, ['gender' => 'female'], ['trainer_id' => 99999, 'trainer_name' => 'Ondine']);

        $this->assertSame('very_well', $this->daycare->compatibility($m, $f));
    }

    public function test_incompatible_pairs(): void
    {
        $sacha = GameWorld::character();
        $m1 = GameWorld::give($sacha, 'rattata', 10, ['gender' => 'male']);
        $m2 = GameWorld::give($sacha, 'pikachu', 10, ['gender' => 'male']);
        $squirtle = GameWorld::give($sacha, 'squirtle', 10, ['gender' => 'female']);
        $ditto = GameWorld::give($sacha, 'ditto', 10);
        $ditto2 = GameWorld::give($sacha, 'ditto', 10);
        $mewtwo = GameWorld::give($sacha, 'mewtwo', 70);

        $this->assertNull($this->daycare->compatibility($m1, $m2), 'deux mâles');
        $this->assertNull($this->daycare->compatibility($m1, $squirtle), 'aucun groupe Œuf commun');
        $this->assertNull($this->daycare->compatibility($ditto, $ditto2), 'deux Métamorph');
        $this->assertNull($this->daycare->compatibility($mewtwo, $ditto), 'légendaire');
    }

    public function test_ditto_breeds_with_genderless_pokemon(): void
    {
        $sacha = GameWorld::character();
        $magneti = GameWorld::give($sacha, 'magnemite', 10);
        $ditto = GameWorld::give($sacha, 'ditto', 10);

        $this->assertNotNull($this->daycare->compatibility($magneti, $ditto));
    }

    public function test_the_oval_charm_raises_the_egg_chance(): void
    {
        $sacha = GameWorld::character();
        $without = $this->daycare->eggChance($sacha, 'little');
        $sacha->addItem(GameWorld::item('oval-charm'));

        $this->assertGreaterThan($without, $this->daycare->eggChance($sacha->fresh(), 'little'));
        $this->assertSame(0, $this->daycare->eggChance($sacha, null));
    }

    public function test_price_is_levels_gained_plus_one_times_100(): void
    {
        $sacha = GameWorld::character();
        $p = GameWorld::give($sacha, 'rattata', 12);
        $p->daycare_level = 10;

        $this->assertSame(300, $this->daycare->price($p));
    }

    public function test_a_deposited_pokemon_gains_one_exp_per_step(): void
    {
        $sacha = GameWorld::character();
        $p = GameWorld::give($sacha, 'rattata', 10);
        $this->daycare->deposit($sacha, $p);
        $before = (int) $p->fresh()->experience;

        $this->daycare->steps($sacha->fresh(), 50);

        $this->assertSame($before + 50, (int) $p->fresh()->experience);
    }

    public function test_withdraw_charges_the_price(): void
    {
        $sacha = GameWorld::character(['money' => 1000]);
        $p = GameWorld::give($sacha, 'rattata', 10);
        $this->daycare->deposit($sacha, $p);

        $result = $this->daycare->withdraw($sacha->fresh(), $p->id);

        $this->assertSame(100, $result['price']);
        $this->assertSame(900, (int) $sacha->fresh()->money);
        $this->assertSame('party', $p->fresh()->location);
    }
}
