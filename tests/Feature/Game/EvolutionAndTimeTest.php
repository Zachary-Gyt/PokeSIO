<?php

namespace Tests\Feature\Game;

use App\Models\Evolution;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Models\WildEncounter;
use App\Services\EvolutionService;
use App\Services\TimeOfDay;
use Illuminate\Support\Carbon;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 23 : jour / nuit (heure de Paris) et méthodes d'évolution des générations 2 à 4 */
class EvolutionAndTimeTest extends GameTestCase
{
    private function species(string $slug): int
    {
        return (int) PokemonSpecies::where('slug', $slug)->value('id');
    }

    /** Une évolution de test : Rattata -> $to avec ces conditions */
    private function evolution(string $to, array $conditions, string $from = 'rattata'): Evolution
    {
        Evolution::where('from_species_id', $this->species($from))->delete();

        return Evolution::forceCreate(['from_species_id' => $this->species($from), 'to_species_id' => $this->species($to)] + $conditions + ['trigger' => 'level']);
    }

    private function candidate($pokemon, $item = null)
    {
        return app(EvolutionService::class)->candidate($pokemon->fresh(), $item);
    }

    public function test_the_game_uses_the_paris_clock(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 19:30:00', 'UTC'));   // 21 h 30 à Paris
        $this->assertSame('night', TimeOfDay::period());
        $this->travelTo(Carbon::parse('2026-10-21 03:30:00', 'UTC'));   // 5 h 30 à Paris
        $this->assertSame('morning', TimeOfDay::period());
        $this->assertSame('day', TimeOfDay::dayOrNight(), 'le matin compte comme le jour pour les évolutions');
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00', 'UTC'));
        $this->assertSame('day', TimeOfDay::period());
    }

    public function test_an_admin_can_force_the_time(): void
    {
        TimeOfDay::force('night');
        $this->assertSame('night', TimeOfDay::period());
        $this->assertTrue(TimeOfDay::clock()['forced']);
        TimeOfDay::force(null);
        $this->assertFalse(TimeOfDay::clock()['forced']);
    }

    public function test_night_only_encounters(): void
    {
        foreach (['pikachu' => null, 'magnemite' => 'night'] as $slug => $time) {
            WildEncounter::forceCreate(['map_key' => 'test_route', 'zone' => 'default', 'method' => 'grass', 'pokemon_species_id' => $this->species($slug),
                'min_level' => 3, 'max_level' => 3, 'weight' => 50, 'time_of_day' => $time]);
        }
        TimeOfDay::force('day');
        $this->assertSame(['pikachu'], WildEncounter::at('test_route')->with('species')->get()->pluck('species.slug')->all());
        TimeOfDay::force('night');
        $this->assertCount(2, WildEncounter::at('test_route')->get());
    }

    public function test_held_item_at_night_and_the_item_is_used(): void
    {
        $this->evolution('pikachu', ['held_item_id' => GameWorld::item('charcoal')->id, 'time_of_day' => 'night']);
        $sacha = GameWorld::character();
        $rattata = GameWorld::give($sacha, 'rattata', 20);
        $rattata->forceFill(['held_item_id' => GameWorld::item('charcoal')->id])->save();

        TimeOfDay::force('day');
        $this->assertNull($this->candidate($rattata));
        TimeOfDay::force('night');
        $evolution = $this->candidate($rattata);
        $this->assertNotNull($evolution);

        app(EvolutionService::class)->evolve($rattata->fresh(), $evolution);
        $this->assertSame('pikachu', $rattata->fresh()->species->slug);
        $this->assertNull($rattata->fresh()->held_item_id);
    }

    public function test_known_move(): void
    {
        $this->evolution('ponyta', ['known_move_id' => Move::where('slug', 'cut')->value('id')]);
        $sacha = GameWorld::character();
        $rattata = GameWorld::give($sacha, 'rattata', 20);
        $this->assertNull($this->candidate($rattata));

        $rattata->moves()->where('slot', 1)->update(['move_id' => Move::where('slug', 'cut')->value('id')]);
        $this->assertNotNull($this->candidate($rattata));
    }

    public function test_gender_and_evolution_stone(): void
    {
        $this->evolution('ponyta', ['trigger' => 'item', 'item_id' => GameWorld::item('potion')->id, 'gender' => 'male']);
        $sacha = GameWorld::character();
        $male = GameWorld::give($sacha, 'rattata', 20, ['gender' => 'male']);
        $female = GameWorld::give($sacha, 'rattata', 20, ['gender' => 'female']);

        $this->assertNotNull($this->candidate($male, GameWorld::item('potion')));
        $this->assertNull($this->candidate($female, GameWorld::item('potion')));
    }

    public function test_another_pokemon_in_the_party(): void
    {
        $this->evolution('ponyta', ['party_species_id' => $this->species('magnemite')]);
        $sacha = GameWorld::character();
        $rattata = GameWorld::give($sacha, 'rattata', 20);
        $this->assertNull($this->candidate($rattata));

        GameWorld::give($sacha, 'magnemite', 5);
        $this->assertNotNull($this->candidate($rattata));
    }

    public function test_special_place(): void
    {
        GameWorld::map('test_foret', [], ['evolution_area' => 'moss']);
        $this->evolution('ponyta', ['location' => 'moss']);
        $sacha = GameWorld::character();
        $rattata = GameWorld::give($sacha, 'rattata', 20);
        $this->assertNull($this->candidate($rattata));

        $sacha->forceFill(['current_map' => 'test_foret'])->save();
        $this->assertNotNull($this->candidate($rattata));
    }

    public function test_tyrogue_style_attack_versus_defense(): void
    {
        Evolution::where('from_species_id', $this->species('rattata'))->delete();
        foreach (['ponyta' => 1, 'pikachu' => -1, 'magnemite' => 0] as $to => $rel) {
            Evolution::forceCreate(['from_species_id' => $this->species('rattata'), 'to_species_id' => $this->species($to), 'trigger' => 'level', 'min_level' => 20, 'relative_stats' => $rel]);
        }
        $sacha = GameWorld::character();
        $rattata = GameWorld::give($sacha, 'rattata', 25);
        $expected = match ($rattata->stat('attack') <=> $rattata->stat('defense')) { 1 => 'ponyta', -1 => 'pikachu', 0 => 'magnemite' };

        $this->assertSame($expected, $this->candidate($rattata)->to->slug);
    }

    public function test_wurmple_style_choice_is_always_the_same_for_a_pokemon(): void
    {
        Evolution::where('from_species_id', $this->species('rattata'))->delete();
        foreach (['ponyta', 'pikachu'] as $to) {
            Evolution::forceCreate(['from_species_id' => $this->species('rattata'), 'to_species_id' => $this->species($to), 'trigger' => 'level', 'min_level' => 7]);
        }
        $sacha = GameWorld::character();
        $rattata = GameWorld::give($sacha, 'rattata', 10);
        $first = $this->candidate($rattata)->to_species_id;

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $this->candidate($rattata)->to_species_id);
        }
    }

    public function test_shedinja_style_extra_pokemon(): void
    {
        Evolution::forceCreate(['from_species_id' => $this->species('bulbasaur'), 'to_species_id' => $this->species('charmander'), 'trigger' => 'shed']);
        $sacha = GameWorld::character([], 'bulbasaur', 16);
        $sacha->addItem(GameWorld::item('poke-ball'));
        $bulbi = $sacha->party()->first();

        $result = app(EvolutionService::class)->evolve($bulbi, $this->candidate($bulbi));

        $this->assertSame('ivysaur', $bulbi->fresh()->species->slug);
        $this->assertCount(1, $result['messages']);
        $shed = $sacha->party()->whereHas('species', fn ($q) => $q->where('slug', 'charmander'))->first();
        $this->assertNotNull($shed);
        $this->assertSame(16, $shed->level);
        $this->assertSame(0, $sacha->quantityOf(GameWorld::item('poke-ball')));
    }

    public function test_level_trigger_without_any_condition_never_happens(): void
    {
        $this->evolution('ponyta', []);
        $sacha = GameWorld::character();
        $this->assertNull($this->candidate(GameWorld::give($sacha, 'rattata', 50)));
    }
}
