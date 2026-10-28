<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une invitation dans un groupe (en attente, acceptée, refusée, expirée) */
class GroupInvitation extends Model
{
    protected $guarded = ['id'];

    public function inviter()
    {
        return $this->belongsTo(Character::class, 'inviter_id');
    }

    public function invitee()
    {
        return $this->belongsTo(Character::class, 'invitee_id');
    }
}
