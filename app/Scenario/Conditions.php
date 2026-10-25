<?php

namespace App\Scenario;

use App\Models\Character;
use App\Models\Item;
use App\Models\Trainer;
use App\Services\TimeOfDay;

/**
 * Étape 24 : vérifie les conditions d'un événement ("si drapeau x et badge 2 ...") pour un personnage.
 * Chaque partie est [négation, type, arguments] (voir ScriptParser::term) ; TOUTES doivent être vraies.
 */
class Conditions
{
    /** $run : l'événement en cours (pour "resultat" et les réponses aux questions / menus) */
    public static function check(array $terms, Character $character, ?array $run = null): bool
    {
        foreach ($terms as [$not, $type, $args]) {
            if (self::term($type, $args, $character, $run) === $not) {
                return false;
            }
        }

        return true;
    }

    private static function term(string $type, array $args, Character $character, ?array $run): bool
    {
        return match ($type) {
            'flag' => StoryState::hasFlag($character, $args[0]),
            'var' => self::compare(StoryState::var($character, $args[0]), $args[1], $args[2]),
            'badge' => $character->badges()->where('order', $args[0])->exists(),
            'badges' => $character->badges()->count() >= $args[0],
            'item' => ($item = Item::where('slug', $args[0])->first()) !== null && $character->quantityOf($item) >= $args[1],
            'money' => $character->money >= $args[0],
            'pokemon' => $character->party()->where('is_egg', false)->whereHas('species', fn ($q) => $q->where('slug', $args[0]))->exists(),
            'party' => $character->party()->count() >= $args[0],
            'trainer' => ($trainer = Trainer::where('slug', $args[0])->first()) !== null && $character->hasDefeated($trainer),
            'time' => in_array(TimeOfDay::period(), $args[0], true),
            'day' => in_array(TimeOfDay::now()->dayOfWeekIso, $args[0], true),
            'gender' => $character->gender === $args[0],
            'chance' => random_int(1, 100) <= $args[0],
            // Étape 27 : la Ligue (membres battus dans la tentative en cours) et le Panthéon (victoires contre le Maître)
            'league' => app(\App\Services\LeagueService::class)->progress($character) >= $args[0],
            'hallOfFame' => app(\App\Services\LeagueService::class)->wins($character) >= $args[0],
            'result' => in_array($run['result'] ?? null, $args[0], true),
            'choice' => ($run['choice'] ?? null) === $args[0],
            default => false,
        };
    }

    private static function compare(int $value, string $op, int $to): bool
    {
        return match ($op) {
            '=' => $value === $to,
            '!=' => $value !== $to,
            '<' => $value < $to,
            '<=' => $value <= $to,
            '>' => $value > $to,
            '>=' => $value >= $to,
            default => false,
        };
    }
}
