<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un message du chat (déjà vérifié et nettoyé par ChatController), selon son canal :
 *   local    tous les joueurs de la carte            canal de présence "map.{carte}"
 *   global   tous les joueurs connectés              canal privé "chat.global"
 *   group    les membres du groupe                   canal de présence "group.{id}"
 *   private  un joueur (message privé)               canal privé "character.{id}" (le destinataire, et l'expéditeur)
 */
class ChatMessage implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param string|int|array|null $key la carte, le n° du groupe, ou les n° des personnages (message privé) */
    public function __construct(public string $scope, public string|int|array|null $key, public array $message)
    {
    }

    public function broadcastOn(): array
    {
        return match ($this->scope) {
            'local' => [new PresenceChannel("map.{$this->key}")],
            'global' => [new PrivateChannel('chat.global')],
            'group' => [new PresenceChannel("group.{$this->key}")],
            'private' => array_map(fn ($id) => new PrivateChannel("character.{$id}"), (array) $this->key),
        };
    }

    public function broadcastAs(): string
    {
        return 'chat';
    }

    public function broadcastWith(): array
    {
        return $this->message;
    }
}
