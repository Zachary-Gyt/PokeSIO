<?php

namespace App\Services;

use App\Battle\BattleEngine;
use App\Battle\BattleFactory;
use App\Battle\BattlePresenter;
use App\Battle\InvalidAction;
use App\Events\PlayerNotice;
use App\Models\Battle;
use App\Models\BattleAction;
use App\Models\Character;
use App\Models\Pokemon;
use Illuminate\Support\Facades\DB;

/**
 * Les combats ENTRE JOUEURS (PvP).
 *
 * Même moteur que les autres combats (BattleEngine), avec deux différences :
 *   - chaque joueur choisit son action ; le tour se joue quand LES DEUX ont choisi
 *     (les choix attendent dans la table battle_actions) ;
 *   - c'est un combat AMICAL : les équipes sont soignées au départ, et rien n'est écrit sur
 *     les vrais Pokémon à la fin (ni PV, ni PP, ni expérience). Pas d'objets, pas de fuite.
 *
 * Dans battles.state : "player" = le joueur 1 (celui qui a lancé le défi), "foe" = le joueur 2.
 * Chaque joueur voit le combat de SON point de vue (BattlePresenter, $viewer = 'player' ou 'foe').
 *
 * Après chaque étape, les deux joueurs reçoivent la notification "pvp" (Reverb) et relisent
 * les nouveaux événements avec state() ; sans Reverb, le jeu interroge le serveur toutes les 3 s.
 */
class PvpService
{
    /** Un joueur qui ne choisit rien pendant ce temps peut être déclaré forfait par l'autre */
    public const TIMEOUT_SECONDS = 90;

    public function __construct(private BattleFactory $factory)
    {
    }

    /** Crée le combat : le défi vient d'être accepté */
    public function start(Character $p1, Character $p2): Battle
    {
        $seed = random_int(1, 2_000_000_000);

        $state = [
            'version' => 1,
            'type' => 'pvp',
            'phase' => 'command',
            'turn' => 0,
            'rng' => $seed,
            'background' => 'field',
            'base' => 'field',
            'player' => $this->side($p1),
            'foe' => $this->side($p2),
            'participants' => [],
            'pendingMoves' => [],
            'seen' => [],
            'result' => null,
            'rewards' => [],
            'needSwitch' => ['player' => false, 'foe' => false],
        ];

        $engine = new BattleEngine($state, $this->factory->typeChart());
        $events = $engine->start();
        $state = $engine->state();

        return Battle::create([
            'type' => 'pvp',
            'status' => 'active',
            'character_id' => $p1->id,
            'opponent_character_id' => $p2->id,
            'seed' => $seed,
            'turn' => 0,
            'state' => $state,
            'log' => [['seq' => 0, 'events' => $events]],
        ]);
    }

    /** Un camp : le joueur et son équipe, soignée (combat amical) */
    private function side(Character $character): array
    {
        $party = $character->battleParty()->with('species.primaryType', 'species.secondaryType', 'nature', 'moves.move.type')->get();
        $battlers = $party->map(function (Pokemon $p) use ($character) {
            $b = $this->factory->fromOwned($p, (int) $character->trainer_id);
            $b['hp'] = $b['stats']['hp'];
            $b['status'] = 'none';
            $b['sleepTurns'] = 0;
            foreach ($b['moves'] as &$move) {
                $move['pp'] = $move['maxPp'];
            }

            return $b;
        })->values()->all();

        return [
            'name' => $character->name,
            'gender' => $character->gender,
            'class' => null,
            'sprite' => "player_{$character->avatarKey()}",   // sprites/trainers/battle/player_red.png (étape 17)
            'characterId' => $character->id,
            'active' => 0,
            'party' => $battlers,
        ];
    }

    /** Le côté d'un personnage dans ce combat : 'player' (joueur 1) ou 'foe' (joueur 2) */
    public function sideOf(Battle $battle, Character $character): string
    {
        return match ($character->id) {
            $battle->character_id => 'player',
            $battle->opponent_character_id => 'foe',
            default => throw new InvalidAction('Vous ne participez pas à ce combat.'),
        };
    }

    /**
     * Ce que le joueur doit afficher : la vue du combat, et les événements qu'il n'a pas encore vus
     * ($since = numéro du dernier lot déjà joué, -1 pour tout recevoir).
     */
    public function state(Battle $battle, Character $character, int $since = -1): array
    {
        $side = $this->sideOf($battle, $character);
        $events = [];
        foreach ($battle->log ?? [] as $entry) {
            if (($entry['seq'] ?? 0) > $since) {
                $events = array_merge($events, $entry['events']);
            }
        }

        return [
            'events' => BattlePresenter::events($events, $side),
            'battle' => $this->view($battle, $character),
        ];
    }

    public function view(Battle $battle, Character $character): array
    {
        $side = $this->sideOf($battle, $character);
        $state = $battle->state;
        $other = $side === 'player' ? 'foe' : 'player';
        $code = $this->stepCode($state);
        $mine = BattleAction::where('battle_id', $battle->id)->where('turn', $code)->where('character_id', $character->id)->exists();
        $last = $battle->updated_at;

        // J'ai déjà choisi (ou je n'ai rien à choisir) : j'attends l'autre joueur
        $waiting = $battle->isActive() && ($mine || ($state['phase'] === 'switch' && empty($state['needSwitch'][$side])));

        return BattlePresenter::view($state, [
            'id' => $battle->id,
            'pvp' => [
                'seq' => count($battle->log ?? []) - 1,
                'opponent' => $state[$other]['name'],
                'waiting' => $waiting,
                // L'autre ne répond plus depuis longtemps : je peux le déclarer forfait
                'canClaim' => $waiting && $last && $last->lt(now()->subSeconds(self::TIMEOUT_SECONDS)),
            ],
        ], $side);
    }

    /**
     * Le choix d'un joueur. $input : ['type' => 'move'|'switch'|'continue', 'slot', 'party']
     * Le tour est joué dès que tous les choix attendus sont arrivés.
     */
    public function act(Battle $battle, Character $character, array $input): bool
    {
        $result = DB::transaction(function () use ($battle, $character, $input) {
            $battle = Battle::whereKey($battle->id)->lockForUpdate()->firstOrFail();
            if (! $battle->isActive()) {
                throw new InvalidAction('Ce combat est terminé.');
            }
            $side = $this->sideOf($battle, $character);
            $state = $battle->state;
            $code = $this->stepCode($state);

            $action = match ($input['type'] ?? null) {
                'move' => ['type' => 'move', 'slot' => (int) ($input['slot'] ?? -1)],
                'switch' => ['type' => 'switch', 'party' => (int) ($input['party'] ?? -1)],
                'continue' => ['type' => 'continue'],
                'stay' => ['type' => 'stay'],   // style « Choix » : garder son Pokémon
                default => throw new InvalidAction('Action impossible dans un combat entre joueurs.'),
            };

            if ($state['phase'] === 'switch') {
                $need = $state['needSwitch'][$side] ?? false;
                if (! $need || ! ($action['type'] === 'switch' || ($action['type'] === 'stay' && $need === 'optional'))) {
                    throw new InvalidAction('Attendez que l\'autre joueur choisisse son Pokémon.');
                }
            } elseif ($state['phase'] !== 'command') {
                throw new InvalidAction('Ce n\'est pas le moment.');
            }

            // Vérification immédiate (plus de PP, Pokémon K.O...) : on ne fait pas attendre l'autre pour rien
            (new BattleEngine($state, $this->factory->typeChart()))->checkAction($action, $side);

            BattleAction::updateOrCreate(
                ['battle_id' => $battle->id, 'turn' => $code, 'character_id' => $character->id],
                ['action' => $action['type'] === 'switch' ? 'switch' : 'move', 'payload' => $action],
            );

            return $this->resolveIfReady($battle);
        });

        $this->notify($battle);

        return $result;
    }

    /** Joue le tour si tous les choix attendus sont là */
    private function resolveIfReady(Battle $battle): bool
    {
        $state = $battle->state;
        $code = $this->stepCode($state);
        $actions = BattleAction::where('battle_id', $battle->id)->where('turn', $code)->get()->keyBy('character_id');
        $p1 = $actions[$battle->character_id]->payload ?? null;
        $p2 = $actions[$battle->opponent_character_id]->payload ?? null;

        $engine = new BattleEngine($state, $this->factory->typeChart());
        if ($state['phase'] === 'switch') {
            // Tous ceux qui ont un choix à faire (remplacer, ou changer en style « Choix ») doivent avoir répondu
            if ((! empty($state['needSwitch']['player']) && ! $p1) || (! empty($state['needSwitch']['foe']) && ! $p2)) {
                return false;
            }
            $events = $engine->pvpSwitch(['player' => $p1['party'] ?? null, 'foe' => $p2['party'] ?? null]);
        } else {
            if (! $p1 || ! $p2) {
                return false;
            }
            $events = $engine->actPvp($p1, $p2);
        }

        $this->save($battle, $engine->state(), $events);

        return true;
    }

    /** Abandon : l'autre joueur gagne */
    public function forfeit(Battle $battle, Character $character, bool $timeout = false): void
    {
        DB::transaction(function () use ($battle, $character, $timeout) {
            $battle = Battle::whereKey($battle->id)->lockForUpdate()->firstOrFail();
            if (! $battle->isActive()) {
                return;
            }
            $side = $this->sideOf($battle, $character);
            $state = $battle->state;
            $name = $state[$side]['name'];

            $events = [
                ['type' => 'message', 'text' => $timeout ? "{$name} ne répond plus... Forfait !" : "{$name} abandonne le combat !", 'pause' => true],
            ];
            $state['result'] = $side === 'player' ? 'loss' : 'win';
            $state['phase'] = 'ended';
            $events[] = ['type' => 'end', 'result' => $state['result']];

            $this->save($battle, $state, $events);
        });

        $this->notify($battle);
    }

    /** L'adversaire ne joue plus depuis TIMEOUT_SECONDS : il est déclaré forfait */
    public function claimVictory(Battle $battle, Character $character): void
    {
        $view = $this->view($battle->fresh(), $character);
        if (! $view['pvp']['canClaim']) {
            throw new InvalidAction('Votre adversaire a encore le temps de choisir.');
        }
        $other = $this->sideOf($battle, $character) === 'player' ? $battle->opponentCharacter : $battle->character;
        $this->forfeit($battle, $other, timeout: true);
    }

    private function save(Battle $battle, array $state, array $events): void
    {
        $log = $battle->log ?? [];
        $log[] = ['seq' => count($log), 'turn' => $state['turn'], 'events' => $events];
        $battle->forceFill(['state' => $state, 'turn' => $state['turn'], 'log' => $log]);

        if ($state['phase'] === 'ended') {
            $winner = match ($state['result']) {
                'win' => $battle->character_id,
                'loss' => $battle->opponent_character_id,
                default => null,
            };
            $battle->forceFill([
                'status' => 'finished',
                'result' => $state['result'] === 'draw' ? 'draw' : $state['result'],
                'winner_character_id' => $winner,
                'ended_at' => now(),
            ]);
        }
        $battle->save();
    }

    /** Les deux joueurs relisent le combat */
    private function notify(Battle $battle): void
    {
        $battle->refresh();
        $seq = count($battle->log ?? []) - 1;
        foreach ([$battle->character_id, $battle->opponent_character_id] as $id) {
            PlayerNotice::send($id, 'pvp', ['battleId' => $battle->id, 'seq' => $seq]);
        }
    }

    /**
     * Numéro de l'étape en cours, pour ranger les choix dans battle_actions :
     * tour N (choix des attaques) = 2N + 2, remplacement après un K.O. au tour N = 2N + 1.
     */
    private function stepCode(array $state): int
    {
        return $state['phase'] === 'switch' ? 2 * $state['turn'] + 1 : 2 * $state['turn'] + 2;
    }
}
