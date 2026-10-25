<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un message du chat, envoyé à tous les joueurs de la même carte (canal de présence "map.{carte}").
 * Le texte a déjà été vérifié et nettoyé par ChatController.
 */
class ChatMessage implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $map, public array $message)
    {
    }

    public function broadcastOn(): array
    {
        return [new PresenceChannel("map.{$this->map}")];
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
