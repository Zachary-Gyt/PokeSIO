<?php

namespace App\Scenario;

use App\Models\Character;

/**
 * Étape 24 : les DRAPEAUX et VARIABLES du scénario d'un personnage, rangés dans characters.story_flags :
 *
 *   story_flags['scenario'] = [
 *       'flags' => ['potions_recues' => true, ...],   // drapeaux levés
 *       'vars'  => ['visites' => 3, ...],             // compteurs
 *       'run'   => [...],                             // l'événement en cours (EventRunner), ou absent
 *   ]
 *
 * (Les autres clés de story_flags, comme 'gifts' ou 'picked', appartiennent aux étapes précédentes.)
 */
class StoryState
{
    public static function flags(Character $character): array
    {
        return $character->story_flags['scenario']['flags'] ?? [];
    }

    public static function vars(Character $character): array
    {
        return $character->story_flags['scenario']['vars'] ?? [];
    }

    public static function hasFlag(Character $character, string $name): bool
    {
        return ! empty(self::flags($character)[$name]);
    }

    public static function var(Character $character, string $name): int
    {
        return (int) (self::vars($character)[$name] ?? 0);
    }

    /** Lève (true) ou baisse (false) un drapeau. Ne sauvegarde pas : appeler $character->save() ensuite. */
    public static function setFlag(Character $character, string $name, bool $value): void
    {
        $flags = $character->story_flags ?? [];
        if ($value) {
            $flags['scenario']['flags'][$name] = true;
        } else {
            unset($flags['scenario']['flags'][$name]);
        }
        $character->story_flags = $flags;
    }

    public static function setVar(Character $character, string $name, int $value): void
    {
        $flags = $character->story_flags ?? [];
        $flags['scenario']['vars'][$name] = $value;
        $character->story_flags = $flags;
    }

    public static function run(Character $character): ?array
    {
        return $character->story_flags['scenario']['run'] ?? null;
    }

    public static function setRun(Character $character, ?array $run): void
    {
        $flags = $character->story_flags ?? [];
        if ($run === null) {
            unset($flags['scenario']['run']);
        } else {
            $flags['scenario']['run'] = $run;
        }
        $character->story_flags = $flags;
    }
}
