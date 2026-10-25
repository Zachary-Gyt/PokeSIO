<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un badge d'arène.
 */
class Badge extends Model
{
    protected $guarded = ['id'];

    public function characters()
    {
        return $this->belongsToMany(Character::class)->withPivot('obtained_at');
    }

    /** Le Champion d'arène qui le donne */
    public function gymLeader()
    {
        return $this->hasOne(Trainer::class);
    }
}
