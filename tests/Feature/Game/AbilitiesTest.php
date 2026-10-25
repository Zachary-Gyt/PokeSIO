<?php

namespace Tests\Feature\Game;

use App\Battle\BattleEngine;
use App\Battle\BattleFactory;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Services\BattleService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 23 : les talents et la météo en combat (joués directement avec le moteur) */
class AbilitiesTest extends GameTestCase
{
    /** Un état de combat : Bulbizarre du joueur contre un Pokémon sauvage, avec les talents et capacités voulus */
    private function state(array $player = [], array $foe = [], string $foeSpecies = 'rattata', int $foeLevel = 20): array
    {
        $sacha = GameWorld::character([], 'bulbasaur', 20);
        $state = app(BattleService::class)->startSpawn($sacha, PokemonSpecies::where('slug', $foeSpecies)->first(), $foeLevel)->state;
        $factory = app(BattleFactory::class);
        foreach (['player' => $player, 'foe' => $foe] as $side => $changes) {
            if (isset($changes['moves'])) {
                $changes['moves'] = Move::whereIn('slug', $changes['moves'])->get()->map(fn ($m) => $factory->moveData($m, $m->pp, $m->pp))->all();
            }
            $state[$side]['party'][0] = array_replace($state[$side]['party'][0], $changes);
        }
        $state['rng'] = 777;

        return $state;
    }

    /** Joue un tour ; renvoie [nouvel état, textes des messages] */
    private function play(array $state, array $action = ['type' => 'move', 'slot' => 0]): array
    {
        $engine = new BattleEngine($state, app(BattleFactory::class)->typeChart());
        $events = $engine->act($action);

        return [$engine->state(), collect($events)->where('type', 'message')->pluck('text')->all()];
    }

    private function start(array $state): array
    {
        $engine = new BattleEngine($state, app(BattleFactory::class)->typeChart());
        $events = $engine->start();

        return [$engine->state(), collect($events)->where('type', 'message')->pluck('text')->all()];
    }

    public function test_intimidate_lowers_the_foe_attack_at_the_start(): void
    {
        [$state, $texts] = $this->start($this->state(['ability' => 'intimidate', 'abilityName' => 'Intimidation']));

        $this->assertSame(-1, $state['foe']['party'][0]['stages']['attack']);
        $this->assertContains('Intimidation de Bulbizarre !', $texts);
    }

    public function test_clear_body_blocks_intimidate(): void
    {
        [$state] = $this->start($this->state(['ability' => 'intimidate', 'abilityName' => 'Intimidation'], ['ability' => 'clear-body', 'abilityName' => 'Corps Sain']));

        $this->assertSame(0, $state['foe']['party'][0]['stages']['attack']);
    }

    public function test_levitate_avoids_ground_moves(): void
    {
        $state = $this->state(['moves' => ['tackle']], ['ability' => 'levitate', 'abilityName' => 'Lévitation', 'moves' => ['growl']]);
        $ground = app(BattleFactory::class)->moveData(Move::where('slug', 'tackle')->first(), 35, 35);
        $ground['type'] = 'ground';
        $state['player']['party'][0]['moves'] = [$ground];
        $hp = $state['foe']['party'][0]['hp'];

        [$after, $texts] = $this->play($state);

        $this->assertSame($hp, $after['foe']['party'][0]['hp']);
        $this->assertContains('Lévitation du Rattata sauvage !', $texts);
    }

    public function test_volt_absorb_heals(): void
    {
        $state = $this->state(['moves' => ['thunder-shock']], ['ability' => 'volt-absorb', 'abilityName' => 'Absorb Volt', 'moves' => ['growl']]);
        $state['foe']['party'][0]['hp'] = 10;

        [$after] = $this->play($state);

        $foe = $after['foe']['party'][0];
        $this->assertSame(min($foe['stats']['hp'], 10 + intdiv($foe['stats']['hp'], 4)), $foe['hp']);
    }

    public function test_wonder_guard_only_takes_super_effective_hits(): void
    {
        $state = $this->state(['moves' => ['tackle']], ['ability' => 'wonder-guard', 'abilityName' => 'Garde Mystik', 'moves' => ['growl']]);
        $hp = $state['foe']['party'][0]['hp'];

        [$after] = $this->play($state);

        $this->assertSame($hp, $after['foe']['party'][0]['hp']);
    }

    public function test_sturdy_survives_a_ko_hit_at_full_hp(): void
    {
        $state = $this->state(['moves' => ['tackle'], 'level' => 100], ['ability' => 'sturdy', 'abilityName' => 'Fermeté', 'moves' => ['growl']], 'rattata', 2);
        $state['player']['party'][0]['stats']['attack'] = 999;

        [$after] = $this->play($state);

        $this->assertSame(1, $after['foe']['party'][0]['hp']);
    }

    public function test_huge_power_doubles_damage(): void
    {
        $base = $this->state(['moves' => ['tackle']], ['moves' => ['growl']], 'mewtwo', 50);
        [$normal] = $this->play($base);
        $boosted = $base;
        $boosted['player']['party'][0]['ability'] = 'huge-power';
        [$strong] = $this->play($boosted);

        $dmg = fn ($s) => $base['foe']['party'][0]['hp'] - $s['foe']['party'][0]['hp'];
        $this->assertEqualsWithDelta($dmg($normal) * 2, $dmg($strong), 3);
    }

    public function test_rain_dance_starts_rain_and_swift_swim_doubles_speed(): void
    {
        $state = $this->state(['moves' => ['rain-dance'], 'ability' => 'swift-swim', 'abilityName' => 'Glissade'], ['moves' => ['growl']]);
        $state['player']['party'][0]['target'] = null;

        [$after, $texts] = $this->play($state);

        $this->assertContains('Il commence à pleuvoir !', $texts);
        $this->assertSame(['type' => 'rain', 'turns' => 4], $after['weather']);   // 5 tours, dont un de passé

        $speed = (new \ReflectionMethod(BattleEngine::class, 'effectiveSpeed'));
        $engine = new BattleEngine($after, []);
        $dry = new BattleEngine(array_diff_key($after, ['weather' => 1]), []);
        $this->assertSame($speed->invoke($dry, 'player') * 2, $speed->invoke($engine, 'player'));
    }

    public function test_weather_from_the_map_and_sandstorm_damage(): void
    {
        $state = $this->state(['moves' => ['growl']], ['moves' => ['growl']]);
        $state['weather'] = ['type' => 'sand', 'turns' => null];
        $maxP = $state['player']['party'][0]['stats']['hp'];
        $state['player']['party'][0]['hp'] = $maxP;

        [$after, $texts] = $this->play($state);

        $this->assertContains('La tempête de sable fait rage.', $texts);
        $this->assertSame($maxP - intdiv($maxP, 16), $after['player']['party'][0]['hp']);   // Bulbizarre n'est ni Roche, ni Sol, ni Acier
    }

    public function test_sun_boosts_fire_moves(): void
    {
        $base = $this->state(['moves' => ['ember']], ['moves' => ['growl']], 'mewtwo', 50);
        [$normal] = $this->play($base);
        $sunny = $base + ['weather' => ['type' => 'sun', 'turns' => null]];
        [$sun] = $this->play($sunny);

        $dmg = fn ($s) => $base['foe']['party'][0]['hp'] - $s['foe']['party'][0]['hp'];
        $this->assertEqualsWithDelta($dmg($normal) * 1.5, $dmg($sun), 2);
    }

    public function test_static_can_paralyze_on_contact(): void
    {
        $paralyzed = 0;
        for ($i = 0; $i < 30; $i++) {
            $state = $this->state(['moves' => ['tackle']], ['ability' => 'static', 'abilityName' => 'Statik', 'moves' => ['growl']], 'mewtwo', 60);
            $state['rng'] = 1000 + $i;
            [$after] = $this->play($state);
            $paralyzed += $after['player']['party'][0]['status'] === 'paralysis' ? 1 : 0;
        }

        $this->assertGreaterThan(2, $paralyzed);
        $this->assertLessThan(20, $paralyzed);
    }

    public function test_limber_prevents_paralysis(): void
    {
        $state = $this->state(['moves' => ['tackle'], 'ability' => 'limber', 'abilityName' => 'Échauffement'],
            ['ability' => 'static', 'abilityName' => 'Statik', 'moves' => ['growl']], 'mewtwo', 60);
        for ($i = 0; $i < 15; $i++) {
            $state['rng'] = 2000 + $i;
            [$after] = $this->play($state);
            $this->assertSame('none', $after['player']['party'][0]['status']);
        }
    }

    public function test_natural_cure_heals_when_switching_out(): void
    {
        $sacha = GameWorld::character([], 'bulbasaur', 20);
        GameWorld::give($sacha, 'pikachu', 20);
        $state = app(BattleService::class)->startSpawn($sacha, PokemonSpecies::where('slug', 'mewtwo')->first(), 5)->state;
        $state['player']['party'][0]['ability'] = 'natural-cure';
        $state['player']['party'][0]['status'] = 'poison';

        [$after] = $this->play($state, ['type' => 'switch', 'party' => 1]);

        $this->assertSame('none', $after['player']['party'][0]['status']);
    }

    public function test_run_away_always_flees(): void
    {
        $state = $this->state(['ability' => 'run-away', 'abilityName' => 'Fuite'], ['moves' => ['growl']], 'mewtwo', 100);
        $state['player']['party'][0]['stats']['speed'] = 1;

        [$after] = $this->play($state, ['type' => 'run']);

        $this->assertSame('fled', $after['result']);
    }
}
