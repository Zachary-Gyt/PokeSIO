<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Events\PlayerNotice;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupInvitation;
use Illuminate\Support\Facades\DB;

/**
 * Les GROUPES de joueurs : inviter, accepter, quitter, exclure, nommer un chef.
 *
 * - 4 joueurs au plus (config chat.group_size), chef compris ; un joueur n'est que dans un groupe.
 * - Le groupe est créé quand la première invitation est acceptée ; il disparaît quand il ne reste qu'un joueur.
 * - Si le chef part, le plus ancien membre devient chef.
 * - Chaque changement est annoncé aux membres par une notification "group" (le jeu l'écrit dans le chat
 *   et met à jour son canal de groupe).
 */
class GroupService
{
    public function invite(Character $from, Character $to): GroupInvitation
    {
        if ($from->id === $to->id) {
            throw new InvalidAction('Vous ne pouvez pas vous inviter vous-même !');
        }
        if ($to->group_id && $to->group_id === $from->group_id) {
            throw new InvalidAction("{$to->name} est déjà dans votre groupe.");
        }
        if ($to->group_id) {
            throw new InvalidAction("{$to->name} est déjà dans un groupe.");
        }
        if (! $to->isOnline()) {
            throw new InvalidAction("{$to->name} n'est pas connecté.");
        }
        if ($from->group_id && $from->group->leader_id !== $from->id) {
            throw new InvalidAction('Seul le chef du groupe peut inviter.');
        }
        if ($from->group_id && $from->group->members()->count() >= $this->maxSize()) {
            throw new InvalidAction("Le groupe est complet ({$this->maxSize()} joueurs).");
        }

        // Une seule invitation en attente de A vers B
        GroupInvitation::where('inviter_id', $from->id)->where('invitee_id', $to->id)->where('status', 'pending')->update(['status' => 'expired']);
        $invitation = GroupInvitation::create(['inviter_id' => $from->id, 'invitee_id' => $to->id]);

        PlayerNotice::send($to->id, 'invitation', [
            'id' => $invitation->id,
            'kind' => 'group',
            'from' => ['id' => $from->id, 'name' => $from->name],
        ]);

        return $invitation;
    }

    /** L'invité répond. Renvoie le groupe rejoint (ou null s'il refuse). */
    public function answer(Character $to, int $id, bool $accept): ?Group
    {
        return DB::transaction(function () use ($to, $id, $accept) {
            $invitation = GroupInvitation::whereKey($id)->lockForUpdate()->first();
            if (! $invitation || $invitation->invitee_id !== $to->id || $invitation->status !== 'pending'
                || $invitation->created_at->lt(now()->subSeconds((int) config('chat.invitation_seconds', 60)))) {
                throw new InvalidAction('Cette invitation n\'est plus valable.');
            }
            $from = Character::whereKey($invitation->inviter_id)->lockForUpdate()->first();

            if (! $accept) {
                $invitation->update(['status' => 'declined']);
                PlayerNotice::send($from->id, 'answer', ['kind' => 'group', 'accepted' => false, 'name' => $to->name]);

                return null;
            }

            $to = Character::whereKey($to->id)->lockForUpdate()->first();
            if ($to->group_id) {
                throw new InvalidAction('Vous êtes déjà dans un groupe. Quittez-le d\'abord (/quitter).');
            }
            if ($from->group_id && $from->group->leader_id !== $from->id) {
                throw new InvalidAction("{$from->name} n'est plus chef de son groupe.");
            }

            $group = $from->group;
            if (! $group) {
                $group = Group::create(['leader_id' => $from->id]);
                $from->forceFill(['group_id' => $group->id, 'group_joined_at' => now()])->save();
            }
            if ($group->members()->count() >= $this->maxSize()) {
                throw new InvalidAction('Le groupe est complet.');
            }

            $to->forceFill(['group_id' => $group->id, 'group_joined_at' => now()->addSecond()])->save();
            $invitation->update(['status' => 'accepted']);

            PlayerNotice::send($from->id, 'answer', ['kind' => 'group', 'accepted' => true, 'name' => $to->name]);
            $this->announce($group, "{$to->name} rejoint le groupe.");

            return $group;
        });
    }

    /** Quitter son groupe */
    public function leave(Character $character): void
    {
        $group = $character->group ?? throw new InvalidAction('Vous n\'êtes dans aucun groupe.');
        $this->remove($group, $character, "{$character->name} quitte le groupe.");
        PlayerNotice::send($character->id, 'group', ['groupId' => null, 'text' => 'Vous quittez le groupe.']);
    }

    /** Le chef exclut un membre */
    public function kick(Character $leader, Character $target): void
    {
        $group = $this->ledBy($leader);
        if ($target->id === $leader->id) {
            throw new InvalidAction('Pour partir, utilisez /quitter.');
        }
        if ($target->group_id !== $group->id) {
            throw new InvalidAction("{$target->name} n'est pas dans votre groupe.");
        }
        $this->remove($group, $target, "{$target->name} est exclu du groupe.");
        PlayerNotice::send($target->id, 'group', ['groupId' => null, 'text' => "{$leader->name} vous a exclu du groupe."]);
    }

    /** Le chef donne sa place */
    public function promote(Character $leader, Character $target): void
    {
        $group = $this->ledBy($leader);
        if ($target->group_id !== $group->id || $target->id === $leader->id) {
            throw new InvalidAction("{$target->name} n'est pas un autre membre du groupe.");
        }
        $group->update(['leader_id' => $target->id]);
        $this->announce($group, "{$target->name} devient chef du groupe.");
    }

    /**
     * Ce que le jeu affiche (écran Groupe) : les membres, le chef, connectés ou non, et sur quelle carte.
     * null si le joueur n'a pas de groupe.
     */
    public function view(Character $character): ?array
    {
        $group = $character->fresh()->group;
        if (! $group) {
            return null;
        }

        return [
            'id' => $group->id,
            'leaderId' => $group->leader_id,
            'max' => $this->maxSize(),
            'members' => $group->members()->get()->map(fn (Character $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'leader' => $c->id === $group->leader_id,
                'online' => $c->isOnline(),
                'map' => $c->isOnline() ? $this->mapName($c->current_map) : null,
            ])->all(),
        ];
    }

    /** Les joueurs connectés qu'on peut inviter (sans groupe), ceux de ma carte d'abord */
    public function candidates(Character $character, int $limit = 7): array
    {
        return Character::where('id', '!=', $character->id)->whereNull('group_id')
            ->where('last_seen_at', '>=', now()->subSeconds((int) config('chat.online_seconds', 120)))
            ->orderByRaw('CASE WHEN current_map = ? THEN 0 ELSE 1 END', [$character->current_map])->orderBy('name')
            ->limit($limit)->get()
            ->map(fn (Character $c) => ['id' => $c->id, 'name' => $c->name, 'map' => $this->mapName($c->current_map)])->all();
    }

    public function maxSize(): int
    {
        return max(2, (int) config('chat.group_size', 4));
    }

    // ---------------------------------------------------------------------

    private function ledBy(Character $leader): Group
    {
        $group = $leader->group ?? throw new InvalidAction('Vous n\'êtes dans aucun groupe.');
        if ($group->leader_id !== $leader->id) {
            throw new InvalidAction('Seul le chef du groupe peut faire cela.');
        }

        return $group;
    }

    /** Retire un membre ; nouveau chef si besoin ; le groupe disparaît s'il ne reste qu'un joueur */
    private function remove(Group $group, Character $character, string $text): void
    {
        DB::transaction(function () use ($group, $character, $text) {
            $character->forceFill(['group_id' => null, 'group_joined_at' => null])->save();
            $remaining = $group->members()->get();

            if ($remaining->count() <= 1) {
                foreach ($remaining as $last) {
                    $last->forceFill(['group_id' => null, 'group_joined_at' => null])->save();
                    PlayerNotice::send($last->id, 'group', ['groupId' => null, 'text' => "{$text} Le groupe est dissous."]);
                }
                $group->delete();

                return;
            }

            if ($group->leader_id === $character->id) {
                $group->update(['leader_id' => $remaining->first()->id]);
                $text .= " {$remaining->first()->name} devient chef du groupe.";
            }
            $this->announce($group, $text);
        });
    }

    /** Un message à tous les membres du groupe (notification "group", écrite dans leur chat) */
    private function announce(Group $group, string $text): void
    {
        foreach ($group->members()->pluck('id') as $id) {
            PlayerNotice::send($id, 'group', ['groupId' => $group->id, 'text' => $text]);
        }
    }

    private function mapName(?string $mapKey): string
    {
        return ($mapKey ? MapInfo::load($mapKey)?->property('displayName') : null) ?? $mapKey ?? '?';
    }
}
