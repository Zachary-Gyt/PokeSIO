<?php

namespace Tests\Feature\Game;

use App\Events\ChatMessage;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupInvitation;
use Tests\GameTestCase;
use Tests\Support\GameWorld;

/** Les canaux du chat (local, global, privé, groupe) et les groupes de joueurs */
class ChatGroupTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GameWorld::map('test_ville');
    }

    private function player(string $name): Character
    {
        $c = GameWorld::character(['name' => $name]);
        $c->forceFill(['last_seen_at' => now(), 'current_map' => 'test_ville'])->save();

        return $c;
    }

    private function say(Character $c, string $text)
    {
        return $this->actingAs($c->user)->postJson("/jouer/{$c->id}/chat", ['map' => 'test_ville', 'text' => $text]);
    }

    /** A invite B (commande), B accepte (écran) : renvoie le groupe */
    private function join(Character $leader, Character $member): Group
    {
        $this->say($leader, "/inviter {$member->name}")->assertOk();
        $invitation = GroupInvitation::where('invitee_id', $member->id)->latest('id')->first();
        $this->actingAs($member->user)->postJson(route('group.answer', $member), ['id' => $invitation->id, 'accept' => true])->assertOk();

        return Group::findOrFail($member->fresh()->group_id);
    }

    public function test_local_and_global_messages(): void
    {
        $sacha = $this->player('Sacha');

        $this->say($sacha, 'Bonjour la ville')->assertOk();
        $this->say($sacha, '/all Bonjour le monde')->assertOk();

        $this->assertSame('local', collect(cache('chat:test_ville'))->last()['channel']);
        $global = collect(cache('chat:global'))->last();
        $this->assertSame(['global', 'Bonjour le monde'], [$global['channel'], $global['text']]);
        // Les canaux Reverb de chaque type de message
        $this->assertSame('private-chat.global', (new ChatMessage('global', null, []))->broadcastOn()[0]->name);
        $this->assertSame('presence-map.test_ville', (new ChatMessage('local', 'test_ville', []))->broadcastOn()[0]->name);
        $this->assertSame('presence-group.3', (new ChatMessage('group', 3, []))->broadcastOn()[0]->name);
        $this->assertSame(['private-character.1', 'private-character.2'], collect((new ChatMessage('private', [1, 2], []))->broadcastOn())->pluck('name')->all());
    }

    public function test_private_messages_with_a_name_containing_spaces_and_reply(): void
    {
        $sacha = $this->player('Sacha');
        $red = $this->player('Red Rouge');

        $this->say($sacha, '/msg red rouge Salut toi')->assertOk();
        $pm = collect(cache("chat:pm:{$red->id}"))->last();
        $this->assertSame(['private', 'Salut toi', 'Red Rouge'], [$pm['channel'], $pm['text'], $pm['to']]);
        $this->assertSame($pm, collect(cache("chat:pm:{$sacha->id}"))->last());   // l'expéditeur le garde aussi

        // /r répond au dernier qui m'a écrit
        $this->say($red, '/r Coucou')->assertOk();
        $this->assertSame('Sacha', collect(cache("chat:pm:{$sacha->id}"))->last()['to']);

        // Joueur hors ligne, inconnu, message vide
        $red->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->say($sacha, '/msg "Red Rouge" Tu es là ?')->assertStatus(422)->assertJson(['message' => 'Red Rouge n\'est pas connecté.']);
        $this->say($sacha, '/msg Personne salut')->assertStatus(422);
        $this->say($sacha, '/msg Sacha')->assertStatus(422);
    }

    public function test_a_group_is_created_by_an_accepted_invitation_and_has_its_chat(): void
    {
        $sacha = $this->player('Sacha');
        $ondine = $this->player('Ondine');

        $this->say($sacha, '/g Coucou')->assertStatus(422);   // pas encore de groupe
        $group = $this->join($sacha, $ondine);

        $this->assertSame($sacha->id, $group->leader_id);
        $this->assertSame([$sacha->id, $ondine->id], $group->members()->pluck('id')->all());

        $this->say($ondine, '/g On se retrouve au Centre ?')->assertOk();
        $this->assertSame('group', collect(cache("chat:group:{$group->id}"))->last()['channel']);

        // L'historique de Sacha mélange ses canaux, dans l'ordre
        $this->say($sacha, '/all Hello')->assertOk();
        $messages = $this->actingAs($sacha->user)->getJson("/jouer/{$sacha->id}/chat?map=test_ville")->json('messages');
        $this->assertSame(['group', 'global'], array_column($messages, 'channel'));

        // /groupe : la liste
        $lines = $this->say($sacha, '/groupe')->json('reply');
        $this->assertSame('Groupe (2/4) :', $lines[0]);
        $this->assertStringContainsString('★ Sacha', $lines[1]);
    }

    public function test_size_limit_leader_rules_and_leaving(): void
    {
        $a = $this->player('Alpha');
        $members = [$this->player('Beta'), $this->player('Gamma'), $this->player('Delta')];
        foreach ($members as $m) {
            $group = $this->join($a, $m);
        }
        $this->assertSame(4, $group->members()->count());

        $extra = $this->player('Epsilon');
        $this->say($a, '/inviter Epsilon')->assertStatus(422);              // complet
        $this->say($members[0], '/inviter Epsilon')->assertStatus(422);     // seul le chef invite
        $this->say($members[0], '/exclure Gamma')->assertStatus(422);       // seul le chef exclut

        // Le chef part : le plus ancien membre devient chef
        $this->say($a, '/quitter')->assertOk();
        $this->assertSame($members[0]->id, $group->fresh()->leader_id);
        $this->assertNull($a->fresh()->group_id);

        // Exclusion, puis le dernier départ dissout le groupe
        $this->say($members[0], '/exclure Gamma')->assertOk();
        $this->say($members[0], '/chef Delta')->assertOk();
        $this->assertSame($members[2]->id, $group->fresh()->leader_id);
        $this->say($members[2], '/quitter')->assertOk();
        $this->assertNull(Group::find($group->id));
        $this->assertNull($members[0]->fresh()->group_id);
    }

    public function test_invitation_rules(): void
    {
        $sacha = $this->player('Sacha');
        $ondine = $this->player('Ondine');
        $pierre = $this->player('Pierre');
        $this->join($sacha, $ondine);

        $this->say($pierre, '/inviter Ondine')->assertStatus(422)->assertJson(['message' => 'Ondine est déjà dans un groupe.']);
        $this->say($sacha, '/inviter Sacha')->assertStatus(422);

        // Une invitation trop vieille ne vaut plus rien
        $this->say($sacha, '/inviter Pierre')->assertOk();
        $invitation = GroupInvitation::where('invitee_id', $pierre->id)->latest('id')->first();
        $invitation->forceFill(['created_at' => now()->subMinutes(5)])->save();
        $this->actingAs($pierre->user)->postJson(route('group.answer', $pierre), ['id' => $invitation->id, 'accept' => true])->assertStatus(422);

        // Refuser
        $this->say($sacha, '/inviter Pierre')->assertOk();
        $invitation = GroupInvitation::where('invitee_id', $pierre->id)->latest('id')->first();
        $this->actingAs($pierre->user)->postJson(route('group.answer', $pierre), ['id' => $invitation->id, 'accept' => false])->assertOk();
        $this->assertNull($pierre->fresh()->group_id);
    }

    public function test_the_group_screen(): void
    {
        $sacha = $this->player('Sacha');
        $ondine = $this->player('Ondine');
        $this->player('Pierre');

        $data = $this->actingAs($sacha->user)->getJson(route('group.show', $sacha))->assertOk()->json();
        $this->assertNull($data['group']);
        $this->assertEqualsCanonicalizing(['Ondine', 'Pierre'], array_column($data['candidates'], 'name'));

        $this->actingAs($sacha->user)->postJson(route('group.invite', $sacha), ['target' => $ondine->id])->assertOk();
        $invitation = GroupInvitation::where('invitee_id', $ondine->id)->latest('id')->first();
        $this->actingAs($ondine->user)->postJson(route('group.answer', $ondine), ['id' => $invitation->id, 'accept' => true])->assertOk();

        $group = $this->actingAs($sacha->user)->getJson(route('group.show', $sacha))->json('group');
        $this->assertSame(['Sacha', 'Ondine'], array_column($group['members'], 'name'));
        $this->assertTrue($group['members'][0]['leader']);
        // Un membre (pas chef) ne reçoit pas de liste d'invitation
        $this->assertSame([], $this->actingAs($ondine->user)->getJson(route('group.show', $ondine))->json('candidates'));
    }

    public function test_commands(): void
    {
        $sacha = $this->player('Sacha');

        $this->assertContains('/all Message : à tous les joueurs (global)', $this->say($sacha, '/aide')->json('reply'));
        $this->assertSame(['Les commandes sont réservées aux administrateurs.'], $this->say($sacha, '/heal')->json('reply'));
        $this->assertSame(['Commande inconnue : /danse. Tapez /aide.'], $this->say($sacha, '/danse')->json('reply'));
    }
}
