<?php

namespace Tests\Feature\Game;

use App\Models\Badge;
use App\Models\Character;
use App\Models\HallOfFameEntry;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Services\LeagueService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 27 : la Ligue Pokémon (dans l'ordre, une défaite fait tout recommencer) et le Panthéon */
class LeagueTest extends GameTestCase
{
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['league.members' => ['e1', 'e2', 'maitre'], 'league.maps' => ['test_ligue']]);
        foreach (['e1', 'e2', 'maitre'] as $slug) {
            $t = Trainer::create(['slug' => $slug, 'name' => ucfirst($slug), 'trainer_class' => 'Conseil 4', 'reward_money' => 1000, 'sight_range' => 0, 'ai_level' => 1]);
            $t->pokemon()->create(['version' => 0, 'slot' => 1, 'pokemon_species_id' => PokemonSpecies::where('slug', 'rattata')->value('id'), 'level' => 50]);
            $t->pokemon()->create(['version' => 1, 'slot' => 1, 'pokemon_species_id' => PokemonSpecies::where('slug', 'pikachu')->value('id'), 'level' => 60]);
            $this->members[$slug] = $t;
        }
    }

    private function champion(): Character
    {
        $sacha = GameWorld::character([], 'mewtwo', 70);
        foreach (range(1, 8) as $o) {
            $sacha->badges()->attach(Badge::firstOrCreate(['order' => $o], ['name' => "B{$o}"])->id, ['obtained_at' => now()]);
        }

        return $sacha->fresh();
    }

    public function test_members_are_fought_in_order_with_all_the_badges(): void
    {
        $league = app(LeagueService::class);
        $rookie = GameWorld::character();
        $this->assertFalse($this->members['e1']->canChallenge($rookie));   // pas les 8 badges

        $sacha = $this->champion();
        $this->assertTrue($this->members['e1']->canChallenge($sacha));
        $this->assertFalse($this->members['e2']->canChallenge($sacha));

        $this->assertSame(1, $league->afterBattle($sacha, $this->members['e1'], 'win')['progress']);
        $this->assertFalse($this->members['e1']->canChallenge($sacha->fresh()));   // déjà battu pendant cette tentative
        $this->assertTrue($this->members['e2']->canChallenge($sacha->fresh()));

        // Une défaite : tout recommence
        $this->assertSame(0, $league->afterBattle($sacha->fresh(), $this->members['e2'], 'loss')['progress']);
        $this->assertTrue($this->members['e1']->canChallenge($sacha->fresh()));

        // Ressortir de la Ligue aussi
        $league->afterBattle($sacha->fresh(), $this->members['e1'], 'win');
        $league->rememberMap($sacha->fresh(), 'test_ligue');
        $this->assertSame(1, $league->progress($sacha->fresh()));
        $league->rememberMap($sacha->fresh(), 'test_ville');
        $this->assertSame(0, $league->progress($sacha->fresh()));
    }

    public function test_the_champion_sends_the_team_to_the_hall_of_fame(): void
    {
        $league = app(LeagueService::class);
        $sacha = $this->champion();
        $this->assertSame([50], $league->team($sacha, $this->members['e1'])->pluck('level')->all());

        $league->afterBattle($sacha, $this->members['e1'], 'win');
        $league->afterBattle($sacha->fresh(), $this->members['e2'], 'win');
        $info = $league->afterBattle($sacha->fresh(), $this->members['maitre'], 'win');

        $this->assertSame(0, $info['progress']);
        $this->assertSame(1, $info['hallOfFame']['number']);
        $this->assertSame('Mewtwo', $info['hallOfFame']['team'][0]['name']);
        $this->assertSame(70, $info['hallOfFame']['team'][0]['level']);
        $this->assertSame(1, HallOfFameEntry::count());

        // Après la première victoire, la Ligue est plus forte
        $this->assertSame([60], $league->team($sacha->fresh(), $this->members['e1'])->pluck('level')->all());

        // Le PC du Panthéon, et la page du Wiki
        $this->actingAs($sacha->user)->getJson("/jouer/{$sacha->id}/pantheon")->assertOk()->assertJsonPath('entries.0.trainer.name', 'Sacha');
        $this->get('/wiki/pantheon')->assertOk()->assertSee('Sacha')->assertSee('Mewtwo');
    }

    public function test_a_battle_against_a_member_moves_the_league_forward(): void
    {
        $sacha = $this->champion();
        $battle = app(\App\Services\BattleService::class)->startTrainer($sacha, $this->members['e1']);
        $state = $battle->state;
        $state['foe']['party'][0]['hp'] = 1;
        $battle->forceFill(['state' => $state])->save();
        $slot = collect($state['player']['party'][0]['moves'])->search(fn ($m) => ($m['power'] ?? 0) > 0);
        for ($i = 0; $i < 20 && $battle->fresh()->status === 'active'; $i++) {
            $phase = $battle->fresh()->state['phase'];
            $out = app(\App\Services\BattleService::class)->act($battle->fresh(), $phase === 'command' ? ['type' => 'move', 'slot' => $slot] : ['type' => 'continue']);
        }
        $this->assertSame('win', $battle->fresh()->result);
        $this->assertSame(1, app(LeagueService::class)->progress($sacha->fresh()));
        $this->assertSame('leader', $battle->state['foe']['music']);   // la musique des Champions pour le Conseil 4
    }
}
