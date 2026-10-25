<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;

/**
 * Étape 15 : le PARC SAFARI.
 *
 * Une "partie" de Safari est gardée dans characters.story_flags['safari'] :
 *   ['balls' => 30, 'steps' => 500, 'maxSteps' => 500, 'exit' => ['map', 'x', 'y', 'facing']]
 * Elle commence chez le gardien (PNJ action = safari) et se termine :
 *   - quand le joueur quitte les cartes du Parc (propriété de carte "safari") ;
 *   - quand il n'a plus de pas, ou plus de Safari Balls : il est ramené à l'entrée.
 *
 * Dans le Parc, les Pokémon sauvages donnent un combat "safari" (BattleService) : pas de Pokémon
 * envoyé, seulement des Safari Balls, des Appâts et des Cailloux.
 */
class SafariService
{
    /** La partie en cours, ou null */
    public function current(Character $character): ?array
    {
        return $character->story_flags['safari'] ?? null;
    }

    /** Le joueur est-il en partie de Safari, sur une carte du Parc ? (combat "safari" au lieu de "wild") */
    public function active(Character $character, string $mapKey): bool
    {
        return $this->current($character) !== null && (bool) MapInfo::load($mapKey)?->property('safari');
    }

    /**
     * Le gardien : le joueur paie l'entrée. Renvoie ['to' => [map, x, y], 'safari' => [...], 'money' => ...].
     * $x, $y, $facing : où le joueur se tient devant le gardien (il y reviendra à la fin).
     */
    public function enter(Character $character, string $mapKey, int $x, int $y, string $facing, ?string $zone = null): array
    {
        $map = MapInfo::load($mapKey);
        $gate = $map?->safariGate();
        if (! $gate || $x >= $map->width() || $y >= $map->height()) {
            throw new InvalidAction('Il n\'y a pas d\'entrée du Parc Safari ici.');
        }
        // Plusieurs zones (to_map = "safari_kanto,safari_jotho...") : celle choisie par le joueur
        if ($zone !== null) {
            if (! in_array($zone, $gate['maps'] ?? [$gate['map']], true)) {
                throw new InvalidAction('Cette zone du Parc n\'existe pas.');
            }
            $gate['map'] = $zone;
        }
        unset($gate['maps']);
        if ($this->current($character)) {
            throw new InvalidAction('Vous êtes déjà dans le Parc Safari.');
        }
        if (! MapInfo::load($gate['map'])) {
            throw new InvalidAction("Le Parc Safari (carte {$gate['map']}) est introuvable.");
        }
        $price = (int) config('safari.price', 500);
        if (! $character->spendMoney($price)) {
            throw new InvalidAction('Vous n\'avez pas assez d\'argent.');
        }

        $steps = (int) config('safari.steps', 500);
        $state = [
            'balls' => (int) config('safari.balls', 30),
            'steps' => $steps,
            'maxSteps' => $steps,
            'exit' => ['map' => $mapKey, 'x' => $x, 'y' => $y, 'facing' => $facing],
        ];
        $character->setFlag('safari', $state);

        return ['to' => $gate, 'safari' => $this->publicState($state), 'money' => $character->money];
    }

    /**
     * Le joueur a fait $count pas dans le Parc. Renvoie l'état, ou ['ended' => 'steps', 'exit' => ...]
     * quand il n'y a plus de pas.
     */
    public function steps(Character $character, int $count): array
    {
        $state = $this->current($character);
        if (! $state) {
            return ['ended' => 'none'];
        }
        if ($state['maxSteps'] > 0) {
            $state['steps'] = max(0, $state['steps'] - max(0, min(50, $count)));
            if ($state['steps'] === 0) {
                return ['ended' => 'steps', 'exit' => $this->end($character)];
            }
            $character->setFlag('safari', $state);
        }

        return ['safari' => $this->publicState($state)];
    }

    /** Après un combat au Parc : on garde les Balls qui restent ; plus de Balls = fin de la partie */
    public function afterBattle(Character $character, array $battleState): ?array
    {
        $state = $this->current($character);
        if (! $state) {
            return null;
        }
        $state['balls'] = (int) ($battleState['safari']['balls'] ?? $state['balls']);
        if ($state['balls'] <= 0) {
            return ['ended' => 'balls', 'exit' => $this->end($character)];
        }
        $character->setFlag('safari', $state);

        return ['safari' => $this->publicState($state)];
    }

    /** Fin de la partie ; renvoie la place devant le gardien (ou null) */
    public function end(Character $character): ?array
    {
        $state = $this->current($character);
        $flags = $character->story_flags ?? [];
        unset($flags['safari']);
        $character->forceFill(['story_flags' => $flags])->save();

        return $state['exit'] ?? null;
    }

    /** Ce que le jeu affiche (le compteur en haut de l'écran) */
    public function publicState(?array $state): ?array
    {
        return $state ? ['balls' => $state['balls'], 'steps' => $state['steps'], 'maxSteps' => $state['maxSteps']] : null;
    }

    /** Ce que le moteur de combat doit savoir */
    public function battleState(Character $character, int $captureRate): array
    {
        return [
            'balls' => (int) ($this->current($character)['balls'] ?? 0),
            'rate' => $captureRate,
            'angry' => 0,
            'eating' => 0,
            'flee' => (float) config('safari.flee_per_speed', 0.4),
            'ball' => (int) config('safari.ball_bonus', 15),
        ];
    }
}
