<?php

namespace Tests\Feature\Game;

use App\Battle\BattleEngine;
use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Services\BattleService;
use Database\Seeders\ItemsGen4Seeder;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 22 : objets en combat (Sac, Balls spéciales, objets tenus) */
class BattleItemsTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ItemsGen4Seeder::class);
    }

    /** Un combat contre un Pokémon sauvage ; $foeMoves : les capacités de l'adversaire (slugs) */
    private function battle($character, string $foe = 'rattata', int $level = 5, array $foeMoves = ['growl']): Battle
    {
        $battle = app(BattleService::class)->startSpawn($character, PokemonSpecies::where('slug', $foe)->first(), $level);
        $state = $battle->state;
        $factory = app(\App\Battle\BattleFactory::class);
        $state['foe']['party'][0]['moves'] = Move::whereIn('slug', $foeMoves)->get()->map(fn ($m) => $factory->moveData($m, $m->pp, $m->pp))->all();
        $battle->forceFill(['state' => $state])->save();

        return $battle->fresh();
    }

    private function hold($pokemon, string $slug): void
    {
        $pokemon->forceFill(['held_item_id' => GameWorld::item($slug)->id])->save();
    }

    private function useItem(Battle $battle, $character, string $slug, array $extra = []): array
    {
        $character->addItem(GameWorld::item($slug));

        return app(BattleService::class)->act($battle, ['type' => 'item', 'item_id' => GameWorld::item($slug)->id] + $extra);
    }

    private function player(Battle $battle): array
    {
        $s = $battle->fresh()->state;

        return $s['player']['party'][$s['player']['active']];
    }

    public function test_x_attack_raises_attack_by_two_stages(): void
    {
        $sacha = GameWorld::character();
        $battle = $this->battle($sacha, foeMoves: ['tackle']);

        $this->useItem($battle, $sacha, 'x-attack');

        $this->assertSame(2, $this->player($battle)['stages']['attack']);
        $this->assertSame(0, $sacha->quantityOf(GameWorld::item('x-attack')));
    }

    public function test_guard_spec_stops_growl(): void
    {
        $sacha = GameWorld::character();
        $battle = $this->battle($sacha);

        $this->useItem($battle, $sacha, 'guard-spec');   // l'adversaire utilise Rugissement ce tour-ci

        $this->assertSame(0, $this->player($battle)['stages']['attack']);
        $this->assertSame(4, $battle->fresh()->state['player']['mist']);
    }

    public function test_poke_doll_flees_a_wild_battle(): void
    {
        $sacha = GameWorld::character();
        $battle = $this->battle($sacha);

        $this->useItem($battle, $sacha, 'poke-doll');

        $this->assertSame('fled', $battle->fresh()->result);
    }

    public function test_ether_restores_the_chosen_move(): void
    {
        $sacha = GameWorld::character();
        $battle = $this->battle($sacha);
        $state = $battle->state;
        $state['player']['party'][0]['moves'][1]['pp'] = 0;
        $battle->forceFill(['state' => $state])->save();

        $this->useItem($battle->fresh(), $sacha, 'ether', ['target' => 0, 'move' => 1]);

        $this->assertSame(10, $this->player($battle)['moves'][1]['pp']);
    }

    public function test_choice_band_locks_the_move(): void
    {
        $sacha = GameWorld::character();
        $this->hold($sacha->party()->first(), 'choice-band');
        $battle = $this->battle($sacha, 'mewtwo', 50);   // ne tombera pas K.O.
        $service = app(BattleService::class);

        $service->act($battle, ['type' => 'move', 'slot' => 0]);

        $this->expectException(InvalidAction::class);
        $service->act($battle->fresh(), ['type' => 'move', 'slot' => 1]);
    }

    public function test_focus_sash_survives_a_ko_hit(): void
    {
        $sacha = GameWorld::character([], 'rattata', 2);
        $this->hold($sacha->party()->first(), 'focus-sash');
        $battle = $this->battle($sacha, 'mewtwo', 70, ['tackle']);

        app(BattleService::class)->act($battle, ['type' => 'move', 'slot' => 0]);

        $me = $this->player($battle);
        $this->assertSame(1, $me['hp']);
        $this->assertNull($me['item']);
    }

    public function test_life_orb_hurts_its_holder(): void
    {
        $sacha = GameWorld::character([], 'bulbasaur', 50);
        $this->hold($sacha->party()->first(), 'life-orb');
        $battle = $this->battle($sacha, 'mewtwo', 70, ['growl']);
        $max = $this->player($battle)['stats']['hp'];

        app(BattleService::class)->act($battle, ['type' => 'move', 'slot' => $this->damagingSlot($battle)]);

        $this->assertSame($max - intdiv($max, 10), $this->player($battle)['hp']);
    }

    public function test_white_herb_restores_lowered_stats(): void
    {
        $sacha = GameWorld::character();
        $this->hold($sacha->party()->first(), 'white-herb');
        $battle = $this->battle($sacha, 'mewtwo', 50, ['growl']);   // Mewtwo ne fait que Rugissement
        $growl = collect($this->player($battle)['moves'])->search(fn ($m) => $m['slug'] === 'growl');

        app(BattleService::class)->act($battle, ['type' => 'move', 'slot' => $growl]);

        $me = $this->player($battle);
        $this->assertSame(0, $me['stages']['attack']);
        $this->assertNull($me['item'], "l'Herbe Blanche est consommée");
    }

    public function test_resist_berry_halves_a_super_effective_hit(): void
    {
        $sacha = GameWorld::character([], 'bulbasaur', 30);
        $state = $this->battle($sacha, 'charmander', 30, ['ember'])->state;
        $state['foe']['party'][0]['stats']['speed'] = 999;   // Salamèche attaque en premier
        $state['player']['party'][0]['moves'] = [$state['player']['party'][0]['moves'][0]];
        $chart = app(\App\Battle\BattleFactory::class)->typeChart();

        // Le MÊME tour joué deux fois (même hasard), avec et sans Baie Chocco
        $play = function (?array $item) use ($state, $chart) {
            $state['player']['party'][0]['item'] = $item;
            $engine = new BattleEngine($state, $chart);
            $engine->act(['type' => 'move', 'slot' => 0]);
            $me = $engine->state()['player']['party'][0];

            return [$state['player']['party'][0]['hp'] - $me['hp'], $me['item']];
        };
        [$without] = $play(null);
        [$with, $item] = $play(\App\Services\HeldItemService::battleData(GameWorld::item('occa-berry')));

        $this->assertNull($item, 'la Baie est mangée');
        $this->assertGreaterThan(0, $without);
        $this->assertEqualsWithDelta($without / 2, $with, 1);
    }

    public function test_special_balls(): void
    {
        $sacha = GameWorld::character([], 'bulbasaur', 40);
        $battle = $this->battle($sacha, 'squirtle', 10);
        $engine = new BattleEngine($battle->state, []);
        $modifier = fn (string $slug, array $state = []) => (new \ReflectionMethod($engine, 'ballModifier'))->invoke(
            new BattleEngine(array_replace_recursive($battle->state, $state), []), $slug, $battle->state['foe']['party'][0], 45);

        $this->assertSame(3.5, $modifier('net-ball')[0]);                       // Carapuce est de type Eau
        $this->assertSame(3.1, round($modifier('nest-ball')[0], 2));            // (41 - 10) / 10
        $this->assertSame(8, $modifier('level-ball')[0]);                       // N. 40 contre N. 10
        $this->assertSame(5.0, (float) $modifier('quick-ball', ['turn' => 1])[0]);
        $this->assertSame(1, $modifier('quick-ball', ['turn' => 2])[0]);
        $this->assertSame(4.0, (float) $modifier('timer-ball', ['turn' => 11])[0]);
        $this->assertSame(1, $modifier('dusk-ball')[0]);
        $this->assertSame(3.0, (float) $modifier('dusk-ball', ['dark' => true])[0]);
        $this->assertSame(5.0, (float) $modifier('lure-ball', ['encounter' => 'good_rod'])[0]);
    }

    public function test_heal_ball_heals_the_caught_pokemon(): void
    {
        $sacha = GameWorld::character();
        $battle = $this->battle($sacha);
        $state = $battle->state;
        $state['foe']['party'][0]['hp'] = 1;
        $state['foe']['party'][0]['captureRate'] = 255;
        $state['foe']['party'][0]['status'] = 'sleep';
        $battle->forceFill(['state' => $state])->save();
        $sacha->addItem(GameWorld::item('heal-ball'));

        for ($i = 0; $i < 5 && $battle->fresh()->isActive(); $i++) {
            $sacha->addItem(GameWorld::item('heal-ball'));
            app(BattleService::class)->act($battle->fresh(), ['type' => 'item', 'item_id' => GameWorld::item('heal-ball')->id]);
        }

        $caught = $sacha->pokemon()->whereHas('caughtBall', fn ($q) => $q->where('slug', 'heal-ball'))->first();
        $this->assertNotNull($caught);
        $this->assertSame($caught->max_hp, $caught->current_hp);
        $this->assertSame('none', $caught->status);
    }

    private function damagingSlot(Battle $battle): int
    {
        return collect($this->player($battle)['moves'])->search(fn ($m) => $m['power'] > 0);
    }
}
