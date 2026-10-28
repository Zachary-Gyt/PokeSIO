<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un groupe de joueurs (chat /g, liste des membres). Voir App\Services\GroupService. */
class Group extends Model
{
    protected $guarded = ['id'];

    public function leader()
    {
        return $this->belongsTo(Character::class, 'leader_id');
    }

    /** Les membres, du plus ancien au plus récent */
    public function members()
    {
        return $this->hasMany(Character::class)->orderBy('group_joined_at')->orderBy('id');
    }
}
