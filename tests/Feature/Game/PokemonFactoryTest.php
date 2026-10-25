<?php

namespace Tests\Feature\Game;

use App\Models\Nature;
use App\Models\PokemonSpecies;
use App\Services\PokemonFactory;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** La création des Pokémon : stats, attaques de départ, PV pleins, origine */
class PokemonFactoryTest extends GameTestCase
{
    private function bulbasaur(int $level, array $options = [])
    {
        return app(PokemonFactory::class)->make(PokemonSpecies::where('slug', 'bulbasaur')->first(), $level, $options);
    }

    public function test_stats_follow_the_official_formula(): void
    {
        // Bulbizarre N. 50, IV 31 partout, EV 0, nature Hardi (neutre) : 120 PV, 69 Att (calculs des jeux)
        $ivs = array_fill_keys(PokemonSpecies::STATS, 31);
        $p = $this->bulbasaur(50, ['ivs' => $ivs, 'nature' => Nature::where('slug', 'hardy')->first()]);

        $this->assertSame(120, $p->max_hp);
        $this->assertSame(69, $p->stat('attack'));
        $this->assertSame($p->max_hp, $p->current_hp);
    }

    public function test_a_nature_raises_one_stat_and_lowers_another(): void
    {
        $ivs = array_fill_keys(PokemonSpecies::STATS, 31);
        $neutral = $this->bulbasaur(50, ['ivs' => $ivs, 'nature' => Nature::where('slug', 'hardy')->first()]);
        $adamant = $this->bulbasaur(50, ['ivs' => $ivs, 'nature' => Nature::where('slug', 'adamant')->first()]);

        $this->assertSame((int) floor($neutral->stat('attack') * 1.1), $adamant->stat('attack'));
        $this->assertSame((int) floor($neutral->stat('sp_attack') * 0.9), $adamant->stat('sp_attack'));
    }

    public function test_level_is_clamped_between_1_and_100(): void
    {
        $this->assertSame(100, $this->bulbasaur(250)->level);
        $this->assertSame(1, $this->bulbasaur(-3)->level);
    }

    public function test_starting_moves_are_the_last_four_learned(): void
    {
        $sacha = GameWorld::character();
        $p = GameWorld::give($sacha, 'bulbasaur', 20);

        $slugs = $p->moves()->with('move')->orderBy('slot')->get()->pluck('move.slug')->all();
        // Charge (1), Rugissement (3), Vampigraine (7), Fouet Lianes (9), Tranch'Herbe (19) : les 4 dernières
        $this->assertSame(['growl', 'leech-seed', 'vine-whip', 'razor-leaf'], $slugs);
    }

    public function test_given_pokemon_belongs_to_its_trainer_and_is_registered_in_the_pokedex(): void
    {
        $sacha = GameWorld::character();
        $p = GameWorld::give($sacha, 'pikachu', 5);

        $this->assertSame((int) $sacha->trainer_id, (int) $p->original_trainer_id);
        $this->assertSame(5, $p->met_level);
        $this->assertTrue($sacha->pokedexEntries()->where('pokemon_species_id', $p->pokemon_species_id)->where('caught', true)->exists());
    }

    public function test_genderless_species_have_no_gender(): void
    {
        $magneti = app(PokemonFactory::class)->make(PokemonSpecies::where('slug', 'magnemite')->first(), 10);

        $this->assertContains($magneti->gender, [null, 'genderless', 'none']);
    }
}
