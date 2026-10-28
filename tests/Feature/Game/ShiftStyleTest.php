<?php

namespace Tests\Feature\Game;

use App\Battle\BattleEngine;
use App\Battle\BattleFactory;
use App\Models\Battle;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Services\BattleService;
use App\Services\PvpService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Style « Choix » : changer de Pokémon quand le dresseur adverse va envoyer le suivant */
class ShiftStyleTest extends GameTestCase
{
    private function trainerBattle(bool $twoPokemon = true): array
    {
        $sacha = GameWorld::character([], 'bulbasaur', 30);
        if ($twoPokemon) {
            GameWorld::give($sacha, 'pikachu', 30);
        }
        $trainer = Trainer::create(['slug' => 'leo', 'name' => 'Léo', 'trainer_class' => 'Gamin', 'reward_money' => 100, 'sight_range' => 3, 'ai_level' => 1]);
        foreach (['rattata', 'pikachu'] as $slot => $species) {
            $trainer->pokemon()->create(['version' => 0, 'slot' => $slot + 1, 'pokemon_species_id' => PokemonSpecies::where('slug', $species)->value('id'), 'level' => 5]);
        }
        $state = app(BattleService::class)->startTrainer($sacha, $trainer)->state;
        $state['player']['party'][0]['stats']['attack'] = 999;   // une attaque physique met K.O. à coup sûr
        $state['player']['party'][0]['stats']['speed'] = 999;
        $state['rng'] = 4;

        return $state;
    }

    private function play(array $state, array $action): array
    {
        $engine = new BattleEngine($state, app(BattleFactory::class)->typeChart());
        $events = $engine->act($action);

        return [$engine->state(), collect($events)->where('type', 'message')->pluck('text')->all()];
    }

    private function tackle(array $state): int
    {
        return collect($state['player']['party'][0]['moves'])->search(fn ($m) => $m['category'] === 'physical');   // Fouet Lianes
    }

    public function test_the_player_may_switch_before_the_next_foe_comes_out(): void
    {
        $state = $this->trainerBattle();
        [$state, $texts] = $this->play($state, ['type' => 'move', 'slot' => $this->tackle($state)]);

        $this->assertSame('shift', $state['phase']);
        $this->assertSame('Gamin Léo va envoyer Pikachu.', end($texts));
        $this->assertSame(0, $state['foe']['active']);   // pas encore envoyé

        [$state, $texts] = $this->play($state, ['type' => 'shift', 'party' => 1]);
        $this->assertSame('command', $state['phase']);
        $this->assertSame(1, $state['player']['active']);
        $this->assertSame(1, $state['foe']['active']);
        $this->assertContains('Bulbizarre, reviens !', $texts);
    }

    public function test_keeping_the_same_pokemon(): void
    {
        $state = $this->trainerBattle();
        [$state] = $this->play($state, ['type' => 'move', 'slot' => $this->tackle($state)]);
        [$state, $texts] = $this->play($state, ['type' => 'shift', 'party' => null]);

        $this->assertSame([0, 1, 'command'], [$state['player']['active'], $state['foe']['active'], $state['phase']]);
        $this->assertNotContains('Gamin Léo va envoyer Pikachu.', $texts);   // déjà dit
    }

    public function test_no_question_with_a_single_pokemon(): void
    {
        $state = $this->trainerBattle(twoPokemon: false);
        [$state, $texts] = $this->play($state, ['type' => 'move', 'slot' => $this->tackle($state)]);

        $this->assertSame('command', $state['phase']);
        $this->assertSame(1, $state['foe']['active']);
        $this->assertContains('Gamin Léo va envoyer Pikachu.', $texts);
    }

    public function test_pvp_the_other_player_may_switch_too(): void
    {
        $a = GameWorld::character([], 'bulbasaur', 30);
        GameWorld::give($a, 'pikachu', 30);
        $b = GameWorld::character([], 'rattata', 5);
        GameWorld::give($b, 'pikachu', 5);
        $battle = app(PvpService::class)->start($a, $b);
        $state = $battle->state;
        $state['foe']['party'][0]['hp'] = 0;   // le Rattata de B est K.O.
        $engine = new BattleEngine($state, app(BattleFactory::class)->typeChart());
        (new \ReflectionMethod($engine, 'handleFaints'))->invoke($engine);
        $state = $engine->state();

        $this->assertSame(['player' => 'optional', 'foe' => true], $state['needSwitch']);
        $battle->forceFill(['state' => $state])->save();

        $pvp = app(PvpService::class);
        $pvp->act(Battle::find($battle->id), $b, ['type' => 'switch', 'party' => 1]);
        $this->assertSame('switch', Battle::find($battle->id)->state['phase']);   // A n'a pas encore répondu
        $pvp->act(Battle::find($battle->id), $a, ['type' => 'switch', 'party' => 1]);

        $state = Battle::find($battle->id)->state;
        $this->assertSame([1, 1, 'command'], [$state['player']['active'], $state['foe']['active'], $state['phase']]);
    }
}
