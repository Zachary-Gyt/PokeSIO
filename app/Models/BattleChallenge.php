<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un défi PvP envoyé à un autre joueur, avant que le combat commence.
 */
class BattleChallenge extends Model
{
    /** Un défi non accepté expire au bout de ce délai */
    public const EXPIRES_AFTER_SECONDS = 60;

    protected $guarded = ['id'];

    public function challenger()
    {
        return $this->belongsTo(Character::class, 'challenger_id');
    }

    public function challenged()
    {
        return $this->belongsTo(Character::class, 'challenged_id');
    }

    public function battle()
    {
        return $this->belongsTo(Battle::class);
    }

    /** Défis encore valides */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending')
            ->where('created_at', '>=', now()->subSeconds(self::EXPIRES_AFTER_SECONDS));
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->status === 'pending' && $this->created_at->lt(now()->subSeconds(self::EXPIRES_AFTER_SECONDS)));
    }
}
