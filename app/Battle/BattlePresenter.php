<?php

namespace App\Battle;

/**
 * Ce que le NAVIGATEUR a le droit de voir du combat.
 *
 * L'état complet reste sur le serveur : IV, EV, attaques de l'adversaire, PV exacts de l'adversaire...
 * Le joueur ne voit que ce qu'il verrait dans le jeu (la barre de PV adverse, pas le nombre).
 */
class BattlePresenter
{
    /**
     * L'état du combat, version "écran".
     * $viewer : 'player' (joueur 1) ou 'foe' (joueur 2 d'un combat entre joueurs : on retourne l'état)
     */
    public static function view(array $state, array $extra = [], string $viewer = 'player'): array
    {
        if ($viewer === 'foe') {
            $state = self::mirror($state);
        }
        $player = $state['player'];
        $foe = $state['foe'];
        $active = $player['party'][$player['active']] ?? ['volatile' => []];

        $view = [
            'type' => $state['type'],
            'phase' => $state['phase'],
            'turn' => $state['turn'],
            'result' => $state['result'],
            'background' => $state['background'],
            'weather' => $state['weather']['type'] ?? null,   // étape 23
            'base' => $state['base'] ?? $state['background'],
            'player' => [
                'name' => $player['name'],
                'gender' => $player['gender'],
                'active' => $player['active'],
                'party' => array_map(fn ($b) => self::playerBattler($b), $player['party']),
            ],
            'foe' => [
                'name' => $foe['name'],
                'class' => $foe['class'] ?? null,
                'music' => $foe['music'] ?? null,   // étape 12 : 'trainer', 'leader' ou 'title'
                'sprite' => $foe['sprite'] ?? null,
                'active' => self::foeBattler($foe['party'][$foe['active']]),
                // Les Balls de l'équipe adverse (dresseurs) : ok / statut / K.O.
                'lineup' => array_map(fn ($b) => self::lineupState($b), $foe['party']),
            ],
            // Attaque imposée (préparation, repos, Mania) : pas de menu, on envoie "continue"
            'locked' => ! empty($active['volatile']['locked']),
            'trapped' => ! empty($active['volatile']['trapped']),
            'canRun' => $state['type'] === 'wild',
        ];

        if ($state['type'] === 'pvp') {
            // Combat entre joueurs : le Pokémon adverse est montré comme celui d'un dresseur,
            // et "mustSwitch" dit si CE joueur doit envoyer un Pokémon (après un K.O.)
            $view['foe']['class'] = null;
            $view['mustSwitch'] = ! empty($state['needSwitch']['player']);
            $view['canRun'] = false;
        }

        if ($state['type'] === 'safari') {
            // Étape 15 : Parc Safari, les Safari Balls qui restent (boîte en bas à droite)
            $view['safari'] = ['balls' => $state['safari']['balls'] ?? 0];
            $view['canRun'] = true;
        }

        if ($state['phase'] === 'learn_move' && ! empty($state['pendingMoves'])) {
            $pending = $state['pendingMoves'][0];
            $view['learn'] = [
                'index' => $pending['index'],
                'move' => self::move($pending['move'] + ['pp' => $pending['move']['maxPp']]),
            ];
        }

        return $view + $extra;
    }

    /** Les événements, nettoyés pour le navigateur ($viewer : voir view()) */
    public static function events(array $events, string $viewer = 'player'): array
    {
        return array_map(function (array $e) use ($viewer) {
            // Messages : la version du joueur 2 (textFoe), et les jetons "Le Pikachu ennemi" de chacun
            if ($e['type'] === 'message') {
                if ($viewer === 'foe' && isset($e['textFoe'])) {
                    $e['text'] = $e['textFoe'];
                }
                unset($e['textFoe']);
                $e['text'] = BattleText::resolve($e['text'], $viewer);
            }
            if ($viewer === 'foe') {
                if (isset($e['side'])) {
                    $e['side'] = $e['side'] === 'player' ? 'foe' : 'player';
                }
                if ($e['type'] === 'end') {
                    $e['result'] = self::invertResult($e['result']);
                }
            }

            if (isset($e['battler'])) {
                $e['battler'] = $e['side'] === 'player' ? self::playerBattler($e['battler']) : self::foeBattler($e['battler']);
            }

            // PV de l'adversaire : seulement la proportion (la barre), pas le nombre exact
            if ($e['type'] === 'hp' && $e['side'] === 'foe') {
                $e['ratio'] = $e['maxHp'] > 0 ? $e['hp'] / $e['maxHp'] : 0;
                unset($e['hp'], $e['maxHp']);
            }

            return $e;
        }, $events);
    }

    /** L'état vu par le joueur 2 : les deux côtés sont échangés */
    public static function mirror(array $state): array
    {
        [$state['player'], $state['foe']] = [$state['foe'], $state['player']];
        if (isset($state['needSwitch'])) {
            $state['needSwitch'] = ['player' => $state['needSwitch']['foe'] ?? false, 'foe' => $state['needSwitch']['player'] ?? false];
        }
        if ($state['result'] !== null) {
            $state['result'] = self::invertResult($state['result']);
        }

        return $state;
    }

    public static function invertResult(?string $result): ?string
    {
        return match ($result) {
            'win' => 'loss',
            'loss' => 'win',
            'forfeit_player' => 'forfeit_foe',
            'forfeit_foe' => 'forfeit_player',
            default => $result,
        };
    }

    public static function playerBattler(array $b): array
    {
        return [
            'id' => $b['id'],
            'name' => $b['name'],
            'speciesName' => $b['speciesName'],
            'level' => $b['level'],
            'gender' => $b['gender'],
            'shiny' => $b['shiny'],
            'types' => $b['types'],
            'hp' => $b['hp'],
            'maxHp' => $b['stats']['hp'],
            'status' => $b['status'],
            'stats' => $b['stats'],
            'exp' => $b['exp'],
            'levelExp' => Formulas::experienceForLevel($b['growth'], $b['level']),
            'nextLevelExp' => $b['level'] >= 100 ? $b['exp'] : Formulas::experienceForLevel($b['growth'], $b['level'] + 1),
            'moves' => array_map(fn ($m) => self::move($m), $b['moves']),
            'sprites' => $b['sprites'],
        ];
    }

    public static function foeBattler(array $b): array
    {
        return [
            'name' => $b['name'],
            'speciesName' => $b['speciesName'],
            'level' => $b['level'],
            'gender' => $b['gender'],
            'shiny' => $b['shiny'],
            'ratio' => $b['stats']['hp'] > 0 ? $b['hp'] / $b['stats']['hp'] : 0,
            'status' => $b['status'],
            'owned' => $b['owned'] ?? false,
            'sprites' => ['front' => $b['sprites']['front'], 'cry' => $b['sprites']['cry'] ?? null],
        ];
    }

    private static function move(array $m): array
    {
        return [
            'name' => $m['name'],
            'type' => $m['type'],
            'category' => $m['category'],
            'power' => $m['power'],
            'accuracy' => $m['accuracy'],
            'pp' => $m['pp'],
            'maxPp' => $m['maxPp'],
            'hm' => (bool) ($m['hm'] ?? false),   // étape 19
        ];
    }

    private static function lineupState(array $b): string
    {
        return match (true) {
            $b['hp'] <= 0 => 'faint',
            $b['status'] !== 'none' => 'status',
            default => 'ok',
        };
    }
}
