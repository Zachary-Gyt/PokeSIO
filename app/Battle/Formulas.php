<?php

namespace App\Battle;

/**
 * Les formules officielles des jeux (5e génération et suivantes), sans base de données :
 * stats, courbes d'expérience, dégâts, capture, fuite.
 */
class Formulas
{
    public const STATS = ['hp', 'attack', 'defense', 'sp_attack', 'sp_defense', 'speed'];

    /** Stat finale (même calcul que Pokemon::stat()) */
    public static function stat(string $stat, int $base, int $iv, int $ev, int $level, ?string $natureUp, ?string $natureDown): int
    {
        $core = intdiv((2 * $base + $iv + intdiv($ev, 4)) * $level, 100);

        if ($stat === 'hp') {
            return $core + $level + 10;
        }

        $nature = match ($stat) {
            $natureUp => 1.1,
            $natureDown => 0.9,
            default => 1.0,
        };

        return (int) floor(($core + 5) * $nature);
    }

    /** Les 6 stats d'un combattant, d'après ses données (base, ivs, evs, level, nature) */
    public static function stats(array $b): array
    {
        $stats = [];
        foreach (self::STATS as $s) {
            $stats[$s] = self::stat($s, $b['base'][$s], $b['ivs'][$s], $b['evs'][$s], $b['level'], $b['nature']['up'], $b['nature']['down']);
        }

        return $stats;
    }

    /** Expérience totale pour atteindre un niveau (identique à PokemonSpecies::experienceForLevel) */
    public static function experienceForLevel(string $growthRate, int $level): int
    {
        if ($level <= 1) {
            return 0;
        }
        $n = $level;
        $cube = $n ** 3;

        $xp = match ($growthRate) {
            'fast' => 4 * $cube / 5,
            'medium_fast' => $cube,
            'medium_slow' => 6 / 5 * $cube - 15 * $n ** 2 + 100 * $n - 140,
            'slow' => 5 * $cube / 4,
            'erratic' => match (true) {
                $n < 50 => $cube * (100 - $n) / 50,
                $n < 68 => $cube * (150 - $n) / 100,
                $n < 98 => $cube * intdiv(1911 - 10 * $n, 3) / 500,
                default => $cube * (160 - $n) / 100,
            },
            'fluctuating' => match (true) {
                $n < 15 => $cube * (intdiv($n + 1, 3) + 24) / 50,
                $n < 36 => $cube * ($n + 14) / 50,
                default => $cube * (intdiv($n, 2) + 32) / 50,
            },
            default => $cube,
        };

        return max(0, (int) floor($xp));
    }

    /**
     * Expérience gagnée en battant un Pokémon (formule "à l'échelle" de la 5e génération) :
     * battre un Pokémon de plus haut niveau rapporte plus, et l'XP est partagée entre les participants.
     */
    public static function experienceGain(int $baseExp, int $foeLevel, int $winnerLevel, int $participants, bool $trainerBattle): int
    {
        $a = $trainerBattle ? 1.5 : 1.0;
        $scale = ((2 * $foeLevel + 10) ** 2.5) / (($foeLevel + $winnerLevel + 10) ** 2.5);

        return (int) floor(($a * $baseExp * $foeLevel) / (5 * max(1, $participants)) * $scale + 1);
    }

    /** Multiplicateur d'un niveau de stat (-6 à +6) : +1 = x1.5, +2 = x2, -1 = x0.67... */
    public static function stageMultiplier(int $stage): float
    {
        return $stage >= 0 ? (2 + $stage) / 2 : 2 / (2 - $stage);
    }

    /** Multiplicateur de précision / esquive (-6 à +6) : +1 = x1.33, -1 = x0.75... */
    public static function accuracyMultiplier(int $stage): float
    {
        $stage = max(-6, min(6, $stage));

        return $stage >= 0 ? (3 + $stage) / 3 : 3 / (3 - $stage);
    }

    /** Chance de coup critique (en %) selon le niveau de critique (0 = normal, 3+ = toujours) */
    public static function critChance(int $stage): float
    {
        return match (true) {
            $stage <= 0 => 100 / 24,
            $stage === 1 => 100 / 8,
            $stage === 2 => 50,
            default => 100,
        };
    }

    /**
     * Dégâts de base, AVANT les multiplicateurs (critique, aléatoire, STAB, type, brûlure).
     */
    public static function baseDamage(int $level, int $power, int $attack, int $defense): int
    {
        $levelFactor = intdiv(2 * $level, 5) + 2;

        return intdiv(intdiv($levelFactor * $power * $attack, max(1, $defense)), 50) + 2;
    }

    /**
     * Capture (5e génération et suivantes). Renvoie le nombre de secousses (0 à 3) et si c'est réussi.
     * $ballBonus : x1 Poké Ball, x1.5 Super Ball, x2 Hyper Ball, 255 = Master Ball.
     */
    public static function capture(Rng $rng, int $maxHp, int $hp, int $captureRate, float $ballBonus, string $status): array
    {
        if ($ballBonus >= 255) {
            return ['shakes' => 3, 'caught' => true];
        }

        $statusBonus = match ($status) {
            'sleep', 'freeze' => 2.5,
            'paralysis', 'poison', 'bad_poison', 'burn' => 1.5,
            default => 1.0,
        };

        $x = ((3 * $maxHp - 2 * $hp) * $captureRate * $ballBonus) / (3 * $maxHp) * $statusBonus;

        if ($x >= 255) {
            return ['shakes' => 3, 'caught' => true];
        }

        // 4 tests successifs : chaque test réussi = une secousse de la Ball (le 4e = capture)
        $threshold = (int) floor(65536 / ((255 / max(1, $x)) ** 0.1875));
        $passed = 0;
        while ($passed < 4 && $rng->int(0, 65535) < $threshold) {
            $passed++;
        }

        return ['shakes' => min(3, $passed), 'caught' => $passed === 4];
    }

    /** Fuite (3e génération et suivantes) : plus on est rapide et plus on essaie, plus c'est facile */
    public static function canEscape(Rng $rng, int $playerSpeed, int $foeSpeed, int $attempts): bool
    {
        if ($playerSpeed >= $foeSpeed) {
            return true;
        }
        $odds = intdiv($playerSpeed * 128, max(1, $foeSpeed)) + 30 * $attempts;

        return $odds > 255 || $rng->int(0, 255) < $odds;
    }
}
