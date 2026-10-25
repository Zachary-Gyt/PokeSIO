<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne de la table des faiblesses : "Eau contre Feu = x2".
 * Rarement utilisé directement : passe plutôt par Type::effectivenessAgainst().
 */
class TypeEfficacy extends Model
{
    public $timestamps = false; // la table n'a pas de created_at / updated_at

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['multiplier' => 'float'];
    }

    public function attackingType()
    {
        return $this->belongsTo(Type::class, 'attacking_type_id');
    }

    public function defendingType()
    {
        return $this->belongsTo(Type::class, 'defending_type_id');
    }
}
