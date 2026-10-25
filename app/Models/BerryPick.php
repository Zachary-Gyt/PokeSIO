<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Étape 22 : la dernière cueillette d'un buisson à Baies par un joueur (le buisson repousse ensuite pour lui) */
class BerryPick extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['picked_at' => 'datetime'];
}
