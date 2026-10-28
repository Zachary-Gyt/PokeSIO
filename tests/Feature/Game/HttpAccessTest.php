<?php

namespace Tests\Feature\Game;

use App\Models\Pokemon;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Les routes du jeu : connexion obligatoire, personnage d'un autre joueur interdit, données vérifiées */
class HttpAccessTest extends GameTestCase
{
    public function test_guests_are_sent_to_the_login_page(): void
    {
        $sacha = GameWorld::character();

        $this->get("/jouer/{$sacha->id}")->assertRedirect(route('login'));
        $this->getJson("/jouer/{$sacha->id}/equipe")->assertUnauthorized();
    }

    public function test_a_player_cannot_use_someone_elses_character(): void
    {
        $sacha = GameWorld::character();
        $ondine = GameWorld::character(['name' => 'Ondine']);

        $this->actingAs($ondine->user)->getJson("/jouer/{$sacha->id}/equipe")->assertNotFound();
        $this->actingAs($ondine->user)->getJson("/jouer/{$sacha->id}/sac")->assertNotFound();
        $this->actingAs($ondine->user)->postJson("/jouer/{$sacha->id}/casino/jetons", ['map' => 'test_ville', 'amount' => 50])->assertNotFound();
    }

    public function test_the_owner_sees_their_party(): void
    {
        $sacha = GameWorld::character();

        $this->actingAs($sacha->user)->getJson("/jouer/{$sacha->id}/equipe")->assertOk();
    }

    public function test_nickname_rules(): void
    {
        $sacha = GameWorld::character();
        $bulbi = $sacha->party()->first();
        $url = "/jouer/{$sacha->id}/equipe/surnom";
        $as = $this->actingAs($sacha->user);

        $as->postJson($url, ['pokemon_id' => $bulbi->id, 'nickname' => 'Bubu'])->assertOk();
        $this->assertSame('Bubu', $bulbi->fresh()->nickname);

        $as->postJson($url, ['pokemon_id' => $bulbi->id, 'nickname' => '<script>'])->assertUnprocessable();
        $as->postJson($url, ['pokemon_id' => $bulbi->id, 'nickname' => str_repeat('a', 30)])->assertUnprocessable();

        // Le nom de l'espèce (ou rien) efface le surnom
        $as->postJson($url, ['pokemon_id' => $bulbi->id, 'nickname' => 'Bulbizarre'])->assertOk();
        $this->assertNull($bulbi->fresh()->nickname);
    }

    public function test_a_traded_pokemon_keeps_its_name(): void
    {
        config(['move_tutor.rename_traded' => false]);
        $sacha = GameWorld::character();
        $traded = GameWorld::give($sacha, 'pikachu', 10, [], ['trainer_id' => 55555, 'trainer_name' => 'Pierre']);

        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/equipe/surnom", ['pokemon_id' => $traded->id, 'nickname' => 'Pika'])
            ->assertUnprocessable();
    }

    public function test_casino_actions_must_exist_on_the_map(): void
    {
        GameWorld::map('test_ville');
        $sacha = GameWorld::character();
        $sacha->addItem(GameWorld::item('coin-case'));

        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/casino/jetons", ['map' => 'test_ville', 'amount' => 50])->assertUnprocessable();
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/casino/jetons", ['map' => '../../etc', 'amount' => 50])->assertUnprocessable();
    }

    public function test_casino_buy_through_http(): void
    {
        GameWorld::map('test_casino', ['npcs' => [[1, 1, ['action' => 'coins']]]]);
        $sacha = GameWorld::character(['money' => 2000]);
        $sacha->addItem(GameWorld::item('coin-case'));

        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/casino/jetons", ['map' => 'test_casino', 'amount' => 50])
            ->assertOk()->assertJson(['coins' => 50, 'money' => 1000]);
    }

    public function test_pc_release_of_another_players_pokemon_is_refused(): void
    {
        $sacha = GameWorld::character();
        $ondine = GameWorld::character(['name' => 'Ondine']);
        $hers = GameWorld::give($ondine, 'rattata', 5);

        $status = $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/pc/relacher", ['pokemon_id' => $hers->id])->status();
        $this->assertContains($status, [403, 404]);
        $this->assertNotNull(Pokemon::find($hers->id));
    }

    public function test_steps_are_validated(): void
    {
        $sacha = GameWorld::character();

        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/pas", ['steps' => -5])->assertUnprocessable();
        $this->actingAs($sacha->user)->postJson("/jouer/{$sacha->id}/pas", ['steps' => 10])->assertOk();
    }
}
