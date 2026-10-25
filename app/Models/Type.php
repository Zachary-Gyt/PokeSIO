<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un type : Feu, Eau, Plante...
 */
class Type extends Model
{
    // Données de référence remplies par l'import : on autorise toutes les colonnes sauf l'id
    protected $guarded = ['id'];

    /** Cache de la table des efficacités pour la durée de la requête : [attaquant][défenseur] => multiplicateur */
    private static ?array $chart = null;

    public function moves()
    {
        return $this->hasMany(Move::class);
    }

    /**
     * Multiplicateur de dégâts d'une attaque de ce type contre un ou deux types défenseurs.
     *
     *   $feu->effectivenessAgainst($plante)          // 2.0
     *   $feu->effectivenessAgainst($eau, $roche)     // 0.25
     *   $normal->effectivenessAgainst($spectre)      // 0.0
     */
    public function effectivenessAgainst(?Type ...$defenders): float
    {
        self::$chart ??= TypeEfficacy::all()
            ->groupBy('attacking_type_id')
            ->map(fn ($rows) => $rows->pluck('multiplier', 'defending_type_id')->map(fn ($m) => (float) $m))
            ->toArray();

        $multiplier = 1.0;
        foreach (array_filter($defenders) as $defender) {
            // Paire absente de la table = efficacité normale (x1)
            $multiplier *= self::$chart[$this->id][$defender->id] ?? 1.0;
        }

        return $multiplier;
    }
}
