<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne du journal de modération : chaque commande d'un administrateur y est notée.
 *
 *   AdminAction::record($admin, 'ban', $character, '2h — insultes');
 */
class AdminAction extends Model
{
    protected $guarded = ['id'];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function targetCharacter()
    {
        return $this->belongsTo(Character::class, 'target_character_id');
    }

    public static function record(User $admin, string $action, Character|User|null $target = null, ?string $details = null): self
    {
        return self::create([
            'admin_id' => $admin->id,
            'target_user_id' => $target instanceof Character ? $target->user_id : $target?->id,
            'target_character_id' => $target instanceof Character ? $target->id : null,
            'action' => $action,
            'details' => $details !== null ? mb_substr($details, 0, 500) : null,
        ]);
    }
}
