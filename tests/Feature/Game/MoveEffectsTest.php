<?php

namespace Tests\Feature\Game;

use App\Battle\BattleEngine;
use App\Battle\BattleFactory;
use App\Battle\InvalidAction;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Services\BattleService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Les capacités au fonctionnement unique (app/Battle/Concerns/MoveEffects.php), jouées avec le moteur */
class MoveEffectsTest extends GameTestCase
{
    /** Une capacité "fabriquée" à partir de Charge (les données de test n'ont que quelques capacités) */
    private function move(string $slug, array $changes = []): array
    {
        $tackle = app(BattleFactory::class)->moveData(Move::where('slug', 'tackle')->first(), 20, 20);

        return array_replace($tackle, ['slug' => $slug, 'name' => ucfirst($slug)], $changes);
    }

    private function statusMove(string $slug, array $changes = []): array
    {
        return $this->move($slug, ['category' => 'status', 'power' => null, 'accuracy' => null, 'effect' => 'unique', 'target' => 'selected-pokemon'] + $changes);
    }

    /** Bulbizarre (N. 20) du joueur contre un Rattata sauvage (N. 20), avec les capacités voulues */
    private function state(array $playerMoves, array $foeMoves, array $player = [], array $foe = []): array
    {
        $sacha = GameWorld::character([], 'bulbasaur', 20);
        $state = app(BattleService::class)->startSpawn($sacha, PokemonSpecies::where('slug', 'rattata')->first(), 20)->state;
        $state['player']['party'][0] = array_replace($state['player']['party'][0], ['moves' => $playerMoves, 'gender' => 'male'], $player);
        $state['foe']['party'][0] = array_replace($state['foe']['party'][0], ['moves' => $foeMoves, 'gender' => 'female'], $foe);
        $state['rng'] = 777;

        return $state;
    }

    /** Ajoute un 2e Pokémon à l'équipe du joueur */
    private function withSecond(array $state): array
    {
        $second = $state['player']['party'][0];
        $second['id'] = null;
        $second['name'] = 'Second';
        $state['player']['party'][1] = $second;

        return $state;
    }

    private function play(array $state, array $action = ['type' => 'move', 'slot' => 0]): array
    {
        $engine = new BattleEngine($state, app(BattleFactory::class)->typeChart());
        $events = $engine->act($action);

        return [$engine->state(), collect($events)->where('type', 'message')->pluck('text')->all()];
    }

    public function test_false_swipe_always_leaves_one_hp(): void
    {
        $state = $this->state([$this->move('false-swipe', ['name' => 'Faux-Chage', 'power' => 40])], [$this->statusMove('splash')]);
        $state['player']['party'][0]['stats']['attack'] = 999;

        [$after] = $this->play($state);
        $this->assertSame(1, $after['foe']['party'][0]['hp']);

        // Encore une fois : la cible reste à 1 PV
        [$after] = $this->play($after);
        $this->assertSame(1, $after['foe']['party'][0]['hp']);
    }

    public function test_protect_blocks_the_attack(): void
    {
        $state = $this->state([$this->statusMove('protect', ['priority' => 4, 'target' => 'user'])], [$this->move('tackle')]);
        $hp = $state['player']['party'][0]['hp'];

        [$after, $texts] = $this->play($state);

        $this->assertSame($hp, $after['player']['party'][0]['hp']);
        $this->assertContains('Bulbizarre se protège !', $texts);
    }

    public function test_endure_leaves_one_hp(): void
    {
        $state = $this->state([$this->statusMove('endure', ['priority' => 4, 'target' => 'user'])], [$this->move('tackle')]);
        $state['foe']['party'][0]['stats']['attack'] = 999;

        [$after] = $this->play($state);

        $this->assertSame(1, $after['player']['party'][0]['hp']);
    }

    public function test_substitute_takes_the_hits(): void
    {
        $state = $this->state([$this->statusMove('substitute', ['target' => 'user', 'priority' => 1])], [$this->move('tackle')]);
        $max = $state['player']['party'][0]['stats']['hp'];
        $state['player']['party'][0]['hp'] = $max;

        [$after, $texts] = $this->play($state);

        // Le clone coûte 1/4 des PV ; le coup de Charge est encaissé par le clone
        $this->assertSame($max - intdiv($max, 4), $after['player']['party'][0]['hp']);
        $this->assertContains('Le clone prend les dégâts à la place de Bulbizarre !', $texts);
    }

    public function test_counter_returns_double_the_physical_damage(): void
    {
        $state = $this->state([$this->move('counter', ['power' => null, 'priority' => -5, 'category' => 'physical', 'type' => 'fighting'])], [$this->move('tackle')]);
        $state['player']['party'][0]['stats']['hp'] = $state['player']['party'][0]['hp'] = 500;
        $state['foe']['party'][0]['stats']['hp'] = $state['foe']['party'][0]['hp'] = 500;

        [$after] = $this->play($state);

        $taken = 500 - $after['player']['party'][0]['hp'];
        $this->assertGreaterThan(0, $taken);
        $this->assertSame(500 - 2 * $taken, $after['foe']['party'][0]['hp']);
    }

    public function test_hidden_power_type_and_power_come_from_the_ivs(): void
    {
        $ivs = ['hp' => 31, 'attack' => 31, 'defense' => 31, 'speed' => 31, 'sp_attack' => 31, 'sp_defense' => 31];
        $state = $this->state([$this->move('hidden-power', ['category' => 'special', 'power' => 60])], [$this->statusMove('splash')], ['ivs' => $ivs]);
        $engine = new BattleEngine($state, app(BattleFactory::class)->typeChart());

        $method = new \ReflectionMethod($engine, 'hiddenPower');
        $this->assertSame(['dark', 70], $method->invoke($engine, $state['player']['party'][0]));
    }

    public function test_taunt_forbids_status_moves(): void
    {
        $state = $this->state([$this->statusMove('growl'), $this->move('tackle')], [$this->statusMove('splash')]);
        $state['player']['party'][0]['volatile']['taunt'] = 3;

        $this->expectException(InvalidAction::class);
        $this->play($state);
    }

    public function test_spikes_hurt_the_pokemon_that_comes_in(): void
    {
        $state = $this->withSecond($this->state([$this->move('tackle')], [$this->statusMove('spikes', ['target' => 'opponents-field'])]));

        [$after] = $this->play($state);
        $this->assertSame(1, $after['player']['hazards']['spikes']);

        [$after, $texts] = $this->play($after, ['type' => 'switch', 'party' => 1]);
        $second = $after['player']['party'][1];
        $this->assertContains('Second est blessé par les picots !', $texts);
        $this->assertLessThan($second['stats']['hp'], $second['hp']);
    }

    public function test_future_sight_hits_two_turns_later(): void
    {
        $state = $this->state([$this->move('future-sight', ['category' => 'special', 'power' => 120, 'type' => 'psychic', 'effect' => 'unique']), $this->statusMove('splash')], [$this->statusMove('splash')]);
        $hp = $state['foe']['party'][0]['hp'];

        [$s1] = $this->play($state);
        $this->assertSame($hp, $s1['foe']['party'][0]['hp']);
        [$s2] = $this->play($s1, ['type' => 'move', 'slot' => 1]);
        $this->assertSame($hp, $s2['foe']['party'][0]['hp']);
        [$s3, $texts] = $this->play($s2, ['type' => 'move', 'slot' => 1]);
        $this->assertLessThan($hp, $s3['foe']['party'][0]['hp']);
        $this->assertContains("Le Rattata sauvage subit l'attaque Future-sight !", $texts);
    }

    public function test_u_turn_lets_the_player_choose_a_replacement(): void
    {
        $state = $this->withSecond($this->state([$this->move('u-turn', ['type' => 'bug'])], [$this->statusMove('splash')]));

        [$after] = $this->play($state);
        $this->assertSame('switch', $after['phase']);

        [$after, $texts] = $this->play($after, ['type' => 'switch', 'party' => 1]);
        $this->assertSame(1, $after['player']['active']);
        $this->assertSame('command', $after['phase']);
        $this->assertContains('Bulbizarre, reviens !', $texts);
    }

    public function test_thief_keeps_a_wild_pokemon_item_and_trick_is_undone_after_battle(): void
    {
        $berry = ['id' => 5, 'slug' => 'oran-berry', 'name' => 'Baie Oran', 'effect' => null];
        $state = $this->state([$this->move('thief', ['type' => 'dark']), $this->statusMove('splash')], [$this->statusMove('splash')], ['item' => null], ['item' => $berry]);

        [$after] = $this->play($state);
        $this->assertSame('oran-berry', $after['player']['party'][0]['item']['slug']);
        $this->assertSame(5, $after['player']['party'][0]['stolenItemId']);
        $this->assertNull($after['foe']['party'][0]['item']);

        // Passe-Passe : les objets sont échangés pendant le combat, puis rendus à la fin
        $band = ['id' => 7, 'slug' => 'focus-band', 'name' => 'Bandeau', 'effect' => null];
        $state = $this->state([$this->statusMove('trick')], [$this->statusMove('splash')], ['item' => $band], ['item' => $berry]);
        $state['player']['party'][0]['stats']['speed'] = 999;   // la fuite réussit à coup sûr
        [$after] = $this->play($state);
        $this->assertSame('oran-berry', $after['player']['party'][0]['item']['slug']);
        [$ended] = $this->play($after, ['type' => 'run']);
        $this->assertSame('fled', $ended['result']);
        $this->assertSame('focus-band', $ended['player']['party'][0]['item']['slug']);
        $this->assertEmpty($ended['player']['party'][0]['itemConsumed'] ?? false);
    }

    public function test_perish_song_knocks_out_after_three_turns(): void
    {
        $state = $this->state([$this->statusMove('perish-song', ['target' => 'all-pokemon']), $this->statusMove('splash')], [$this->statusMove('splash')]);

        [$s] = $this->play($state);
        foreach (range(1, 3) as $turn) {
            [$s] = $this->play($s, ['type' => 'move', 'slot' => 1]);
        }

        $this->assertSame(0, $s['foe']['party'][0]['hp']);
        $this->assertSame(0, $s['player']['party'][0]['hp']);
    }

    public function test_flatter_raises_special_attack_and_confuses(): void
    {
        $state = $this->state([$this->statusMove('flatter')], [$this->statusMove('splash')]);

        [$after] = $this->play($state);

        $foe = $after['foe']['party'][0];
        $this->assertSame(1, $foe['stages']['sp_attack']);
        $this->assertSame(0, $foe['stages']['attack']);
        $this->assertNotEmpty($foe['volatile']['confusion']);
    }

    public function test_pay_day_scatters_coins(): void
    {
        $state = $this->state([$this->move('pay-day', ['name' => 'Jackpot']), $this->statusMove('splash')], [$this->statusMove('splash')]);

        [$after] = $this->play($state);
        $this->assertSame(100, $after['payDay']);   // 5 x niveau 20

        [, $texts] = $this->play($after, ['type' => 'run']);
        $this->assertContains('Vous ramassez 100 ₽ !', $texts);
    }
}
