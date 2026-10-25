<?php

namespace Tests\Feature\Game;

use App\Battle\InvalidAction;
use App\Models\Badge;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Services\BattleService;
use App\Services\RematchService;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Étape 26 : les revanches (Cherche VS, équipes de revanche, Champions) */
class RematchTest extends GameTestCase
{
    private function trainer(string $slug, array $team, array $attributes = []): Trainer
    {
        $trainer = Trainer::create($attributes + ['slug' => $slug, 'name' => 'Léo', 'trainer_class' => 'Gamin', 'reward_money' => 100, 'sight_range' => 3, 'ai_level' => 1]);
        foreach ($team as [$version, $species, $level, $slot]) {
            $trainer->pokemon()->create(['version' => $version, 'slot' => $slot, 'pokemon_species_id' => PokemonSpecies::where('slug', $species)->value('id'), 'level' => $level]);
        }

        return $trainer;
    }

    private function beat(Character $character, Trainer $trainer): void
    {
        $character->defeatedTrainers()->attach($trainer->id);
    }

    private function badges(Character $character, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $badge = Badge::firstOrCreate(['order' => $i], ['name' => "Badge {$i}"]);
            $character->badges()->attach($badge->id, ['obtained_at' => now()]);
        }
    }

    private function vsSeeker(Character $character): void
    {
        $item = Item::forceCreate(['slug' => 'vs-seeker', 'name' => 'Cherche VS', 'pocket' => 'key_items', 'effect_code' => 'vs_seeker', 'price' => 0, 'sell_price' => 0, 'consumable' => false]);
        $character->addItem($item);
    }

    public function test_the_team_depends_on_the_badges(): void
    {
        $leo = $this->trainer('leo', [[0, 'rattata', 5, 1], [1, 'rattata', 15, 1], [1, 'pikachu', 16, 2], [3, 'pikachu', 30, 1]]);
        $sacha = GameWorld::character();
        $rematches = app(RematchService::class);

        $this->assertSame([5], $rematches->team($sacha, $leo)->pluck('level')->all());   // premier combat
        $this->beat($sacha, $leo);
        $this->assertSame([5], $rematches->team($sacha, $leo)->pluck('level')->all());   // 0 badge : rien de débloqué, et pas de bonus
        $this->badges($sacha, 1);
        $this->assertSame([15, 16], $rematches->team($sacha, $leo->fresh())->pluck('level')->all());
        $this->badges($sacha->fresh(), 0);
        Badge::firstOrCreate(['order' => 2], ['name' => 'B2']);
        foreach ([2, 3, 4, 5] as $o) {
            $sacha->badges()->syncWithoutDetaching([Badge::firstOrCreate(['order' => $o], ['name' => "B{$o}"])->id => ['obtained_at' => now()]]);
        }
        $this->assertSame([30], $rematches->team($sacha->fresh(), $leo)->pluck('level')->all());   // 5 badges : la version 3
    }

    public function test_without_a_rematch_team_the_pokemon_grow_and_evolve(): void
    {
        $leo = $this->trainer('leo', [[0, 'bulbasaur', 10, 1]]);
        $sacha = GameWorld::character([], 'pikachu', 50);
        $this->beat($sacha, $leo);
        $this->badges($sacha, 2);   // +6 niveaux -> 16 : Bulbizarre évolue (Herbizarre au niveau 16 dans le petit monde de test)

        $team = app(RematchService::class)->team($sacha, $leo);
        $this->assertSame(16, $team[0]->level);
        $this->assertSame('ivysaur', $team[0]->species->slug);
        $this->assertSame(1, $leo->pokemon()->count());   // rien n'est enregistré
    }

    public function test_the_vs_seeker(): void
    {
        GameWorld::map('test_ville', ['npcs' => [[5, 5, ['trainer' => 'leo']], [30, 5, ['trainer' => 'loin']]]], [], 40, 10);
        $leo = $this->trainer('leo', [[0, 'rattata', 5, 1]]);
        $far = $this->trainer('loin', [[0, 'rattata', 5, 1]]);
        $sacha = GameWorld::character();
        $this->beat($sacha, $leo);
        $this->beat($sacha, $far);
        $rematches = app(RematchService::class);
        config(['rematches.vs_seeker.chance' => 100]);

        $this->assertFalse($leo->canChallenge($sacha));
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/cherche-vs", ['map' => 'test_ville', 'x' => 3, 'y' => 5])
            ->assertStatus(422)->assertJson(['message' => 'Vous n\'avez pas de Cherche VS.']);

        $this->vsSeeker($sacha);
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/cherche-vs", ['map' => 'test_ville', 'x' => 3, 'y' => 5])
            ->assertOk()->assertJson(['ready' => ['leo'], 'charge' => 100]);   // l'autre est trop loin
        $this->assertTrue($leo->canChallenge($sacha->fresh()));
        $this->assertFalse($far->canChallenge($sacha->fresh()));

        // Batterie vide, puis rechargée en marchant
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/cherche-vs", ['map' => 'test_ville', 'x' => 3, 'y' => 5])->assertStatus(422);
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/pas", ['steps' => 100])->assertOk();
        $this->assertLessThan(100, $rematches->charge($sacha->fresh()));   // le serveur limite les pas selon le temps écoulé
        $rematches->steps($sacha->fresh(), 100);
        $this->assertSame(0, $rematches->charge($sacha->fresh()));

        // La revanche gagnée : il ne veut plus se battre
        $battle = app(BattleService::class)->startTrainer($sacha->fresh(), $leo);
        $state = $battle->state;
        $state['foe']['party'][0]['hp'] = 1;
        $battle->forceFill(['state' => $state])->save();
        // Une capacité qui fait des dégâts (pas Rugissement)
        $slot = collect($state['player']['party'][$state['player']['active']]['moves'])->search(fn ($m) => ($m['power'] ?? 0) > 0);
        for ($i = 0; $i < 20 && $battle->fresh()->status === 'active'; $i++) {
            $phase = $battle->fresh()->state['phase'];
            app(BattleService::class)->act($battle->fresh(), $phase === 'command' ? ['type' => 'move', 'slot' => $slot] : ['type' => 'continue']);
        }
        $this->assertSame('win', $battle->fresh()->result, 'phase : ' . $battle->fresh()->state['phase']);
        $this->assertFalse($leo->canChallenge($sacha->fresh()));
        $this->assertSame(2, $sacha->defeatedTrainers()->whereKey($leo->id)->first()->pivot->times_defeated);
    }

    public function test_gym_leaders_accept_a_rematch_with_all_the_badges(): void
    {
        $sacha = GameWorld::character();
        $badge = Badge::firstOrCreate(['order' => 1], ['name' => 'Badge Roche']);
        $pierre = $this->trainer('pierre', [[0, 'rattata', 12, 1], [5, 'mewtwo', 60, 1]], ['badge_id' => $badge->id, 'name' => 'Pierre', 'trainer_class' => 'Champion']);
        $this->beat($sacha, $pierre);
        $sacha->badges()->attach($badge->id, ['obtained_at' => now()]);

        $this->assertFalse($pierre->canChallenge($sacha->fresh()));
        $this->expectExceptionObject(new InvalidAction('Champion Pierre a déjà été battu.'));
        try {
            app(BattleService::class)->startTrainer($sacha->fresh(), $pierre);
        } finally {
            foreach (range(2, 8) as $o) {
                $sacha->badges()->attach(Badge::firstOrCreate(['order' => $o], ['name' => "B{$o}"])->id, ['obtained_at' => now()]);
            }
            $this->assertTrue($pierre->canChallenge($sacha->fresh()));
            $battle = app(BattleService::class)->startTrainer($sacha->fresh(), $pierre);
            $this->assertSame('Mewtwo', $battle->state['foe']['party'][0]['name']);   // l'équipe de revanche (version 5)
            $this->assertSame(500, $battle->state['foe']['reward']);                  // 100 x 60 / 12
        }
    }

    /** L'objet offert par un dresseur (Cherche VS, Canne...) : à la première victoire seulement, pas aux revanches */
    public function test_the_reward_item_is_given_only_the_first_time(): void
    {
        GameWorld::map('test_ville', ['npcs' => [[5, 5, ['trainer' => 'hugo']]]]);
        config(['rematches.vs_seeker.chance' => 100]);
        $sacha = GameWorld::character();
        $potion = GameWorld::item('potion');
        $hugo = $this->trainer('hugo', [[0, 'rattata', 2, 1]], ['reward_item_id' => $potion->id]);
        $win = function () use ($sacha, $hugo) {
            $battle = app(BattleService::class)->startTrainer($sacha->fresh(), $hugo);
            $state = $battle->state;
            $state['foe']['party'][0]['hp'] = 1;
            $battle->forceFill(['state' => $state])->save();
            $slot = collect($state['player']['party'][$state['player']['active']]['moves'])->search(fn ($m) => ($m['power'] ?? 0) > 0);
            for ($i = 0; $i < 20 && $battle->fresh()->status === 'active'; $i++) {
                $phase = $battle->fresh()->state['phase'];
                app(BattleService::class)->act($battle->fresh(), $phase === 'command' ? ['type' => 'move', 'slot' => $slot] : ['type' => 'continue']);
            }
            $this->assertSame('win', $battle->fresh()->result);
        };

        $win();
        $this->assertSame(1, $sacha->fresh()->quantityOf($potion));

        // Revanche (Cherche VS) : pas une 2e fois
        $this->vsSeeker($sacha);
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/cherche-vs", ['map' => 'test_ville', 'x' => 3, 'y' => 5])->assertOk();
        $this->assertTrue($hugo->canChallenge($sacha->fresh()));
        $win();
        $this->assertSame(1, $sacha->fresh()->quantityOf($potion));
    }
}
