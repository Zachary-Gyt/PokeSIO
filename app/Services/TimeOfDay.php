<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Étape 23 : le JOUR et la NUIT, sur l'heure réelle (comme Or / Argent et Diamant / Perle).
 *
 *   matin 4 h - 10 h     jour 10 h - 20 h     nuit 20 h - 4 h      (config/game.php : "time")
 *
 * L'heure est celle du fuseau du jeu (Europe/Paris par défaut), pas celle du serveur.
 * Un administrateur peut forcer un moment pour tout le monde avec /heure matin|jour|nuit (puis /heure reel).
 */
class TimeOfDay
{
    public const PERIODS = ['morning' => 'matin', 'day' => 'jour', 'night' => 'nuit'];

    private const FORCED_KEY = 'time-of-day:forced';

    /** L'heure du jeu (fuseau config game.timezone) */
    public static function now(): Carbon
    {
        return now()->setTimezone(config('game.timezone', 'Europe/Paris'));
    }

    /** morning, day ou night */
    public static function period(): string
    {
        if ($forced = self::forced()) {
            return $forced;
        }
        $hour = (int) self::now()->format('G');
        $t = config('game.time', ['morning' => 4, 'day' => 10, 'night' => 20]);

        return match (true) {
            $hour >= $t['morning'] && $hour < $t['day'] => 'morning',
            $hour >= $t['day'] && $hour < $t['night'] => 'day',
            default => 'night',
        };
    }

    /** Pour les évolutions (PokéAPI ne connaît que "day" et "night") : le matin compte comme le jour */
    public static function dayOrNight(): string
    {
        return self::period() === 'night' ? 'night' : 'day';
    }

    public static function isNight(): bool
    {
        return self::period() === 'night';
    }

    /** L'heure "affichée" (pour la teinte de l'écran) : une heure typique si le moment est forcé */
    public static function clock(): array
    {
        $forced = self::forced();
        $now = self::now();
        $hour = $forced ? ['morning' => 7, 'day' => 13, 'night' => 23][$forced] : (int) $now->format('G');

        return ['hour' => $hour, 'minute' => $forced ? 0 : (int) $now->format('i'), 'period' => self::period(), 'forced' => (bool) $forced];
    }

    public static function forced(): ?string
    {
        $value = Cache::get(self::FORCED_KEY);

        return isset(self::PERIODS[$value]) ? $value : null;
    }

    /** Forcer un moment (null = heure réelle). Pendant 2 heures au plus. */
    public static function force(?string $period): void
    {
        $period ? Cache::put(self::FORCED_KEY, $period, now()->addHours(2)) : Cache::forget(self::FORCED_KEY);
    }
}
