<?php

namespace App\Battle;

/**
 * Générateur de nombres aléatoires "à graine" (algorithme mulberry32).
 *
 * Pourquoi pas random_int() ? Parce qu'avec une graine, le combat est REJOUABLE :
 * même graine + mêmes actions = exactement le même combat. Pratique pour déboguer
 * ("rejoue-moi le combat n° 42") et pour vérifier qu'un joueur n'a pas triché.
 *
 * L'état (un entier) est rangé dans le JSON du combat et avance à chaque tirage.
 */
class Rng
{
    public function __construct(private int $state)
    {
    }

    /** L'état à enregistrer pour reprendre le tirage plus tard */
    public function state(): int
    {
        return $this->state;
    }

    /** Un entier de 0 à 2^32 - 1 */
    public function next(): int
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = $this->mul($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + $this->mul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;

        return ($t ^ ($t >> 14)) & 0xFFFFFFFF;
    }

    /** Un entier entre $min et $max inclus */
    public function int(int $min, int $max): int
    {
        return $min + ($this->next() % ($max - $min + 1));
    }

    /** Vrai avec une probabilité de $percent % */
    public function chance(float $percent): bool
    {
        return $this->next() / 4294967296 * 100 < $percent;
    }

    /** Un élément au hasard d'une liste */
    public function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[$this->int(0, count($items) - 1)];
    }

    /** Multiplication sur 32 bits (comme Math.imul en JavaScript) */
    private function mul(int $a, int $b): int
    {
        $low = ($a & 0xFFFF) * $b;
        $high = (($a >> 16) & 0xFFFF) * $b;

        return ($low + (($high & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
