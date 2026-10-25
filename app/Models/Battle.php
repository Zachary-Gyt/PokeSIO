<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un combat (sauvage, contre un dresseur PNJ ou en PvP).
 * `state` contient tout l'état du combat en JSON ; le moteur de combat (étape 6) le lit et le met à jour.
 */
class Battle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'log' => 'array',
            'ended_at' => 'datetime',
        ];
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function opponentCharacter()
    {
        return $this->belongsTo(Character::class, 'opponent_character_id');
    }

    public function trainer()
    {
        return $this->belongsTo(Trainer::class);
    }

    public function winner()
    {
        return $this->belongsTo(Character::class, 'winner_character_id');
    }

    public function actions()
    {
        return $this->hasMany(BattleAction::class)->orderBy('turn');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /** Combats en cours d'un personnage (comme joueur 1 ou adversaire PvP) */
    public function scopeInvolving(Builder $query, Character $character): void
    {
        $query->where(fn ($q) => $q->where('character_id', $character->id)
            ->orWhere('opponent_character_id', $character->id));
    }

    public function isPvp(): bool
    {
        return $this->type === 'pvp';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Ajoute une ligne au journal : "Pikachu utilise Tonnerre !" */
    public function addLog(string $message): void
    {
        $log = $this->log ?? [];
        $log[] = ['turn' => $this->turn, 'message' => $message];
        $this->log = $log;
    }

    /** En PvP : les deux joueurs ont-ils choisi leur action pour le tour en cours ? */
    public function allActionsReceived(): bool
    {
        $expected = $this->isPvp() ? 2 : 1;

        return $this->actions()->where('turn', $this->turn)->count() >= $expected;
    }
}
