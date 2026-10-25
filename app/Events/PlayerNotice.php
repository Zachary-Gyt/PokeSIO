<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un message en direct pour UN personnage, envoyé par Reverb sur son canal privé "character.{id}".
 *
 *   PlayerNotice::send($character->id, 'invitation', ['id' => 3, 'kind' => 'trade', 'from' => 'Sacha']);
 *
 * Types utilisés par le jeu (public/js/game/net/Multiplayer.js) :
 *   invitation      quelqu'un propose un échange ou un combat
 *   answer          réponse à MON invitation (acceptée / refusée)
 *   trade           l'échange en cours a changé (offre, confirmation, annulation, terminé)
 *   pvp             le combat entre joueurs a avancé : il y a de nouveaux événements à lire
 *   title_lost      un autre joueur m'a pris le titre de Tenant du titre
 *
 * ShouldBroadcastNow : envoyé tout de suite, sans file d'attente (pas besoin de "queue:work").
 * Le message reste PETIT (Reverb refuse au-delà de 10 Ko) : le jeu relit les détails par une requête.
 */
class PlayerNotice implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $characterId, public string $type, public array $data = [])
    {
    }

    /** Envoie la notification ; une erreur de connexion à Reverb ne doit jamais casser le jeu */
    public static function send(int $characterId, string $type, array $data = []): void
    {
        try {
            broadcast(new self($characterId, $type, $data));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("character.{$this->characterId}")];
    }

    public function broadcastAs(): string
    {
        return 'notice';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type] + $this->data;
    }
}
