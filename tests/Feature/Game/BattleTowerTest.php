<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Models\BattleTowerRecord;
use App\Models\Character;
use App\Services\BattleService;
use App\Services\BattleTowerService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 28 : la Tour de Combat (3 Pokémon, niveau 20 puis de plus en plus haut, séries de 7, Maître de la Tour, record) */
class BattleTowerTest extends GameTestCase
{
    private BattleTowerService $tower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tower = app(BattleTowerService::class);
        GameWorld::map('test_tour', ['npcs' => [[3, 3, ['name' => 'Hôtesse', 'action' => 'battle_tower', 'text' => '...']]]]);
    }

    /** Un dresseur avec Herbizarre N. 70, Pikachu, Ponyta et Mewtwo */
    private function player(): array
    {
        $sacha = GameWorld::character([], 'ivysaur', 70);
        $pika = GameWorld::give($sacha, 'pikachu', 15);
        $pony = GameWorld::give($sacha, 'ponyta', 30);
        $mew = GameWorld::give($sacha, 'mewtwo', 70);

        return [$sacha->fresh(), $sacha->party()->orderBy('party_slot')->first(), $pika, $pony, $mew];
    }

    public function test_choosing_the_team_follows_the_rules(): void
    {
        [$sacha, $ivy, $pika, $pony, $mew] = $this->player();

        $this->assertTrue(collect($this->tower->status($sacha)['eligible'])->firstWhere('id', $mew->id)['banned']);
        foreach ([[$ivy->id, $pika->id], [$ivy->id, $pika->id, $mew->id], [$ivy->id, $ivy->id, $pika->id]] as $ids) {
            try {
                $this->tower->start($sacha->fresh(), $ids);
                $this->fail('Équipe refusée attendue : ' . implode(',', $ids));
            } catch (InvalidAction $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $status = $this->tower->start($sacha->fresh(), [$ivy->id, $pika->id, $pony->id]);
        $this->assertSame(0, $status['run']['streak']);
        $this->assertSame(['Herbizarre', 'Pikachu', 'Ponyta'], collect($status['run']['team'])->pluck('speciesName')->all());
    }

    public function test_a_battle_caps_levels_and_keeps_the_real_team_untouched(): void
    {
        [$sacha, $ivy, $pika, $pony] = $this->player();
        $this->tower->start($sacha, [$ivy->id, $pika->id, $pony->id]);
        $battle = $this->tower->nextBattle($sacha->fresh());

        $state = $battle->state;
        $this->assertTrue($state['tower']);
        $this->assertSame(20, $state['player']['party'][0]['level']);   // Herbizarre N. 70 -> 20 (premier niveau de la Tour)
        $this->assertSame(15, $state['player']['party'][1]['level']);   // pas de hausse pour les petits niveaux
        $this->assertCount(3, $state['foe']['party']);
        $this->assertSame([20, 20, 20], array_column($state['foe']['party'], 'level'));
        $this->assertSame(70, $ivy->fresh()->level);
    }

    public function test_winning_moves_the_streak_and_money_but_gives_no_experience(): void
    {
        [$sacha, $ivy, $pika, $pony] = $this->player();
        $this->tower->start($sacha, [$ivy->id, $pika->id, $pony->id]);
        $money = $sacha->fresh()->money;
        $exp = $ivy->fresh()->experience;

        $battle = $this->tower->nextBattle($sacha->fresh());
        $state = $battle->state;
        foreach ($state['foe']['party'] as $i => $p) {
            $state['foe']['party'][$i]['hp'] = $i === 0 ? 1 : 0;
        }
        $battle->forceFill(['state' => $state])->save();
        $slot = collect($state['player']['party'][0]['moves'])->search(fn ($m) => ($m['power'] ?? 0) > 0);
        $service = app(BattleService::class);
        $out = null;
        for ($i = 0; $i < 20 && $battle->fresh()->status === 'active'; $i++) {
            $phase = $battle->fresh()->state['phase'];
            $out = $service->act($battle->fresh(), $phase === 'command' ? ['type' => 'move', 'slot' => $slot] : ['type' => 'continue']);
        }

        $this->assertSame('win', $battle->fresh()->result);
        $this->assertSame(1, $sacha->fresh()->story_flags['tower']['streak']);
        $this->assertSame($money + 1000, $sacha->fresh()->money);
        $this->assertSame($exp, $ivy->fresh()->experience);
        $this->assertSame(1, BattleTowerRecord::where('character_id', $sacha->id)->value('best_streak'));
    }

    public function test_the_level_rises_set_after_set(): void
    {
        $this->assertSame([20, 20, 25, 25, 30, 100], array_map(fn ($n) => $this->tower->levelFor($n), [1, 7, 8, 14, 15, 500]));
        config(['battle_tower.level' => 50]);   // l'ancien réglage : un niveau fixe
        $this->assertSame(50, $this->tower->levelFor(30));
        config(['battle_tower.level' => ['start' => 20, 'step' => 5, 'every' => 7, 'max' => 100]]);

        // Après le 7e combat : le joueur est prévenu, et le combat suivant est au niveau 25
        [$sacha, $ivy, $pika, $pony] = $this->player();
        $this->tower->start($sacha, [$ivy->id, $pika->id, $pony->id]);
        for ($i = 0; $i < 7; $i++) {
            $info = $this->tower->afterBattle($sacha->fresh(), [], 'win');
        }
        $this->assertTrue($info['levelUp']);
        $this->assertSame(25, $info['nextLevel']);
        $state = $this->tower->nextBattle($sacha->fresh())->state;
        $this->assertSame(25, $state['player']['party'][0]['level']);
        $this->assertSame([25, 25, 25], array_column($state['foe']['party'], 'level'));
    }

    public function test_sets_tycoon_and_records(): void
    {
        [$sacha, $ivy, $pika, $pony] = $this->player();
        $this->tower->start($sacha, [$ivy->id, $pika->id, $pony->id]);
        $info = null;
        for ($i = 0; $i < 7; $i++) {
            $info = $this->tower->afterBattle($sacha->fresh(), [], 'win');
        }
        $this->assertTrue($info['setDone']);
        $this->assertSame(7, $info['streak']);
        $this->assertSame(1, BattleTowerRecord::where('character_id', $sacha->id)->value('sets'));

        // Le Maître de la Tour : au 21e combat (argent), puis tous les 49
        $this->assertSame('silver', $this->tower->isTycoonBattle(21));
        $this->assertSame('gold', $this->tower->isTycoonBattle(49));
        $this->assertNull($this->tower->isTycoonBattle(20));
        $this->assertSame((1000 + 100 * 6) * 2, $this->tower->opponent(7)['money']);   // le 7e combat vaut double

        // Une défaite : la série s'arrête, le record reste
        $end = $this->tower->afterBattle($sacha->fresh(), [], 'loss');
        $this->assertTrue($end['ended']);
        $this->assertArrayNotHasKey('tower', $sacha->fresh()->story_flags ?? []);
        $this->assertSame(7, $this->tower->best($sacha->fresh()));
    }

    public function test_http_counter_board_and_wiki(): void
    {
        [$sacha, $ivy, $pika, $pony] = $this->player();
        $this->actingAs($sacha->user);
        $base = "/jouer/{$sacha->id}/tour";

        $this->getJson($base)->assertOk()->assertJsonPath('rules.size', 3)->assertJsonPath('run', null);
        $this->postJson("{$base}/commencer", ['map' => 'test_ville', 'pokemon_ids' => [$ivy->id, $pika->id, $pony->id]])->assertStatus(422);
        $this->postJson("{$base}/commencer", ['map' => 'test_tour', 'pokemon_ids' => [$ivy->id, $pika->id, $pony->id]])->assertOk()->assertJsonPath('run.streak', 0);
        $this->postJson("{$base}/combat", ['map' => 'test_tour'])->assertOk()->assertJsonPath('number', 1)->assertJsonStructure(['battle', 'events', 'trainer' => ['name', 'intro']]);

        $this->tower->afterBattle($sacha->fresh(), [], 'win');
        $this->postJson("{$base}/abandonner", [])->assertOk()->assertJsonPath('streak', 1);
        $this->getJson("{$base}/classement")->assertOk()->assertJsonPath('records.0.name', 'Sacha')->assertJsonPath('records.0.streak', 1);
        $this->get('/wiki/tour-de-combat')->assertOk()->assertSee('Sacha');
    }
}
