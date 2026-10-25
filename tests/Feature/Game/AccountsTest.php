<?php

namespace Tests\Feature\Game;

use App\Models\Character;
use App\Models\PokemonSpecies;
use App\Services\PlaySession;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Le grade Professeur, les noms de personnage uniques, et une seule partie à la fois par compte */
class AccountsTest extends GameTestCase
{
    public function test_character_names_are_unique_in_the_whole_game(): void
    {
        GameWorld::character(['name' => 'Sacha']);
        $other = GameWorld::character(['name' => 'Ondine', 'slot' => 1]);
        $starter = PokemonSpecies::where('slug', 'bulbasaur')->value('id');
        $avatar = array_key_first(config('avatars'));

        $this->actingAs($other->user)
            ->post('/personnages', ['slot' => 2, 'name' => ' sacha ', 'avatar' => $avatar, 'starter_species_id' => $starter])
            ->assertSessionHasErrors(['name' => 'Désolé, ce nom est déjà utilisé.']);
        $this->assertSame(1, Character::where('name', 'Sacha')->count());
    }

    public function test_professor_title_in_the_chat_only(): void
    {
        $prof = GameWorld::character(['name' => 'Chen']);
        $this->artisan('game:professeur', ['email' => $prof->user->email])->assertSuccessful();
        $this->assertTrue((bool) $prof->user->fresh()->is_professor);
        $this->assertFalse((bool) $prof->user->fresh()->is_admin);

        GameWorld::map('test_ville');
        $this->actingAs($prof->user->fresh())
            ->postJson("/jouer/{$prof->id}/chat", ['map' => 'test_ville', 'text' => 'Bonjour !'])->assertOk();
        $message = collect(cache()->get('chat:test_ville'))->last();
        $this->assertTrue($message['professor']);
        $this->assertFalse($message['admin']);

        // Aucun privilège : pas de commandes
        $reply = $this->postJson("/jouer/{$prof->id}/chat", ['map' => 'test_ville', 'text' => '/heal'])->json('reply');
        $this->assertSame(['Les commandes sont réservées aux administrateurs.'], $reply);
    }

    public function test_only_one_game_at_a_time_per_account(): void
    {
        $sacha = GameWorld::character();
        $user = $sacha->user;
        $first = PlaySession::start($user, $sacha);
        $second = PlaySession::start($user, $sacha);   // le même compte ouvre le jeu ailleurs

        $this->actingAs($user);
        $url = "/jouer/{$sacha->id}/equipe";
        $this->getJson($url, ['X-Play-Token' => $first])->assertStatus(409)->assertJsonPath('elsewhere', true);
        $this->getJson($url, ['X-Play-Token' => $second])->assertOk();
        $this->getJson($url)->assertOk();   // une page sans jeton (Wiki, menu...) n'est pas concernée
    }
}
