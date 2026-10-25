<?php

namespace App\Battle;

/**
 * LE MOTEUR DE COMBAT (combat simple : un Pokémon de chaque côté).
 *
 * Il ne connaît ni Laravel ni la base de données : il reçoit l'état du combat (un tableau,
 * enregistré en JSON dans battles.state), applique UNE action du joueur, et renvoie la liste
 * des "événements" que le navigateur doit animer (messages, barres de PV, K.O., Ball...).
 *
 *   $engine = new BattleEngine($state, $typeChart);
 *   $events = $engine->act(['type' => 'move', 'slot' => 0]);
 *   $state  = $engine->state();     // à réenregistrer
 *
 * Tout le hasard passe par $this->rng (graine enregistrée dans l'état) : un combat est rejouable.
 *
 * Phases ($state['phase']) :
 *   command     le joueur choisit : attaque, sac, Pokémon ou fuite
 *   switch      son Pokémon est K.O. : il DOIT en envoyer un autre
 *   learn_move  un Pokémon veut apprendre une 5e attaque : laquelle oublier ?
 *   ended       combat terminé ($state['result'] : win, loss, fled, caught, draw)
 *
 * Étape 15 : Parc Safari (type "safari") : le joueur n'envoie pas de Pokémon. Il lance des Safari Balls,
 * un Appât ou un Caillou ; le Pokémon peut s'enfuir à chaque tour (voir safariTurn()).
 *
 * Combat entre deux joueurs (type "pvp") : "player" = le joueur 1, "foe" = le joueur 2.
 *   $events = $engine->actPvp($actionJoueur1, $actionJoueur2);   // phase command
 *   $events = $engine->pvpSwitch(['player' => 2, 'foe' => null]); // phase switch (après un K.O.)
 * Les messages contiennent des jetons (BattleText::resolve) et parfois un texte "textFoe"
 * pour le joueur 2 : BattlePresenter les traduit pour chacun.
 */
class BattleEngine
{
    // Étape 23 : talents et météo (app/Battle/Concerns/AbilitiesAndWeather.php)
    use Concerns\AbilitiesAndWeather;

    // Cibles PokéAPI qui désignent le lanceur lui-même (Danse-Lames, Repos...)
    private const SELF_TARGETS = ['user', 'users-field', 'user-or-ally', 'user-and-allies', 'all-allies', 'ally'];

    // Attaques en deux tours : 1er tour = préparation (message), 2e tour = attaque
    private const CHARGE_MOVES = [
        'solar-beam' => 'absorbe la lumière !',
        'fly' => "s'envole !",
        'dig' => 'creuse un trou !',
        'skull-bash' => 'baisse la tête !',
        'sky-attack' => "est entouré d'une lumière intense !",
        'razor-wind' => 'se prépare à lancer une bourrasque !',
    ];

    // Pendant la préparation de Vol et Tunnel, le Pokémon est hors d'atteinte
    private const SEMI_INVULNERABLE = ['fly', 'dig'];

    // Attaques qui obligent à se reposer au tour suivant
    private const RECHARGE_MOVES = ['hyper-beam', 'giga-impact', 'blast-burn', 'hydro-cannon', 'frenzy-plant'];

    // Attaques "furie" : 2 ou 3 tours d'affilée, puis le Pokémon devient confus
    private const RAMPAGE_MOVES = ['thrash', 'petal-dance', 'outrage'];

    // Poudres : sans effet sur les Pokémon Plante (6e génération et suivantes)
    private const POWDER_MOVES = ['stun-spore', 'sleep-powder', 'poison-powder', 'spore'];

    // Attaques qui mettent le lanceur K.O.
    private const SELF_DESTRUCT = ['explosion', 'self-destruct'];

    // Attaques qui échouent toujours ici (elles dépendent de la dernière attaque reçue)
    private const UNSUPPORTED_DAMAGE = ['counter', 'mirror-coat', 'bide', 'metal-burst', 'natural-gift', 'fling'];

    // Attaques à dégâts fixes (leur puissance est "null" dans PokéAPI)
    private const FIXED_DAMAGE = ['seismic-toss', 'night-shade', 'dragon-rage', 'sonic-boom', 'super-fang', 'psywave', 'endeavor'];

    private array $state;
    private Rng $rng;
    private BattleText $text;
    private array $events = [];
    private ?int $speedTie = null;

    /** Étape 14 : la Vive Griffe s'est déclenchée ce tour-ci pour ce camp ('player' => true) */
    private array $quick = [];

    /** L'objet utilisé par le joueur a-t-il été consommé ? (lu par Laravel pour le retirer du sac) */
    public bool $itemConsumed = false;

    /**
     * $typeChart : [type attaquant][type défenseur] => multiplicateur (absent = x1)
     */
    public function __construct(array $state, private array $typeChart)
    {
        $this->state = $state;
        $this->rng = new Rng($state['rng']);
        $this->text = new BattleText($state['type']);
    }

    public function state(): array
    {
        $this->state['rng'] = $this->rng->state();

        return $this->state;
    }

    // =====================================================================
    // Début du combat
    // =====================================================================

    /** Événements d'ouverture : apparition de l'adversaire, envoi du premier Pokémon du joueur */
    public function start(): array
    {
        $this->events = [];
        $foe = &$this->active('foe');

        if ($this->state['type'] === 'pvp') {
            $p1 = $this->state['player']['name'];
            $p2 = $this->state['foe']['name'];
            $this->sayPov("{$p2} veut se battre !", "{$p1} veut se battre !", pause: true);
            $this->sayPov("{$p2} envoie {$foe['name']} !", "Go ! {$foe['name']} !");
            $this->emitSendOut('foe');
            $player = $this->active('player');
            $this->sayPov("Go ! {$player['name']} !", "{$p1} envoie {$player['name']} !");
            $this->emitSendOut('player');
            $this->entryAbilitiesAtStart();   // étape 23 : Intimidation, Sécheresse...
            $this->state['participants'] = [];
            $this->state['needSwitch'] = ['player' => false, 'foe' => false];
            $this->state['phase'] = 'command';

            return $this->events;
        }

        if ($this->state['type'] === 'safari') {
            // Étape 15 : Parc Safari, personne n'est envoyé au combat
            $this->emitSendOut('foe');
            $this->say($this->text->wildAppears($foe), pause: true);
            $this->startWeather();   // étape 23
            $this->state['participants'] = [];
            $this->state['phase'] = 'command';

            return $this->events;
        }

        if ($this->state['type'] === 'wild') {
            $this->emitSendOut('foe');
            $this->say($this->text->wildAppears($foe), pause: true);
        } else {
            $trainer = $this->trainerName();
            $this->say("Vous êtes défié par {$trainer} !", pause: true);
            $this->say("{$trainer} envoie {$foe['name']} !");
            $this->emitSendOut('foe');
        }

        $this->say("Go ! {$this->active('player')['name']} !");
        $this->emitSendOut('player');
        $this->entryAbilitiesAtStart();   // étape 23 : météo de la carte, Intimidation, Sécheresse...

        $this->state['participants'] = [$this->state['player']['active']];
        // Étape 14 : tous les Pokémon du joueur envoyés au combat (Pièce Rune)
        $this->state['entered'] = [$this->state['player']['active']];
        $this->state['phase'] = 'command';

        return $this->events;
    }

    // =====================================================================
    // Une action du joueur
    // =====================================================================

    /**
     * $action :
     *   ['type' => 'move', 'slot' => 0..3]
     *   ['type' => 'switch', 'party' => index dans l'équipe]
     *   ['type' => 'item', 'item' => [...], 'target' => index dans l'équipe ou null]
     *   ['type' => 'run']
     *   ['type' => 'continue']                 (attaque imposée : préparation, Ultralaser, Mania)
     *   ['type' => 'learn', 'forget' => 0..3 ou null]
     */
    public function act(array $action): array
    {
        $this->events = [];
        $this->itemConsumed = false;
        $phase = $this->state['phase'];

        if ($this->state['type'] === 'pvp') {
            throw new InvalidAction('Combat entre joueurs : utilisez actPvp().');
        }

        if ($phase === 'ended') {
            throw new InvalidAction('Le combat est terminé.');
        }

        if ($this->state['type'] === 'safari') {
            return $this->safariTurn($action);
        }

        if ($phase === 'learn_move') {
            if (($action['type'] ?? null) !== 'learn') {
                throw new InvalidAction('Choisissez d\'abord quelle capacité oublier.');
            }
            $this->resolveLearnMove($action['forget'] ?? null);
            $this->afterTurn();

            return $this->events;
        }

        if ($phase === 'switch') {
            if (($action['type'] ?? null) !== 'switch') {
                throw new InvalidAction('Choisissez le Pokémon à envoyer.');
            }
            $this->validateSwitch((int) $action['party']);
            $this->switchIn('player', (int) $action['party'], recall: false);
            $this->state['phase'] = 'command';

            return $this->events;
        }

        // ----- phase "command" : un vrai tour de combat -----
        $player = &$this->active('player');

        // Attaque imposée (préparation, repos après Ultralaser, Mania) : le choix du joueur est ignoré
        if (! empty($player['volatile']['locked'])) {
            $action = ['type' => 'move', 'slot' => $player['volatile']['locked']['slot']];
        }

        $this->validateAction($action);

        // Plus aucun PP sur aucune attaque : ce sera Lutte
        if ($action['type'] === 'move' && empty($player['volatile']['locked'])
            && ! collect($player['moves'])->contains(fn ($m) => $m['pp'] > 0)) {
            $action['slot'] = -1;
        }

        return $this->commandTurn($action, null);
    }

    /**
     * Combat entre joueurs, phase "command" : les deux actions sont jouées dans le même tour.
     * $p1 / $p2 : ['type' => 'move', 'slot'] ou ['type' => 'switch', 'party'] (ou 'continue')
     */
    public function actPvp(array $p1, array $p2): array
    {
        $this->events = [];
        if ($this->state['phase'] !== 'command') {
            throw new InvalidAction('Ce n\'est pas le moment de choisir une action.');
        }

        $actions = ['player' => $p1, 'foe' => $p2];
        foreach ($actions as $side => &$action) {
            $b = $this->active($side);
            // Attaque imposée (préparation, repos, Mania) : le choix est ignoré
            if (! empty($b['volatile']['locked'])) {
                $action = ['type' => 'move', 'slot' => $b['volatile']['locked']['slot']];
            }
            if (($action['type'] ?? null) === 'continue') {
                $action = ['type' => 'move', 'slot' => 0];
            }
            $this->validateAction($action, $side);
            if ($action['type'] === 'move' && empty($b['volatile']['locked'])
                && ! collect($b['moves'])->contains(fn ($m) => $m['pp'] > 0)) {
                $action['slot'] = -1; // Lutte
            }
        }
        unset($action);

        return $this->commandTurn($actions['player'], $actions['foe']);
    }

    /**
     * Combat entre joueurs, phase "switch" : le ou les joueurs dont le Pokémon est K.O. envoient le suivant.
     * $choices : ['player' => index ou null, 'foe' => index ou null]
     */
    public function pvpSwitch(array $choices): array
    {
        $this->events = [];
        if ($this->state['phase'] !== 'switch') {
            throw new InvalidAction('Aucun Pokémon à remplacer.');
        }
        foreach (['player', 'foe'] as $side) {
            if (! empty($this->state['needSwitch'][$side])) {
                if (! isset($choices[$side])) {
                    throw new InvalidAction('Choisissez le Pokémon à envoyer.');
                }
                $this->validateSwitch((int) $choices[$side], $side);
            }
        }
        foreach (['player', 'foe'] as $side) {
            if (! empty($this->state['needSwitch'][$side])) {
                $this->switchIn($side, (int) $choices[$side], recall: false);
            }
        }
        $this->state['needSwitch'] = ['player' => false, 'foe' => false];
        $this->state['phase'] = 'command';

        return $this->events;
    }

    /** PvP : vérifie un choix AVANT d'attendre l'autre joueur (lance InvalidAction s'il est impossible) */
    public function checkAction(array $action, string $side): void
    {
        if ($this->state['phase'] === 'switch') {
            $this->validateSwitch((int) ($action['party'] ?? -1), $side);

            return;
        }
        if (($action['type'] ?? null) === 'continue' || ! empty($this->active($side)['volatile']['locked'])) {
            return;
        }
        $this->validateAction($action, $side);
    }

    /** Un tour de combat. $foeChoice = null : l'adversaire est l'ordinateur (IA) */
    private function commandTurn(array $action, ?array $foeChoice): array
    {
        $this->state['turn']++;
        $this->speedTie = null;
        $foeSwitch = null;
        if ($foeChoice === null) {
            // Le dresseur adverse peut utiliser un objet (Super Potion...) au lieu d'attaquer
            $foeItem = $this->chooseFoeItem();
            $foeAction = $foeItem === null ? $this->chooseFoeAction() : null;
        } else {
            $foeItem = null;
            $foeAction = $foeChoice['type'] === 'move' ? (int) $foeChoice['slot'] : null;
            $foeSwitch = $foeChoice['type'] === 'switch' ? (int) $foeChoice['party'] : null;
        }

        // 1) Les actions "rapides" passent AVANT les attaques : fuite, changement, objet
        $type = $action['type'];
        if ($type === 'run') {
            if ($this->tryRun()) {
                return $this->events;
            }
        } elseif ($type === 'switch') {
            $this->switchIn('player', (int) $action['party'], recall: true);
        } elseif ($type === 'item') {
            $this->useItem($action['item'], $action['target'] ?? null);
            if ($this->state['phase'] === 'ended') {
                return $this->events; // Pokémon capturé
            }
        }

        if ($foeItem !== null) {
            $this->foeUseItem($foeItem);
        }
        if ($foeSwitch !== null) {
            $this->switchIn('foe', $foeSwitch, recall: true);
        }

        // 2) Les attaques, dans l'ordre : priorité, puis Vitesse
        $moves = $foeAction === null ? [] : [['side' => 'foe', 'slot' => $foeAction]];
        if ($type === 'move') {
            $moves[] = ['side' => 'player', 'slot' => (int) $action['slot']];
        }
        // Étape 14 : Vive Griffe (tirée une fois par tour, seulement s'il y a deux attaques)
        $this->quick = [];
        $custap = [];
        if (count($moves) > 1) {
            foreach ($moves as $m) {
                $b = $this->active($m['side']);
                $claw = $this->holds($b, 'quick_claw');
                if ($claw && $this->rng->chance($claw['value'] ?? 20)) {
                    $this->quick[$m['side']] = true;
                }
                // Étape 22 : Baie Chérim, au quart des PV le porteur agit en premier (elle est mangée)
                $berry = $this->holds($b, 'pinch_berry');
                if ($berry && ($berry['stat'] ?? null) === 'custap' && $b['hp'] > 0 && $b['hp'] * 4 <= $b['stats']['hp']) {
                    $this->quick[$m['side']] = true;
                    $custap[$m['side']] = $b['item']['name'];
                }
            }
        }
        usort($moves, fn ($a, $b) => $this->compareOrder($a, $b));
        if (count($moves) > 1 && ! empty($this->quick[$moves[0]['side']]) && empty($this->quick[$moves[1]['side']])) {
            $side0 = $moves[0]['side'];
            $first = $this->active($side0);
            if (isset($custap[$side0])) {
                $this->say($this->text->subject($first, $side0) . " mange sa {$custap[$side0]} et agit plus vite !");
                $this->consumeItem($side0);
            } else {
                $this->say($this->text->subject($first, $side0) . " agit en premier grâce à sa {$first['item']['name']} !");
            }
        }

        $this->state['firstMover'] = $moves[0]['side'] ?? null;
        // Qui est au combat au début du tour : un Pokémon envoyé en cours de tour (après un K.O.) n'attaque pas
        $startActive = ['player' => $this->state['player']['active'], 'foe' => $this->state['foe']['active']];

        foreach ($moves as $move) {
            if ($this->battleIsDecided()) {
                break;
            }
            if ($this->state[$move['side']]['active'] !== $startActive[$move['side']]) {
                continue;
            }
            $this->executeMove($move['side'], $move['slot']);
            $this->checkBerries(); // étape 14 : une Baie tenue se mange dès que les PV tombent à la moitié
            $this->handleFaints();
            if ($this->state['phase'] === 'ended' || $this->state['phase'] === 'switch') {
                break;
            }
        }

        // 3) Fin du tour : brûlure, poison, Vampigraine, Ligotage...
        if (! $this->battleIsDecided() && $this->state['phase'] === 'command') {
            $this->endOfTurn();
            $this->handleFaints();
        }

        $this->afterTurn();

        return $this->events;
    }

    // =====================================================================
    // Validation
    // =====================================================================

    private function validateAction(array $action, string $side = 'player'): void
    {
        $player = $this->active($side);
        $pvp = $this->state['type'] === 'pvp';

        switch ($action['type'] ?? null) {
            case 'move':
                if (! empty($player['volatile']['locked'])) {
                    return; // attaque imposée : le choix ne compte pas
                }
                $slot = (int) ($action['slot'] ?? -1);
                if (! collect($player['moves'])->contains(fn ($m) => $m['pp'] > 0)) {
                    return; // plus aucun PP : Lutte
                }
                if (! isset($player['moves'][$slot])) {
                    throw new InvalidAction('Capacité inconnue.');
                }
                if ($player['moves'][$slot]['pp'] <= 0) {
                    throw new InvalidAction('Il n\'y a plus de PP pour cette capacité !');
                }
                // Étape 22 : objet Choix, une seule capacité tant que le Pokémon reste au combat
                $lock = $player['volatile']['choiceLock'] ?? null;
                if ($lock !== null && $lock !== $slot && ($player['moves'][$lock]['pp'] ?? 0) > 0 && $this->holds($player, 'choice')) {
                    throw new InvalidAction("{$player['item']['name']} : {$player['name']} ne peut utiliser que {$player['moves'][$lock]['name']} !");
                }

                return;

            case 'switch':
                // Étape 22 : la Carapace Mue permet toujours d'être rappelé
                if (! empty($player['volatile']['trapped']) && ! $this->holds($player, 'shed_shell')) {
                    throw new InvalidAction("{$player['name']} est piégé et ne peut pas être rappelé !");
                }
                // Étape 23 : Marque Ombre, Piège Sable, Magnépiège de l'adversaire
                if (($trap = $this->trappedByAbility($side)) && ! $this->holds($player, 'shed_shell')) {
                    throw new InvalidAction("{$player['name']} ne peut pas être rappelé à cause de {$trap} !");
                }
                $this->validateSwitch((int) ($action['party'] ?? -1), $side);

                return;

            case 'item':
                if ($pvp) {
                    throw new InvalidAction('Les objets sont interdits dans les combats entre joueurs.');
                }
                if (! empty($this->state['tower'])) {   // étape 28
                    throw new InvalidAction('Les objets du Sac sont interdits à la Tour de Combat.');
                }
                if (empty($action['item']['effect'])) {
                    throw new InvalidAction('Cet objet ne peut pas être utilisé ici.');
                }

                return;

            case 'run':
                if ($this->state['type'] !== 'wild') {
                    throw new InvalidAction('Impossible de fuir un combat de Dresseur !');
                }
                if ((! empty($player['volatile']['trapped']) || $this->trappedByAbility($side)) && ! $this->hasAbility($player, 'run-away')) {
                    throw new InvalidAction('Impossible de fuir !');
                }

                return;
        }

        throw new InvalidAction('Action inconnue.');
    }

    private function validateSwitch(int $index, string $side = 'player'): void
    {
        $party = $this->state[$side]['party'];

        if (! isset($party[$index])) {
            throw new InvalidAction('Pokémon inconnu.');
        }
        if ($party[$index]['hp'] <= 0) {
            throw new InvalidAction("{$party[$index]['name']} n'a plus la force de se battre !");
        }
        if ($index === $this->state[$side]['active']) {
            throw new InvalidAction("{$party[$index]['name']} est déjà au combat !");
        }
    }

    // =====================================================================
    // Ordre d'action
    // =====================================================================

    private function compareOrder(array $a, array $b): int
    {
        $pa = $this->movePriority($a['side'], $a['slot']);
        $pb = $this->movePriority($b['side'], $b['slot']);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }

        // Étape 14 : la Vive Griffe passe devant la Vitesse (mais pas devant une attaque prioritaire)
        $qa = ! empty($this->quick[$a['side']]);
        $qb = ! empty($this->quick[$b['side']]);
        if ($qa !== $qb) {
            return $qa ? -1 : 1;
        }

        // Étape 22 : Ralentiqueue / Encens Plein, le porteur agit en dernier
        // Étape 23 : talent Frein, pareil
        $la = $this->holds($this->active($a['side']), 'last') || $this->hasAbility($this->active($a['side']), 'stall');
        $lb = $this->holds($this->active($b['side']), 'last') || $this->hasAbility($this->active($b['side']), 'stall');
        if ($la !== $lb) {
            return $la ? 1 : -1;
        }

        $sa = $this->effectiveSpeed($a['side']);
        $sb = $this->effectiveSpeed($b['side']);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }

        // Égalité parfaite : pile ou face (mais un seul tirage, pour rester cohérent)
        return $this->speedTie ??= ($this->rng->int(0, 1) ? 1 : -1);
    }

    private function movePriority(string $side, int $slot): int
    {
        $move = $this->active($side)['moves'][$slot] ?? null;

        return $move['priority'] ?? 0;
    }

    private function effectiveSpeed(string $side): int
    {
        $b = $this->active($side);
        $speed = $b['stats']['speed'] * Formulas::stageMultiplier($b['stages']['speed']);
        if ($b['status'] === 'paralysis') {
            $speed /= 2;
        }
        // Étape 22 : Mouchoir Choix x1,5 ; Balle Fer, Bracelet Macho, objets Pouvoir /2 ; Poudre Vite (Métamorph) x2
        $item = $b['item']['effect'] ?? null;
        if (is_array($item)) {
            if ($item['effect'] === 'choice' && ($item['stat'] ?? null) === 'speed') {
                $speed *= 1.5;
            } elseif (in_array($item['effect'], ['iron_ball', 'ev_double', 'ev_power'], true)) {
                $speed /= 2;
            }
        }
        $speed *= $this->speciesStat($b, 'speed');
        $speed *= $this->abilitySpeed($b, $side);   // étape 23 : Glissade, Chlorophylle, Pied Véloce...

        return (int) floor($speed);
    }

    // =====================================================================
    // Attaques
    // =====================================================================

    private function executeMove(string $side, int $slot): void
    {
        $other = $this->opponent($side);
        $user = &$this->active($side);
        $target = &$this->active($other);

        if ($user['hp'] <= 0) {
            return;
        }

        // Repos forcé après Ultralaser
        if (! empty($user['volatile']['recharge'])) {
            unset($user['volatile']['recharge'], $user['volatile']['locked']);
            $this->say($this->text->subject($user, $side) . ' doit se reposer !');

            return;
        }

        if (! $this->canAct($side)) {
            unset($user['volatile']['charging'], $user['volatile']['rampage'], $user['volatile']['locked'], $user['volatile']['invulnerable']);

            return;
        }

        // Pokémon reçu d'un autre dresseur, trop fort pour les badges du joueur : il peut désobéir
        if ($side === 'player' && $this->state['type'] !== 'pvp' && ! $this->obeys($user)) {
            unset($user['volatile']['charging'], $user['volatile']['rampage'], $user['volatile']['locked']);

            return;
        }

        // Plus aucun PP : Lutte
        $struggle = $slot < 0 || ! isset($user['moves'][$slot]);
        if ($struggle) {
            $move = $this->struggleMove();
            $this->say($this->text->subject($user, $side) . " n'a plus de capacités !");
        } else {
            $move = $user['moves'][$slot];
        }

        // ---- attaques en deux tours : 1er tour = préparation ----
        $charging = $user['volatile']['charging'] ?? null;
        // Étape 23 : Lance-Soleil part tout de suite au soleil ; Ball'Météo et Normalise changent le type
        if (! $struggle) {
            $move = $this->weatherBall($move);
            if ($this->hasAbility($user, 'normalize')) {
                $move['type'] = 'normal';
            }
        }
        $sunBeam = $move['slug'] === 'solar-beam' && $this->weather() === 'sun';
        if (! $struggle && isset(self::CHARGE_MOVES[$move['slug']]) && $charging === null && ! $sunBeam) {
            $user['moves'][$slot]['pp']--;
            $user['volatile']['charging'] = $move['slug'];
            $user['volatile']['locked'] = ['slot' => $slot];
            if (in_array($move['slug'], self::SEMI_INVULNERABLE, true)) {
                $user['volatile']['invulnerable'] = $move['slug'];
            }
            $this->emit('move', ['side' => $side, 'move' => $move['slug'], 'moveType' => $move['type'], 'category' => 'status']);
            $this->say($this->text->subject($user, $side) . ' ' . self::CHARGE_MOVES[$move['slug']]);
            if ($move['slug'] === 'skull-bash') {
                $this->changeStat($side, 'defense', 1);
            }

            // Étape 22 : Herbe Pouvoir, l'attaque part tout de suite (l'herbe est consommée)
            if (! $this->holds($user, 'power_herb')) {
                return;
            }
            $this->say($this->text->subject($user, $side) . " est prêt grâce à son {$user['item']['name']} !");
            $this->consumeItem($side);
            $charging = $move['slug'];
        }
        $wasCharging = $charging !== null;
        unset($user['volatile']['charging'], $user['volatile']['invulnerable']);
        if ($wasCharging) {
            unset($user['volatile']['locked']);
        }

        // ---- PP (pas pour le 2e tour d'une attaque en deux tours, ni pendant une furie) ----
        $inRampage = isset($user['volatile']['rampage']);
        if (! $struggle && ! $wasCharging && ! $inRampage) {
            // Étape 23 : Pression, la capacité utilise 2 PP
            $cost = $this->hasAbility($target, 'pressure') && ! in_array($move['target'], self::SELF_TARGETS, true) ? 2 : 1;
            $user['moves'][$slot]['pp'] = max(0, $user['moves'][$slot]['pp'] - $cost);
        }

        $this->say($this->text->subject($user, $side) . " utilise {$move['name']} !");

        // ---- furie (Mania, Danse Fleur) : 2 ou 3 tours, puis confusion ----
        if (in_array($move['slug'], self::RAMPAGE_MOVES, true)) {
            if (! $inRampage) {
                $user['volatile']['rampage'] = $this->rng->int(2, 3);
                $user['volatile']['locked'] = ['slot' => $slot];
            }
            $user['volatile']['rampage']--;
            if ($user['volatile']['rampage'] <= 0) {
                unset($user['volatile']['rampage'], $user['volatile']['locked']);
                $endRampage = true;
            }
        }

        // Étape 22 : objet Choix (capacité bloquée) et Métronome (même capacité de suite)
        if (! $struggle && $this->holds($user, 'choice')) {
            $user['volatile']['choiceLock'] = $slot;
        }
        $user['volatile']['metronome'] = ($user['volatile']['lastMove'] ?? null) === $move['slug'] ? ($user['volatile']['metronome'] ?? 0) + 1 : 0;
        $user['volatile']['lastMove'] = $move['slug'];

        // Étape 23 : Moiteur empêche Explosion et Destruction
        if (in_array($move['slug'], self::SELF_DESTRUCT, true)) {
            foreach (['player', 'foe'] as $s) {
                if ($this->active($s)['hp'] > 0 && $this->hasAbility($this->active($s), 'damp')) {
                    $this->announce($s, 'Mais cela échoue !');

                    return;
                }
            }
        }

        $this->moldBreaker = $this->hasAbility($user, 'mold-breaker');   // étape 23 : Brise Moule
        $this->resolveMove($side, $slot, $move, $struggle);
        $this->moldBreaker = false;

        // Explosion, Destruction : le lanceur est K.O. quoi qu'il arrive
        if (in_array($move['slug'], self::SELF_DESTRUCT, true) && $this->active($side)['hp'] > 0) {
            $this->applyDamage($side, $this->active($side)['hp'], 'recoil');
        }

        if (! empty($endRampage) && $user['hp'] > 0 && empty($user['volatile']['confusion'])) {
            $user['volatile']['confusion'] = $this->rng->int(2, 5);
            $this->say($this->text->subject($user, $side) . ' est épuisé et devient confus !');
        }
    }

    /** Précision, puis effet de l'attaque (dégâts ou statut) */
    private function resolveMove(string $side, int $slot, array $move, bool $struggle): void
    {
        $other = $this->opponent($side);
        $user = &$this->active($side);
        $target = &$this->active($other);

        $selfTarget = in_array($move['target'], self::SELF_TARGETS, true);
        $fieldMove = in_array($move['target'], ['entire-field', 'opponents-field'], true);

        // ---- précision ----
        if (! $selfTarget && ! $fieldMove) {
            if ($target['hp'] <= 0) {
                $this->say('Mais il n\'y a pas de cible...');

                return;
            }
            if (! $this->moveHits($side, $move)) {
                $this->emit('move', ['side' => $side, 'move' => $move['slug'], 'moveType' => $move['type'], 'category' => $move['category'], 'missed' => true]);
                $this->say($this->text->subject($target, $other) . " évite l'attaque !");
                unset($user['volatile']['rampage'], $user['volatile']['locked']);

                return;
            }
        }

        $this->emit('move', ['side' => $side, 'move' => $move['slug'], 'moveType' => $move['type'], 'category' => $move['category']]);

        if ($move['category'] === 'status') {
            // Étape 23 : Absorbe-Volt contre Cage Éclair, Anti-Bruit contre Rugissement...
            if (! $selfTarget && ! $fieldMove && $move['type'] && $this->abilityBlocks($side, $other, $move, 1.0)) {
                return;
            }
            $this->statusMove($side, $move, $selfTarget);
        } else {
            $this->damagingMove($side, $move, $struggle);
        }

        if (in_array($move['slug'], self::RECHARGE_MOVES, true) && $this->active($side)['hp'] > 0) {
            $user['volatile']['recharge'] = true;
            $user['volatile']['locked'] = ['slot' => $slot];
        }
    }

    /**
     * Sommeil, gel, peur, confusion, paralysie : le Pokémon peut-il attaquer ce tour-ci ?
     */
    private function canAct(string $side): bool
    {
        $b = &$this->active($side);
        $who = $this->text->subject($b, $side);

        // Étape 23 : Absentéisme, un tour sur deux le Pokémon paresse
        if ($this->hasAbility($b, 'truant') && ! empty($b['volatile']['truant'])) {
            $this->say("{$who} paresse !");

            return false;
        }

        if ($b['status'] === 'sleep') {
            $b['sleepTurns'] -= $this->hasAbility($b, 'early-bird') ? 2 : 1;   // étape 23 : Matinal
            if ($b['sleepTurns'] > 0) {
                $this->emit('status_anim', ['side' => $side, 'status' => 'sleep']);
                $this->say("{$who} dort profondément.");

                return false;
            }
            $b['status'] = 'none';
            $this->emit('status', ['side' => $side, 'status' => 'none']);
            $this->say("{$who} se réveille !");
        }

        if ($b['status'] === 'freeze') {
            if ($this->rng->chance(20)) {
                $b['status'] = 'none';
                $this->emit('status', ['side' => $side, 'status' => 'none']);
                $this->say("{$who} dégèle !");
            } else {
                $this->emit('status_anim', ['side' => $side, 'status' => 'freeze']);
                $this->say("{$who} est gelé !");

                return false;
            }
        }

        if (! empty($b['volatile']['flinch'])) {
            $this->say("{$who} a peur ! Il ne peut plus attaquer !");
            if ($this->hasAbility($b, 'steadfast')) {   // étape 23 : Impassible
                unset($b);
                $this->announce($side);
                $this->changeStat($side, 'speed', 1);
            }

            return false;
        }

        if (! empty($b['volatile']['confusion'])) {
            $b['volatile']['confusion']--;
            if ($b['volatile']['confusion'] <= 0) {
                unset($b['volatile']['confusion']);
                $this->say("{$who} n'est plus confus !");
            } else {
                $this->emit('status_anim', ['side' => $side, 'status' => 'confusion']);
                $this->say("{$who} est confus !");
                if ($this->rng->chance(100 / 3)) {
                    // Il se frappe lui-même : attaque physique de puissance 40, sans type
                    $damage = $this->computeDamage($side, $side, ['power' => 40, 'category' => 'physical', 'type' => null], false, 1.0);
                    $this->say('Il se blesse dans sa confusion !');
                    $this->applyDamage($side, $damage, 'confusion');

                    return false;
                }
            }
        }

        if ($b['status'] === 'paralysis' && $this->rng->chance(25)) {
            $this->emit('status_anim', ['side' => $side, 'status' => 'paralysis']);
            $this->say("{$who} est paralysé ! Il ne peut pas attaquer !");

            return false;
        }

        return true;
    }

    /** Test de précision (Précision du lanceur contre Esquive de la cible) */
    private function moveHits(string $side, array $move): bool
    {
        $user = $this->active($side);
        $target = $this->active($this->opponent($side));

        // Cible en plein Vol / Tunnel : hors d'atteinte
        if (! empty($target['volatile']['invulnerable'])) {
            return false;
        }

        if ($move['effect'] === 'ohko') {
            if ($user['level'] < $target['level']) {
                return false;
            }

            return $this->rng->chance(30 + $user['level'] - $target['level']);
        }

        // Étape 23 : météo (Fatal-Foudre sous la pluie, Blizzard sous la grêle) et talents (Annule Garde, Œil Composé...)
        $weatherAcc = $this->weatherAccuracy($move);
        if ($weatherAcc === -1) {
            return true;
        }
        $abilityAcc = $this->abilityAccuracy($user, $target, $move);
        if ($abilityAcc === null) {
            return true;
        }

        if ($move['accuracy'] === null) {
            return true; // ne rate jamais (Météores...)
        }

        // Inconscient : les changements de stats de l'autre ne comptent pas
        $accStage = $this->targetHas($target, 'unaware') ? 0 : $user['stages']['accuracy'];
        $evaStage = $this->hasAbility($user, 'unaware') ? 0 : $target['stages']['evasion'];
        $accuracy = ($weatherAcc ?? $move['accuracy']) * Formulas::accuracyMultiplier($accStage - $evaStage) * $abilityAcc;

        // Étape 22 : Loupe, Lentille Zoom (si le porteur agit après), Baie Micle ; Poudre Claire / Encens Doux de la cible
        $lens = $this->holds($user, 'accuracy');
        if ($lens && (empty($lens['after']) || ($this->state['firstMover'] ?? null) !== $side)) {
            $accuracy *= $lens['value'] ?? 1.1;
        }
        if (! empty($user['volatile']['micle'])) {
            $accuracy *= $user['volatile']['micle'];
            unset($this->active($side)['volatile']['micle']);
        }
        if ($powder = $this->holds($target, 'evasion')) {
            $accuracy *= $powder['value'] ?? 0.9;
        }

        return $this->rng->chance($accuracy);
    }

    // ---------------------------------------------------------------------
    // Attaques qui font des dégâts
    // ---------------------------------------------------------------------

    private function damagingMove(string $side, array $move, bool $struggle): void
    {
        $other = $this->opponent($side);
        $user = &$this->active($side);
        $target = &$this->active($other);

        $targetTypes = $target['types'];
        // Étape 22 : la Balle Fer cloue au sol, les attaques Sol touchent même un Pokémon Vol
        if ($move['type'] === 'ground' && $this->holds($target, 'iron_ball')) {
            $targetTypes = array_values(array_diff($targetTypes, ['flying'])) ?: ['normal'];
        }
        // Étape 23 : Querelleur, les attaques Normal et Combat touchent les Pokémon Spectre
        if (in_array($move['type'], ['normal', 'fighting'], true) && $this->hasAbility($user, 'scrappy')) {
            $targetTypes = array_values(array_diff($targetTypes, ['ghost'])) ?: ['normal'];
        }
        $effectiveness = $move['type'] === null ? 1.0 : $this->effectiveness($move['type'], $targetTypes);

        if ($effectiveness == 0.0) {
            $this->say('Ça n\'affecte pas ' . $this->text->subject($target, $other, capital: false) . '...');
            unset($user['volatile']['rampage'], $user['volatile']['locked']);

            return;
        }

        // Étape 23 : Lévitation, Absorbe-Volt, Torche, Garde Mystik...
        if ($this->abilityBlocks($side, $other, $move, $effectiveness)) {
            unset($user['volatile']['rampage'], $user['volatile']['locked']);

            return;
        }

        if ($move['effect'] === 'ohko') {
            $this->applyDamage($other, $target['hp'], 'damage', $effectiveness);
            $this->say('K.O. en un coup !');

            return;
        }

        // Attaques à puissance variable (Gigotage, Gyroballe, Balayage...) : "null" dans PokéAPI
        if ($move['power'] === null && ! in_array($move['slug'], self::FIXED_DAMAGE, true)) {
            $move['power'] = $this->variablePower($move['slug'], $user, $target);
            if ($move['power'] === null) {
                $this->say('Mais cela échoue !');

                return;
            }
        }

        // Nombre de coups (Double Pied = 2, Furie = 2 à 5...) ; étape 23 : Multi-Coups, toujours le maximum
        $hits = $this->hitCount($move);
        if ($hits > 1 && $this->hasAbility($user, 'skill-link')) {
            $hits = (int) ($move['meta']['max_hits'] ?? $hits);
        }
        $totalDamage = 0;
        $hitsDone = 0;

        for ($i = 0; $i < $hits; $i++) {
            if ($target['hp'] <= 0 || $user['hp'] <= 0) {
                break;
            }

            if (in_array($move['slug'], self::FIXED_DAMAGE, true)) {
                $damage = $this->fixedDamage($move['slug'], $user, $target);
                $crit = false;
                if ($damage <= 0) {
                    $this->say('Mais cela échoue !');

                    return;
                }
            } else {
                $critStage = ($move['meta']['crit_rate'] ?? 0) + ($user['stages']['crit'] ?? 0)
                    + (int) ($this->holds($user, 'crit_boost')['value'] ?? 0)   // étape 14 : Lentilscope
                    + (int) ($this->speciesItem($user, 'species_crit')['value'] ?? 0)   // étape 22 : Poireau, Poing Chance
                    + ($this->hasAbility($user, 'super-luck') ? 1 : 0);                 // étape 23 : Chanceux
                $crit = $this->rng->chance(Formulas::critChance($critStage))
                    && ! $this->targetHas($target, 'battle-armor', 'shell-armor');      // étape 23 : Armurbaston, Coque Armure
                $damage = $this->computeDamage($side, $other, $move, $crit, $effectiveness);
            }

            // Étape 22 : Baie qui affaiblit une attaque super efficace de son type (Baie Zalis : Normal)
            $resist = $this->holds($target, 'resist_berry');
            if ($resist && $i === 0 && ($resist['type'] ?? null) === $move['type'] && ($effectiveness > 1 || $move['type'] === 'normal')
                && ! in_array($move['slug'], self::FIXED_DAMAGE, true)) {
                $damage = max(1, intdiv($damage, 2));
                $this->say("{$target['item']['name']} affaiblit l'attaque reçue par " . $this->text->subject($target, $other, capital: false) . ' !');
                $this->consumeItem($other);
                $target = &$this->active($other);
            }

            $damage = min($damage, $target['hp']);

            // Étape 14 : Bandeau, le Pokémon peut tenir avec 1 PV
            // Étape 22 : Ceinture Force, il tient à coup sûr s'il avait tous ses PV (elle est consommée)
            $endured = false;
            $endureItem = $target['item']['name'] ?? '';
            $band = $this->holds($target, 'focus_band');
            $sash = $this->holds($target, 'focus_sash') && $target['hp'] >= $target['stats']['hp'];
            // Étape 23 : Fermeté (jeux récents), comme une Ceinture Force qui ne s'use pas
            $sturdy = $this->targetHas($target, 'sturdy') && $target['hp'] >= $target['stats']['hp'];
            if ($sturdy && ! $sash) {
                $endureItem = $target['abilityName'];
            }
            if ($damage >= $target['hp'] && $target['hp'] > 1 && ($sturdy || $sash || ($band && $this->rng->chance($band['value'] ?? 10)))) {
                $damage = $target['hp'] - 1;
                $endured = true;
                if ($sash && ! $sturdy) {
                    $this->consumeItem($other);
                    $target = &$this->active($other);
                }
            }

            $totalDamage += $damage;
            $hitsDone++;

            $this->applyDamage($other, $damage, 'damage', $effectiveness, $crit);
            if ($crit) {
                $this->say('Coup critique !');
                // Étape 23 : Colérique, l'Attaque monte au maximum après un coup critique reçu
                if ($target['hp'] > 0 && $this->hasAbility($target, 'anger-point')) {
                    $this->announce($other);
                    $target['stages']['attack'] = 6;
                    $this->say('L\'Attaque ' . $this->text->of($target, $other) . ' est au maximum !');
                }
            }
            if ($endured) {
                $this->say($this->text->subject($target, $other) . " tient bon grâce à {$endureItem} !");
            }
        }

        if (! in_array($move['slug'], self::FIXED_DAMAGE, true)) {
            if ($effectiveness > 1) {
                $this->say('C\'est super efficace !');
            } elseif ($effectiveness < 1) {
                $this->say('Ce n\'est pas très efficace...');
            }
        }
        if ($hits > 1) {
            $this->say("Touché {$hitsDone} fois !");
        }

        // Une attaque Feu dégèle la cible
        if ($move['type'] === 'fire' && $target['status'] === 'freeze' && $target['hp'] > 0) {
            $target['status'] = 'none';
            $this->emit('status', ['side' => $other, 'status' => 'none']);
            $this->say($this->text->subject($target, $other) . ' dégèle !');
        }

        // Recul (Bélier, Damoclès... ou Lutte : 1/4 des PV max)
        $drain = $move['meta']['drain'] ?? 0;
        if ($struggle) {
            $this->applyDamage($side, max(1, intdiv($user['stats']['hp'], 4)), 'recoil');
            $this->say($this->text->subject($user, $side) . ' est blessé par le contrecoup !');
        } elseif ($drain < 0 && $totalDamage > 0 && $user['hp'] > 0) {
            // Étape 23 : Tête de Roc (pas de contrecoup), Garde Magik (pas de dégâts indirects)
            if (! $this->hasAbility($user, 'rock-head')) {
                $this->hurt($side, intdiv($totalDamage * -$drain, 100), $this->text->subject($user, $side) . ' est blessé par le contrecoup !', 'recoil');
            }
        } elseif ($drain > 0 && $totalDamage > 0 && $user['hp'] > 0 && $this->hasAbility($target, 'liquid-ooze')) {
            // Étape 23 : Suintement, drainer blesse au lieu de soigner
            $this->announce($other);
            $this->hurt($side, intdiv($totalDamage * $drain, 100), $this->text->subject($user, $side) . ' aspire le suintement !');
        } elseif ($drain > 0 && $totalDamage > 0 && $user['hp'] > 0 && $user['hp'] < $user['stats']['hp']) {
            // Vol-Vie, Méga-Sangsue : soigne le lanceur (étape 22 : Grosse Racine x1,3)
            $amount = max(1, intdiv($totalDamage * $drain, 100));
            if ($root = $this->holds($user, 'big_root')) {
                $amount = (int) floor($amount * ($root['value'] ?? 1.3));
            }
            $this->heal($side, $amount);
            $this->say('L\'énergie ' . $this->text->of($target, $other) . ' est drainée !');
        }

        $this->afterDamageItems($side, $move, $totalDamage, $effectiveness, $struggle);
        // Étape 23 : Statik, Corps Ardent, Peau Dure, Boom Final... et Homochromie
        $this->contactAbilities($side, $other, $move, $totalDamage);
        $this->colorChange($other, $move, $totalDamage);

        $this->secondaryEffects($side, $move, $totalDamage);
    }

    /** Effets secondaires d'une attaque qui a touché : statut, baisse de stats, peur */
    private function secondaryEffects(string $side, array $move, int $damage): void
    {
        $other = $this->opponent($side);
        $target = $this->active($other);
        $meta = $move['meta'] ?? [];
        $effect = $move['effect'] ?? 'damage';

        if ($damage <= 0) {
            return;
        }
        // Étape 23 : Sérénité double les chances ; Écran Poudre protège la cible des effets secondaires
        $grace = $this->hasAbility($this->active($side), 'serene-grace') ? 2 : 1;
        $shielded = $this->targetHas($target, 'shield-dust');

        // Statut infligé à la cible (Flammèche : 10 % de brûler)
        if (! $shielded && in_array($effect, ['damage+ailment', 'damage'], true) && ($meta['ailment'] ?? 'none') !== 'none' && $target['hp'] > 0) {
            $chance = $meta['ailment_chance'] ?: ($effect === 'damage+ailment' ? 100 : 0);
            if ($chance > 0 && $this->rng->chance(min(100, $chance * $grace))) {
                $this->inflictAilment($other, $meta['ailment'], $move, quiet: true, source: $side);
            }
        }

        // Stats de la cible qui baissent (Bulles d'O : Vitesse -1, 10 %)
        if (! $shielded && $effect === 'damage+lower' && $target['hp'] > 0) {
            $chance = min(100, ($meta['stat_chance'] ?: 100) * $grace);
            if ($this->rng->chance($chance)) {
                foreach ($meta['stat_changes'] ?? [] as $c) {
                    $this->changeStat($other, $c['stat'], $c['change'], $side);
                }
            }
        }

        // Stats du lanceur qui changent (Griffe Acier : Attaque +1, 10 %)
        if ($effect === 'damage+raise' && $this->active($side)['hp'] > 0) {
            $chance = min(100, ($meta['stat_chance'] ?: 100) * $grace);
            if ($this->rng->chance($chance)) {
                foreach ($meta['stat_changes'] ?? [] as $c) {
                    $this->changeStat($side, $c['stat'], $c['change']);
                }
            }
        }

        // Peur : seulement si le lanceur a agi en premier
        $flinch = $meta['flinch_chance'] ?? 0;
        // Étape 14 : Roche Royale, une chance d'apeurer avec une attaque qui n'en a pas déjà
        if ($flinch <= 0 && ($rock = $this->holds($this->active($side), 'flinch'))) {
            $flinch = $rock['value'] ?? 10;
        }
        // Étape 23 : Puanteur (10 %) ; Attention protège de la peur
        if ($flinch <= 0 && $this->hasAbility($this->active($side), 'stench')) {
            $flinch = 10;
        }
        $flinch = $shielded || $this->targetHas($target, 'inner-focus') ? 0 : $flinch * $grace;
        if ($flinch > 0 && $target['hp'] > 0 && ($this->state['firstMover'] ?? null) === $side && $this->rng->chance($flinch)) {
            $this->active($other)['volatile']['flinch'] = true;
        }
    }

    /** Dégâts d'une attaque (5e génération et suivantes) */
    private function computeDamage(string $side, string $targetSide, array $move, bool $crit, float $effectiveness): int
    {
        $user = $this->active($side);
        $target = $this->active($targetSide);
        $physical = $move['category'] === 'physical';

        [$atkStat, $defStat] = $physical ? ['attack', 'defense'] : ['sp_attack', 'sp_defense'];
        $atkStage = $user['stages'][$atkStat];
        $defStage = $target['stages'][$defStat];
        // Étape 23 : Inconscient, les changements de stats de l'autre ne comptent pas
        if ($side !== $targetSide && $this->targetHas($target, 'unaware')) {
            $atkStage = 0;
        }
        if ($side !== $targetSide && $this->hasAbility($user, 'unaware')) {
            $defStage = 0;
        }

        // Un coup critique ignore les baisses d'Attaque du lanceur et les hausses de Défense de la cible
        if ($crit) {
            $atkStage = max(0, $atkStage);
            $defStage = min(0, $defStage);
        }

        $attack = (int) floor($user['stats'][$atkStat] * Formulas::stageMultiplier($atkStage));
        $defense = (int) floor($target['stats'][$defStat] * Formulas::stageMultiplier($defStage));

        // Étape 14 : objet qui renforce un type (Charbon, Eau Mystique...) : x1,2 sur la puissance
        $power = (int) $move['power'];
        $boost = $this->holds($user, 'type_boost');
        if ($boost && ($boost['type'] ?? null) === $move['type']) {
            $power = (int) floor($power * ($boost['value'] ?? 1.2));
        }

        // Étape 22 : objets tenus (pas sur un Pokémon qui se frappe dans sa confusion)
        $final = 1.0;
        if ($side !== $targetSide) {
            $choice = $this->holds($user, 'choice');
            if ($choice && ($choice['stat'] ?? null) === $atkStat) {
                $attack = (int) floor($attack * 1.5);                                // Bandeau / Lunettes Choix
            }
            $attack = (int) floor($attack * $this->speciesStat($user, $atkStat));     // Balle Lumière, Masse Os...
            $defense = (int) floor($defense * $this->speciesStat($target, $defStat)); // Poudre Métal...
            $muscle = $this->holds($user, 'category_boost');
            if ($muscle && ($muscle['category'] ?? null) === $move['category']) {
                $power = (int) floor($power * ($muscle['value'] ?? 1.1));            // Bandeau Muscle, Lunettes Sages
            }
            $orb = $this->speciesItem($user, 'species_type_boost');
            if ($orb && in_array($move['type'], $orb['types'] ?? [], true)) {
                $power = (int) floor($power * ($orb['value'] ?? 1.2));               // Orbes de Dialga, Palkia...
            }
            if ($life = $this->holds($user, 'life_orb')) {
                $final *= $life['value'] ?? 1.3;                                       // Orbe Vie
            }
            if (($belt = $this->holds($user, 'expert_belt')) && $effectiveness > 1) {
                $final *= $belt['value'] ?? 1.2;                                       // Ceinture Pro
            }
            if ($this->holds($user, 'metronome')) {
                $final *= min(2.0, 1 + 0.2 * ($user['volatile']['metronome'] ?? 0)); // Métronome
            }

            // Étape 23 : talents et météo
            [$atkMod, $defMod, $powerMod, $finalMod] = $this->abilityDamage($user, $target, $move, $effectiveness, $crit);
            $attack = (int) floor($attack * $atkMod);
            $defense = (int) floor($defense * $defMod);
            $power = max(1, (int) floor($power * $powerMod));
            $final *= $finalMod;
        }

        $damage = Formulas::baseDamage($user['level'], $power, $attack, $defense);

        // Protection / Mur Lumière (pas sur un critique)
        $screen = $physical ? 'reflect' : 'light_screen';
        if (! $crit && ! empty($this->state[$targetSide]['screens'][$screen]) && $side !== $targetSide) {
            $damage = intdiv($damage, 2);
        }

        if ($crit) {
            $damage = (int) floor($damage * 1.5);
        }
        $damage = intdiv($damage * $this->rng->int(85, 100), 100);              // facteur aléatoire
        if ($move['type'] !== null && in_array($move['type'], $user['types'], true)) {
            // STAB : attaque de son propre type (étape 23 : Adaptabilité x2)
            $damage = (int) floor($damage * ($this->hasAbility($user, 'adaptability') ? 2 : 1.5));
        }
        $damage = (int) floor($damage * $effectiveness);
        if ($physical && $user['status'] === 'burn' && ! $this->hasAbility($user, 'guts')) {
            $damage = intdiv($damage, 2);                                       // brûlure : Attaque divisée par 2
        }
        $damage = (int) floor($damage * $final);                                // étape 22 : Orbe Vie, Ceinture Pro...

        return max(1, $damage);
    }

    private function fixedDamage(string $slug, array $user, array $target): int
    {
        return match ($slug) {
            'seismic-toss', 'night-shade' => $user['level'],
            'dragon-rage' => 40,
            'sonic-boom' => 20,
            'super-fang' => max(1, intdiv($target['hp'], 2)),
            'psywave' => max(1, intdiv($user['level'] * $this->rng->int(50, 150), 100)),
            'endeavor' => max(0, $target['hp'] - $user['hp']), // Effort : ramène la cible aux PV du lanceur
        };
    }

    /**
     * Puissance des attaques qui dépendent de la situation. null = l'attaque échoue
     * (Riposte, Patience... qui dépendent des coups reçus : pas encore programmées).
     */
    private function variablePower(string $slug, array $user, array $target): ?int
    {
        if (in_array($slug, self::UNSUPPORTED_DAMAGE, true)) {
            return null;
        }

        $weight = $target['weight'] ?? 100; // en hectogrammes (100 = 10 kg)

        return match ($slug) {
            // Moins il reste de PV au lanceur, plus c'est fort
            'flail', 'reversal' => match (true) {
                ($ratio = intdiv(48 * $user['hp'], max(1, $user['stats']['hp']))) <= 1 => 200,
                $ratio <= 4 => 150,
                $ratio <= 9 => 100,
                $ratio <= 16 => 80,
                $ratio <= 32 => 40,
                default => 20,
            },
            // Plus la cible est lourde, plus c'est fort
            'low-kick', 'grass-knot' => match (true) {
                $weight < 100 => 20,
                $weight < 250 => 40,
                $weight < 500 => 60,
                $weight < 1000 => 80,
                $weight < 2000 => 100,
                default => 120,
            },
            'heavy-slam', 'heat-crash' => match (true) {
                ($r = ($user['weight'] ?? 100) / max(1, $weight)) >= 5 => 120,
                $r >= 4 => 100,
                $r >= 3 => 80,
                $r >= 2 => 60,
                default => 40,
            },
            // Plus le lanceur est LENT par rapport à la cible, plus c'est fort
            'gyro-ball' => min(150, intdiv(25 * $target['stats']['speed'], max(1, $user['stats']['speed'])) + 1),
            'electro-ball' => match (true) {
                ($r = $user['stats']['speed'] / max(1, $target['stats']['speed'])) >= 4 => 150,
                $r >= 3 => 120,
                $r >= 2 => 80,
                $r >= 1 => 60,
                default => 40,
            },
            'magnitude' => $this->rng->pick([10, 30, 30, 50, 50, 50, 70, 70, 70, 70, 90, 90, 90, 110, 110, 150]),
            'return', 'frustration' => 102,
            'crush-grip', 'wring-out' => max(1, intdiv(120 * $target['hp'], max(1, $target['stats']['hp']))),
            'punishment' => 60,
            'trump-card' => 40,
            default => 60, // autres cas (Puissance Cachée...) : puissance moyenne
        };
    }

    private function hitCount(array $move): int
    {
        $min = $move['meta']['min_hits'] ?? null;
        $max = $move['meta']['max_hits'] ?? null;
        if (! $min || ! $max) {
            return 1;
        }
        if ($min === 2 && $max === 5) {
            // 2 coups : 35 %, 3 coups : 35 %, 4 coups : 15 %, 5 coups : 15 %
            $roll = $this->rng->int(1, 100);

            return match (true) {
                $roll <= 35 => 2,
                $roll <= 70 => 3,
                $roll <= 85 => 4,
                default => 5,
            };
        }

        return $this->rng->int($min, $max);
    }

    private function effectiveness(string $moveType, array $targetTypes): float
    {
        $multiplier = 1.0;
        foreach ($targetTypes as $type) {
            $multiplier *= $this->typeChart[$moveType][$type] ?? 1.0;
        }

        return $multiplier;
    }

    // ---------------------------------------------------------------------
    // Attaques de statut
    // ---------------------------------------------------------------------

    private function statusMove(string $side, array $move, bool $selfTarget): void
    {
        $other = $this->opponent($side);
        $user = &$this->active($side);
        $target = &$this->active($other);
        $meta = $move['meta'] ?? [];
        $slug = $move['slug'];

        // Quelques attaques au fonctionnement unique
        switch ($slug) {
            case 'rest':
                if ($user['hp'] >= $user['stats']['hp']) {
                    $this->say('Mais cela échoue !');

                    return;
                }
                $user['status'] = 'sleep';
                $user['sleepTurns'] = 3; // dort 2 tours (le compteur baisse avant chaque tentative)
                $user['toxic'] = 0;
                $this->emit('status', ['side' => $side, 'status' => 'sleep']);
                $this->heal($side, $user['stats']['hp']);
                $this->say($this->text->subject($user, $side) . ' s\'endort et récupère ses PV !');

                return;

            case 'haze':
                foreach (['player', 'foe'] as $s) {
                    $this->active($s)['stages'] = $this->freshStages();
                }
                $this->say('Tous les changements de stats ont été annulés !');

                return;

            case 'reflect':
            case 'light-screen':
                $key = $slug === 'reflect' ? 'reflect' : 'light_screen';
                if (! empty($this->state[$side]['screens'][$key])) {
                    $this->say('Mais cela échoue !');

                    return;
                }
                // Étape 22 : Lumargile, 8 tours au lieu de 5
                $this->state[$side]['screens'][$key] = (int) ($this->holds($user, 'light_clay')['value'] ?? 5);
                $this->say($slug === 'reflect'
                    ? 'Protection augmente la résistance aux attaques physiques !'
                    : 'Mur Lumière augmente la résistance aux attaques spéciales !');

                return;

            case 'focus-energy':
                if (($user['stages']['crit'] ?? 0) >= 2) {
                    $this->say('Mais cela échoue !');

                    return;
                }
                $user['stages']['crit'] = 2;
                $this->say($this->text->subject($user, $side) . ' se concentre au maximum !');

                return;

            case 'splash':
                $this->say('Mais rien ne se passe !');

                return;

            // Étape 23 : la météo
            case 'sunny-day':
            case 'rain-dance':
            case 'sandstorm':
            case 'hail':
                $type = ['sunny-day' => 'sun', 'rain-dance' => 'rain', 'sandstorm' => 'sand', 'hail' => 'hail'][$slug];
                if (($this->state['weather']['type'] ?? null) === $type || ! $this->setWeather($type, $side)) {
                    $this->say('Mais cela échoue !');
                }

                return;

            case 'teleport':
                if ($side === 'player' && $this->state['type'] === 'wild') {
                    $this->emit('flee', []);
                    $this->say('Vous prenez la fuite !');
                    $this->endBattle('fled');
                } else {
                    $this->say('Mais cela échoue !');
                }

                return;
        }

        $effect = str_replace('+', '-', (string) $move['effect']);

        switch ($effect) {
            case 'ailment':
                if ($meta['ailment'] === 'none' || $meta['ailment'] === null) {
                    $this->say('Mais cela échoue !');

                    return;
                }
                // Cage Éclair n'affecte pas les Pokémon Sol ; les poudres n'affectent pas les Pokémon Plante
                if ($move['type'] !== null && $slug === 'thunder-wave' && $this->effectiveness($move['type'], $target['types']) == 0.0) {
                    $this->say('Ça n\'affecte pas ' . $this->text->subject($target, $other, capital: false) . '...');

                    return;
                }
                if (in_array($slug, self::POWDER_MOVES, true) && in_array('grass', $target['types'], true)) {
                    $this->say('Ça n\'affecte pas ' . $this->text->subject($target, $other, capital: false) . '...');

                    return;
                }
                $this->inflictAilment($selfTarget ? $side : $other, $meta['ailment'], $move, quiet: false, source: $side);

                return;

            case 'net-good-stats':
                $changes = $meta['stat_changes'] ?? [];
                if (! $changes) {
                    $this->say('Mais cela échoue !');

                    return;
                }
                foreach ($changes as $c) {
                    $this->changeStat($selfTarget ? $side : $other, $c['stat'], $c['change'], $side);
                }

                return;

            case 'heal':
                if ($user['hp'] >= $user['stats']['hp']) {
                    $this->say('Mais les PV ' . $this->text->of($user, $side) . ' sont au maximum !');

                    return;
                }
                $percent = $meta['healing'] ?: 50;
                // Étape 23 : Rayon Lune, Synthèse, Aurore : 2/3 au soleil, 1/4 sous une autre météo
                if (in_array($slug, ['moonlight', 'synthesis', 'morning-sun'], true) && ($w = $this->weather())) {
                    $percent = $w === 'sun' ? 67 : 25;
                }
                $this->heal($side, max(1, intdiv($user['stats']['hp'] * $percent, 100)));
                $this->say($this->text->subject($user, $side) . ' récupère des PV !');

                return;

            case 'swagger':
                $this->changeStat($other, 'attack', 2);
                $this->inflictAilment($other, 'confusion', $move, quiet: false, source: $side);

                return;
        }

        // Effet pas encore programmé (Morphing, Métronome, Patience...) : on le dit honnêtement
        $this->say('Mais cela échoue !');
    }

    /**
     * Inflige un statut (paralysie, sommeil...) ou un état temporaire (confusion, Vampigraine, Ligotage).
     * $quiet : pas de message d'échec (effet secondaire d'une attaque qui n'a simplement pas pris)
     */
    private function inflictAilment(string $side, string $ailment, array $move, bool $quiet, ?string $source = null): void
    {
        $b = &$this->active($side);
        $who = $this->text->subject($b, $side);

        // Étape 23 : talents qui empêchent un statut (Échauffement, Insomnia, Vaccin, Tempo Perso...)
        $wanted = $ailment === 'poison' && ($move['slug'] ?? '') === 'toxic' ? 'bad_poison' : $ailment;
        if ($this->abilityPreventsStatus($b, $wanted) && ($source === null || $source !== $side)) {
            if (! $quiet) {
                unset($b);
                $this->announce($side, 'Ça n\'affecte pas ' . $this->text->subject($this->active($side), $side, capital: false) . '...');
            }

            return;
        }

        switch ($ailment) {
            case 'confusion':
                if (! empty($b['volatile']['confusion'])) {
                    $quiet || $this->say("{$who} est déjà confus !");

                    return;
                }
                $b['volatile']['confusion'] = $this->rng->int(2, 5);
                $this->emit('status_anim', ['side' => $side, 'status' => 'confusion']);
                $this->say("{$who} devient confus !");

                return;

            case 'leech-seed':
                if (in_array('grass', $b['types'], true) || ! empty($b['volatile']['leech_seed'])) {
                    $quiet || $this->say('Mais cela échoue !');

                    return;
                }
                $b['volatile']['leech_seed'] = true;
                $this->say("{$who} est infecté !");

                return;

            case 'trap':
                if (! empty($b['volatile']['trapped'])) {
                    return;
                }
                $turns = $this->rng->int($move['meta']['min_turns'] ?? 4, $move['meta']['max_turns'] ?? 5);
                if ($claw = $this->holds($this->active($this->opponent($side)), 'grip_claw')) {
                    $turns = (int) ($claw['value'] ?? 7);   // étape 22 : Accro Griffe
                }
                $b['volatile']['trapped'] = ['turns' => $turns, 'move' => $move['name']];
                $this->say("{$who} est piégé par {$move['name']} !");

                return;
        }

        $status = match ($ailment) {
            'paralysis' => 'paralysis',
            'burn' => 'burn',
            'freeze' => 'freeze',
            'sleep' => 'sleep',
            'poison' => $move['slug'] === 'toxic' ? 'bad_poison' : 'poison',
            default => null,
        };

        if ($status === null) {
            $quiet || $this->say('Mais cela échoue !');

            return;
        }

        if ($b['status'] !== 'none') {
            $quiet || $this->say($b['status'] === $status || ($status === 'bad_poison' && $b['status'] === 'poison')
                ? $this->text->alreadyStatus($b['status'], $who)
                : 'Mais cela échoue !');

            return;
        }

        // Immunités de type
        $immune = match ($status) {
            'burn' => in_array('fire', $b['types'], true),
            'paralysis' => in_array('electric', $b['types'], true),
            'freeze' => in_array('ice', $b['types'], true),
            'poison', 'bad_poison' => in_array('poison', $b['types'], true) || in_array('steel', $b['types'], true),
            default => false,
        };
        if ($immune) {
            $quiet || $this->say('Ça n\'affecte pas ' . $this->text->subject($b, $side, capital: false) . '...');

            return;
        }

        $b['status'] = $status;
        if ($status === 'sleep') {
            $b['sleepTurns'] = $this->rng->int(2, 4); // 1 à 3 tours de sommeil
        }
        if ($status === 'bad_poison') {
            $b['toxic'] = 0;
        }
        $this->emit('status', ['side' => $side, 'status' => $status]);
        $this->emit('status_anim', ['side' => $side, 'status' => $status]);
        $this->say($this->text->statusInflicted($status, $who));
        unset($b);
        $this->synchronize($side, $status, $source);   // étape 23 : Synchro
    }

    /**
     * Change une stat de -6 à +6 : "L'Attaque du Rattata sauvage baisse !"
     * $source : le camp qui provoque la baisse (étape 22 : la Brume de Garde-Stats protège des baisses venues de l'adversaire)
     */
    private function changeStat(string $side, string $stat, int $change, ?string $source = null): void
    {
        $b = &$this->active($side);
        if (! array_key_exists($stat, $b['stages']) || $b['hp'] <= 0) {
            return;
        }
        if ($change < 0 && $source !== null && $source !== $side && ! empty($this->state[$side]['mist'])) {
            $this->say($this->text->subject($b, $side) . ' est protégé par la Brume !');

            return;
        }
        // Étape 23 : Corps Sain, Hyper Cutter, Regard Vif bloquent ; Simple double
        unset($b);
        $change = $this->abilityStatChange($side, $stat, $change, $source);
        if ($change === null) {
            return;
        }
        $b = &$this->active($side);

        $old = $b['stages'][$stat];
        $new = max(-6, min(6, $old + $change));

        if ($new === $old) {
            $this->say($this->text->statChange($b, $side, $stat, $change, blocked: true));

            return;
        }

        $b['stages'][$stat] = $new;
        $this->emit('stat', ['side' => $side, 'stat' => $stat, 'change' => $new - $old]);
        $this->say($this->text->statChange($b, $side, $stat, $new - $old, blocked: false));

        // Étape 22 : Herbe Blanche, les baisses de stats sont annulées (elle est consommée)
        if ($change < 0 && $this->holds($b, 'white_herb')) {
            foreach ($b['stages'] as $key => $value) {
                if ($value < 0) {
                    $b['stages'][$key] = 0;
                }
            }
            $this->say($this->text->subject($b, $side) . " retrouve ses stats grâce à son {$b['item']['name']} !");
            unset($b);
            $this->consumeItem($side);
        }
    }

    // =====================================================================
    // PV
    // =====================================================================

    private function applyDamage(string $side, int $amount, string $cause, float $effectiveness = 1.0, bool $crit = false): void
    {
        $b = &$this->active($side);
        $b['hp'] = max(0, $b['hp'] - $amount);

        $this->emit('hp', [
            'side' => $side,
            'hp' => $b['hp'],
            'maxHp' => $b['stats']['hp'],
            'cause' => $cause,
            'effectiveness' => $effectiveness,
            'crit' => $crit,
        ]);
    }

    private function heal(string $side, int $amount, ?int $index = null): int
    {
        $b = &$this->battler($side, $index);
        $before = $b['hp'];
        $b['hp'] = min($b['stats']['hp'], $b['hp'] + $amount);

        if ($index === null || $index === $this->state[$side]['active']) {
            $this->emit('hp', ['side' => $side, 'hp' => $b['hp'], 'maxHp' => $b['stats']['hp'], 'cause' => 'heal']);
        }

        return $b['hp'] - $before;
    }

    // =====================================================================
    // Fin du tour
    // =====================================================================

    private function endOfTurn(): void
    {
        $this->weatherEndOfTurn();   // étape 23 : météo (dégâts de sable / grêle, Cuvette, Peau Sèche...)

        foreach (['player', 'foe'] as $side) {
            $b = &$this->active($side);
            unset($b['volatile']['flinch']);

            if ($b['hp'] <= 0) {
                continue;
            }
            $who = $this->text->subject($b, $side);
            $max = $b['stats']['hp'];

            // Étape 23 : Soin Poison (le poison soigne), Ignifugé (brûlure divisée par 2), Garde Magik (rien)
            if ($b['hp'] > 0 && $this->poisonHeals($b)) {
                if ($b['hp'] < $max) {
                    $this->heal($side, max(1, intdiv($max, 8)));
                    $this->announce($side, "{$who} récupère un peu de PV.");
                }
            } elseif ($b['status'] === 'burn') {
                $this->emit('status_anim', ['side' => $side, 'status' => 'burn']);
                $this->hurt($side, intdiv($max, $this->hasAbility($b, 'heatproof') ? 32 : 16), "{$who} souffre de sa brûlure !");
            } elseif ($b['status'] === 'poison') {
                $this->emit('status_anim', ['side' => $side, 'status' => 'poison']);
                $this->hurt($side, intdiv($max, 8), "{$who} souffre du poison !");
            } elseif ($b['status'] === 'bad_poison') {
                $b['toxic'] = min(15, ($b['toxic'] ?? 0) + 1);
                $this->emit('status_anim', ['side' => $side, 'status' => 'poison']);
                $this->hurt($side, intdiv($max * $b['toxic'], 16), "{$who} souffre du poison !");
            }

            // Vampigraine : la cible perd 1/8 de ses PV, le lanceur les récupère
            $other = $this->opponent($side);
            if (! empty($b['volatile']['leech_seed']) && $b['hp'] > 0 && $this->active($other)['hp'] > 0 && ! $this->hasAbility($b, 'magic-guard')) {
                $amount = min($b['hp'], max(1, intdiv($max, 8)));
                $this->applyDamage($side, $amount, 'residual');
                $root = $this->holds($this->active($other), 'big_root');   // étape 22 : Grosse Racine
                $this->heal($other, $root ? (int) floor($amount * ($root['value'] ?? 1.3)) : $amount);
                $this->say('Vampigraine draine l\'énergie ' . $this->text->of($b, $side) . ' !');
            }

            // Ligotage, Danse Flamme... : 1/8 des PV pendant 4 ou 5 tours
            if (! empty($b['volatile']['trapped']) && $b['hp'] > 0) {
                $trap = $b['volatile']['trapped'];
                $trap['turns']--;
                if ($trap['turns'] <= 0) {
                    unset($b['volatile']['trapped']);
                    $this->say("{$who} est libéré de {$trap['move']} !");
                } else {
                    $b['volatile']['trapped'] = $trap;
                    $this->hurt($side, intdiv($max, 8), "{$who} est blessé par {$trap['move']} !");
                }
            }

            // Étape 14 : Restes, 1/16 des PV max à la fin de chaque tour
            $leftovers = $this->holds($b, 'leftovers');
            if ($leftovers && $b['hp'] > 0 && $b['hp'] < $max) {
                $this->heal($side, max(1, intdiv($max, (int) ($leftovers['value'] ?? 16))));
                $this->say("{$who} récupère un peu de PV grâce aux {$b['item']['name']} !");
            }

            // Étape 22 : Boue Noire, Piquants, Orbes Toxique / Flamme
            if ($b['hp'] > 0 && $this->holds($b, 'black_sludge')) {
                if (in_array('poison', $b['types'], true)) {
                    if ($b['hp'] < $max) {
                        $this->heal($side, max(1, intdiv($max, 16)));
                        $this->say("{$who} récupère un peu de PV grâce à la {$b['item']['name']} !");
                    }
                } else {
                    $this->hurt($side, intdiv($max, 8), "{$who} est blessé par la {$b['item']['name']} !");
                }
            }
            if ($b['hp'] > 0 && $this->holds($b, 'sticky_barb')) {
                $this->hurt($side, intdiv($max, 8), "{$who} est blessé par les {$b['item']['name']} !");
            }
            if ($b['hp'] > 0 && $b['status'] === 'none' && ($this->holds($b, 'toxic_orb') || $this->holds($b, 'flame_orb'))) {
                $orbName = $b['item']['name'];
                $this->inflictAilment($side, $this->holds($b, 'toxic_orb') ? 'poison' : 'burn',
                    ['slug' => $this->holds($b, 'toxic_orb') ? 'toxic' : 'orb', 'name' => $orbName, 'meta' => []], quiet: true);
            }
            unset($b);
            $this->abilityEndOfTurn($side);   // étape 23 : Turbo, Mue, Hydratation, Mauvais Rêve...
        }
        unset($b);
        $this->checkBerries();

        // Étape 22 : Brume de la Garde-Stats (5 tours)
        foreach (['player', 'foe'] as $side) {
            if (! empty($this->state[$side]['mist']) && --$this->state[$side]['mist'] <= 0) {
                unset($this->state[$side]['mist']);
                $this->say($side === 'player' ? 'La Brume de votre équipe se dissipe !' : 'La Brume de l\'équipe adverse se dissipe !');
            }
        }

        // Protection / Mur Lumière : 5 tours
        foreach (['player', 'foe'] as $side) {
            foreach ($this->state[$side]['screens'] ?? [] as $key => $turns) {
                if (--$this->state[$side]['screens'][$key] <= 0) {
                    unset($this->state[$side]['screens'][$key]);
                    $this->say(($key === 'reflect' ? "L'effet de Protection" : "L'effet de Mur Lumière") . ' se dissipe !');
                }
            }
        }
    }

    // =====================================================================
    // K.O., expérience, Pokémon suivant
    // =====================================================================

    private function handleFaints(): void
    {
        $foe = &$this->active('foe');
        if ($foe['hp'] <= 0 && empty($foe['fainted'])) {
            $foe['fainted'] = true;
            $this->emit('faint', ['side' => 'foe']);
            $this->say($this->text->subject($foe, 'foe') . ' est K.O. !');
            if ($this->state['type'] !== 'pvp' && empty($this->state['tower'])) {   // étape 28 : pas d'XP à la Tour
                $this->giveExperience($foe);
            }
        }

        $player = &$this->active('player');
        if ($player['hp'] <= 0 && empty($player['fainted'])) {
            $player['fainted'] = true;
            $player['volatile'] = [];
            $player['faints'] = ($player['faints'] ?? 0) + 1;   // étape 22 : le Bonheur baisse après le combat
            $this->emit('faint', ['side' => 'player']);
            $this->say($this->text->subject($player, 'player') . ' est K.O. !');
            $this->state['participants'] = array_values(array_diff($this->state['participants'], [$this->state['player']['active']]));
        }
        unset($foe, $player);

        $playerCanFight = $this->firstUsable('player') !== null;
        $foeCanFight = $this->firstUsable('foe') !== null;

        if ($this->state['type'] === 'pvp') {
            $this->pvpFaints($playerCanFight, $foeCanFight);

            return;
        }

        if (! $playerCanFight) {
            $this->lose();

            return;
        }

        if ($this->active('foe')['hp'] <= 0) {
            if (! $foeCanFight) {
                $this->win();

                return;
            }
            // Le dresseur envoie son Pokémon suivant
            $next = $this->firstUsable('foe');
            $this->say("{$this->trainerName()} va envoyer {$this->state['foe']['party'][$next]['name']}.");
            $this->switchIn('foe', $next, recall: false);
        }

        if ($this->active('player')['hp'] <= 0) {
            $this->state['phase'] = 'switch';
        }
    }

    /** PvP : fin du combat, ou chaque joueur dont le Pokémon est K.O. doit en envoyer un autre */
    private function pvpFaints(bool $playerCanFight, bool $foeCanFight): void
    {
        if (! $playerCanFight && ! $foeCanFight) {
            $this->say('Les deux dresseurs n\'ont plus de Pokémon en état de se battre ! Match nul !', pause: true);
            $this->endBattle('draw');

            return;
        }
        if (! $playerCanFight) {
            $this->lose();

            return;
        }
        if (! $foeCanFight) {
            $this->win();

            return;
        }

        $need = [
            'player' => $this->active('player')['hp'] <= 0,
            'foe' => $this->active('foe')['hp'] <= 0,
        ];
        if ($need['player'] || $need['foe']) {
            $this->state['needSwitch'] = $need;
            $this->state['phase'] = 'switch';
        }
    }

    /**
     * XP pour chaque Pokémon du joueur qui a participé contre ce Pokémon, + EV.
     * Étape 14 : Multi Exp (2e à 5e génération) : si un Pokémon en état de se battre le tient,
     * la moitié de l'Exp. est partagée entre les participants et l'autre moitié entre les porteurs.
     * Œuf Chance : x1,5.
     */
    private function giveExperience(array $foe): void
    {
        $party = $this->state['player']['party'];
        $participants = array_values(array_filter(
            $this->state['participants'],
            fn ($i) => $party[$i]['hp'] > 0,
        ));
        $holders = array_keys(array_filter($party, fn ($b) => $b['hp'] > 0 && $this->holds($b, 'exp_share')));
        $trainer = $this->state['type'] === 'trainer';
        $share = $holders ? 2 : 1;

        foreach (array_values(array_unique(array_merge($participants, $holders))) as $index) {
            $b = &$this->state['player']['party'][$index];

            // EV : 510 au total, 252 par stat (étape 22 : Bracelet Macho x2, objets Pouvoir +8 dans leur stat)
            $macho = $this->holds($b, 'ev_double') ? 2 : 1;
            $power = $this->holds($b, 'ev_power');
            foreach ($foe['evYield'] as $stat => $amount) {
                $amount = $amount * $macho + (($power['stat'] ?? null) === $stat ? (int) ($power['value'] ?? 8) : 0);
                $room = min(252 - $b['evs'][$stat], 510 - array_sum($b['evs']));
                $b['evs'][$stat] += max(0, min($amount, $room));
            }

            if ($b['level'] >= 100) {
                unset($b);
                continue;
            }

            $gain = 0;
            if (in_array($index, $participants, true)) {
                $gain += Formulas::experienceGain($foe['baseExp'], $foe['level'], $b['level'], count($participants) * $share, $trainer);
            }
            if (in_array($index, $holders, true)) {
                $gain += Formulas::experienceGain($foe['baseExp'], $foe['level'], $b['level'], count($holders) * 2, $trainer);
            }
            $egg = $this->holds($b, 'exp_boost');
            if ($egg) {
                $gain = (int) floor($gain * ($egg['value'] ?? 1.5));
            }

            $this->say($egg ? "{$b['name']} gagne un bonus de {$gain} points d'Exp. !" : "{$b['name']} gagne {$gain} points d'Exp. !", pause: true);
            unset($b);

            $this->addExperience($index, $gain);
        }
    }

    private function addExperience(int $index, int $gain): void
    {
        $b = &$this->state['player']['party'][$index];
        $target = $b['exp'] + $gain;

        // On remplit la barre niveau par niveau (le navigateur l'anime de la même façon)
        while (true) {
            $nextLevelExp = Formulas::experienceForLevel($b['growth'], $b['level'] + 1);

            if ($b['level'] >= 100 || $target < $nextLevelExp) {
                $b['exp'] = $target;
                $this->emit('exp', ['index' => $index, 'exp' => $b['exp'], 'level' => $b['level']] + $this->expRange($b));
                break;
            }

            $b['exp'] = $nextLevelExp;
            $this->emit('exp', ['index' => $index, 'exp' => $b['exp'], 'level' => $b['level']] + $this->expRange($b));

            // Montée de niveau : nouvelles stats, et les PV max gagnés s'ajoutent aux PV actuels
            $oldStats = $b['stats'];
            $b['level']++;
            $b['stats'] = Formulas::stats($b);
            $b['hp'] = $b['hp'] > 0 ? $b['hp'] + ($b['stats']['hp'] - $oldStats['hp']) : 0;

            $this->emit('level_up', [
                'index' => $index,
                'level' => $b['level'],
                'hp' => $b['hp'],
                'maxHp' => $b['stats']['hp'],
                'oldStats' => $oldStats,
                'stats' => $b['stats'],
            ] + $this->expRange($b));
            $this->say("{$b['name']} monte au N. {$b['level']} !", pause: true);

            unset($b);
            $this->learnMovesAtLevel($index);
            $b = &$this->state['player']['party'][$index];
        }
    }

    /** Bornes d'XP du niveau actuel (pour la barre bleue) */
    private function expRange(array $b): array
    {
        return [
            'levelExp' => Formulas::experienceForLevel($b['growth'], $b['level']),
            'nextLevelExp' => $b['level'] >= 100 ? $b['exp'] : Formulas::experienceForLevel($b['growth'], $b['level'] + 1),
        ];
    }

    /** Attaques apprises au nouveau niveau : directement s'il reste de la place, sinon on demandera au joueur */
    private function learnMovesAtLevel(int $index): void
    {
        $b = &$this->state['player']['party'][$index];

        foreach ($b['learnset'] ?? [] as $entry) {
            if ($entry['level'] !== $b['level']) {
                continue;
            }
            $move = $entry['move'];
            if (collect($b['moves'])->contains('id', $move['id'])) {
                continue;
            }

            if (count($b['moves']) < 4) {
                $b['moves'][] = $move + ['pp' => $move['maxPp']];
                $b['movesChanged'] = true;
                $this->emit('learned', ['index' => $index]);
                $this->say("{$b['name']} apprend {$move['name']} !", pause: true);
            } else {
                $this->state['pendingMoves'][] = ['index' => $index, 'move' => $move];
            }
        }
    }

    /** Réponse du joueur à "Oublier une capacité pour apprendre X ?" */
    private function resolveLearnMove(?int $forget): void
    {
        // Étape 19 : une CS ne s'oublie pas en apprenant une capacité (seulement chez le Maître des capacités)
        $index = $this->state['pendingMoves'][0]['index'] ?? null;
        if ($forget !== null && $index !== null && ! empty($this->state['player']['party'][$index]['moves'][$forget]['hm'])) {
            throw new InvalidAction('Une CS ne peut pas être oubliée ainsi. Le Maître des capacités peut la faire oublier.');
        }
        $pending = array_shift($this->state['pendingMoves']);
        $b = &$this->state['player']['party'][$pending['index']];
        $move = $pending['move'];

        if ($forget === null || ! isset($b['moves'][$forget])) {
            $this->say("{$b['name']} n'a pas appris {$move['name']}.", pause: true);

            return;
        }

        $old = $b['moves'][$forget]['name'];
        $b['moves'][$forget] = $move + ['pp' => $move['maxPp']];
        $b['movesChanged'] = true;
        $this->say('1, 2 et... Tadaaa !');
        $this->say("{$b['name']} oublie {$old}...");
        $this->say("Et... {$b['name']} apprend {$move['name']} !", pause: true);
    }

    /** Après le tour : reste-t-il une attaque à apprendre ? Sinon, phase suivante */
    private function afterTurn(): void
    {
        if (! empty($this->state['pendingMoves'])) {
            $this->state['phase'] = 'learn_move';

            return;
        }

        if ($this->state['result'] !== null) {
            $this->state['phase'] = 'ended';

            return;
        }

        if ($this->state['phase'] === 'learn_move') {
            $this->state['phase'] = $this->active('player')['hp'] <= 0 ? 'switch' : 'command';
        }
    }

    private function win(): void
    {
        if ($this->state['type'] === 'pvp') {
            $p1 = $this->state['player']['name'];
            $p2 = $this->state['foe']['name'];
            $this->emit('trainer_return', []);
            $this->sayPov("Vous avez battu {$p2} !", "{$p1} a gagné le combat...", pause: true);
            $this->endBattle('win');

            return;
        }

        if ($this->state['type'] === 'trainer') {
            $trainer = $this->trainerName();
            // Comme dans Essentials : récompense de base x niveau le plus haut de son équipe
            $maxLevel = max(array_column($this->state['foe']['party'], 'level'));
            $money = ($this->state['foe']['reward'] ?? 0) * $maxLevel;
            // Étape 14 : Pièce Rune, si son porteur a été envoyé au combat
            foreach (array_unique($this->state['entered'] ?? []) as $i) {
                $coin = $this->holds($this->state['player']['party'][$i], 'money_boost');
                if ($coin) {
                    $money = (int) floor($money * ($coin['value'] ?? 2));
                    break;
                }
            }
            $this->state['rewards']['money'] = $money;

            $this->emit('trainer_return', []);
            $this->say("Vous avez battu {$trainer} !", pause: true);
            if (! empty($this->state['foe']['defeatText'])) {
                $this->say($this->state['foe']['defeatText'], pause: true);
            }
            if ($money > 0) {
                $this->say("Vous remportez {$money} ₽ !", pause: true);
            }
        }

        $this->endBattle('win');
    }

    private function lose(): void
    {
        if ($this->state['type'] === 'pvp') {
            $p1 = $this->state['player']['name'];
            $p2 = $this->state['foe']['name'];
            $this->sayPov("{$p2} a gagné le combat...", "Vous avez battu {$p1} !", pause: true);
            $this->endBattle('loss');

            return;
        }

        $this->say('Vous n\'avez plus de Pokémon en état de se battre !', pause: true);
        $this->endBattle('loss');
    }

    private function endBattle(string $result): void
    {
        $this->state['result'] = $result;
        $this->state['phase'] = 'ended';
        $this->emit('end', ['result' => $result]);
    }

    private function battleIsDecided(): bool
    {
        return $this->state['result'] !== null;
    }

    // =====================================================================
    // Changement de Pokémon
    // =====================================================================

    private function switchIn(string $side, int $index, bool $recall): void
    {
        $current = &$this->active($side);
        $pvp = $this->state['type'] === 'pvp';
        if ($recall) {
            if ($pvp) {
                $owner = $this->state[$side]['name'];
                $mine = "{$current['name']}, reviens !";
                $theirs = "{$owner} rappelle {$current['name']} !";
                $side === 'player' ? $this->sayPov($mine, $theirs) : $this->sayPov($theirs, $mine);
            } else {
                $this->say($side === 'player' ? "{$current['name']}, reviens !" : "{$this->trainerName()} rappelle {$current['name']} !");
            }
            $this->emit('recall', ['side' => $side]);
        }
        unset($current);
        $this->onLeave($side);   // étape 23 : Médic Nature
        $current = &$this->active($side);
        // En quittant le terrain : niveaux de stats et états temporaires remis à zéro
        $current['stages'] = $this->freshStages();
        $current['volatile'] = [];
        unset($current);

        $this->state[$side]['active'] = $index;
        $new = &$this->active($side);
        $new['stages'] = $this->freshStages();
        $new['volatile'] = [];
        $new['toxic'] = 0;

        if ($pvp) {
            $mine = "Go ! {$new['name']} !";
            $theirs = "{$this->state[$side]['name']} envoie {$new['name']} !";
            $side === 'player' ? $this->sayPov($mine, $theirs) : $this->sayPov($theirs, $mine);
        } elseif ($side === 'player') {
            $this->say("Go ! {$new['name']} !");
        }
        $this->emitSendOut($side);
        $this->onEntry($side);   // étape 23 : Intimidation, Sécheresse, Télécharge...

        if ($side === 'player') {
            if (! in_array($index, $this->state['participants'], true)) {
                $this->state['participants'][] = $index;
            }
            $this->state['entered'][] = $index; // étape 14 : Pièce Rune
        } else {
            $this->state['participants'] = [$this->state['player']['active']];
            $this->state['seen'][] = $new['speciesId']; // pour le Pokédex
        }
    }

    private function firstUsable(string $side): ?int
    {
        foreach ($this->state[$side]['party'] as $i => $b) {
            if ($b['hp'] > 0) {
                return $i;
            }
        }

        return null;
    }

    // =====================================================================
    // Fuite
    // =====================================================================

    private function tryRun(): bool
    {
        $this->state['escapeAttempts'] = ($this->state['escapeAttempts'] ?? 0) + 1;
        // Étape 14 : Boule Fumée, la fuite réussit toujours
        // Étape 23 : Fuite, la fuite réussit toujours
        $escaped = $this->holds($this->active('player'), 'flee') || $this->hasAbility($this->active('player'), 'run-away') || Formulas::canEscape(
            $this->rng,
            $this->effectiveSpeed('player'),
            $this->effectiveSpeed('foe'),
            $this->state['escapeAttempts'],
        );

        if ($escaped) {
            $this->emit('flee', []);
            $this->say('Vous prenez la fuite !');
            $this->endBattle('fled');

            return true;
        }

        $this->say('Impossible de fuir !');

        return false;
    }

    // =====================================================================
    // Objets
    // =====================================================================

    /**
     * $item : ['slug', 'name', 'effect' (effect_code), 'value' (effect_value)]
     * $target : index dans l'équipe (pour une Potion), null pour une Ball
     */
    private function useItem(array $item, ?int $target): void
    {
        $player = $this->state['player']['name'];

        if ($item['effect'] === 'catch') {
            $this->itemConsumed = true;
            $this->throwBall($item);

            return;
        }

        $index = $target ?? $this->state['player']['active'];
        if (! isset($this->state['player']['party'][$index])) {
            throw new InvalidAction('Pokémon inconnu.');
        }
        $b = $this->state['player']['party'][$index];
        $fainted = $b['hp'] <= 0;

        // Étape 22 : objets qui agissent sur le Pokémon au combat (objets X, Flûtes, Poupée...)
        if ($this->battleItem($item)) {
            return;
        }

        // Objet sans effet : on le dit, il n'est PAS consommé et le tour n'est pas perdu
        $moveSlot = $item['move'] ?? null;
        $noEffect = match ($item['effect']) {
            'heal_hp', 'heal_hp_full', 'heal_hp_percent' => $fainted || $b['hp'] >= $b['stats']['hp'],
            'full_restore' => $fainted || ($b['hp'] >= $b['stats']['hp'] && $b['status'] === 'none'),
            'cure_status' => $fainted || ! $this->curesStatus($item['slug'], $b['status'], ! empty($b['volatile']['confusion'])),
            'revive' => ! $fainted,
            // Étape 22 : Huile (une capacité), Élixir (toutes)
            'restore_pp' => $fainted || ! isset($b['moves'][$moveSlot]) || $b['moves'][$moveSlot]['pp'] >= $b['moves'][$moveSlot]['maxPp'],
            'restore_pp_all' => $fainted || ! collect($b['moves'])->contains(fn ($m) => $m['pp'] < $m['maxPp']),
            default => true,
        };
        if ($noEffect) {
            throw new InvalidAction('Cela n\'aura aucun effet.');
        }

        $this->itemConsumed = ! str_ends_with($item['slug'], '-flute');
        $this->say(str_ends_with($item['slug'], '-flute') ? "{$player} joue de la {$item['name']} !" : "{$player} utilise {$item['name']} !");
        $max = $b['stats']['hp'];
        // Étape 22 : plantes médicinales amères (le Bonheur baissera après le combat)
        if ($bitter = config("items.bitter.{$item['slug']}")) {
            $this->state['player']['party'][$index]['friendship'][] = $bitter;
        }

        switch ($item['effect']) {
            case 'heal_hp':
            case 'heal_hp_full':
            case 'heal_hp_percent':
            case 'full_restore':
                $amount = match ($item['effect']) {
                    'heal_hp' => (int) $item['value'],
                    'heal_hp_percent' => max(1, intdiv($max * (int) $item['value'], 100)),
                    default => $max,
                };
                $healed = $this->heal('player', $amount, $index);
                if ($item['effect'] === 'full_restore') {
                    $this->cure($index);
                }
                if ($healed > 0) {
                    $this->say("{$b['name']} récupère {$healed} PV !");
                }
                break;

            case 'cure_status':
                $this->cure($index);
                $this->say("{$b['name']} est guéri !");
                break;

            case 'restore_pp':
            case 'restore_pp_all':
                $p = &$this->state['player']['party'][$index];
                foreach ($p['moves'] as $i => $m) {
                    if ($item['effect'] === 'restore_pp_all' || $i === $moveSlot) {
                        $p['moves'][$i]['pp'] = min($m['maxPp'], $m['pp'] + ((int) $item['value'] ?: 10));
                    }
                }
                unset($p);
                $this->say('Les PP ' . ($item['effect'] === 'restore_pp' ? "de {$b['moves'][$moveSlot]['name']} " : '') . "de {$b['name']} sont restaurés !");
                break;

            case 'revive':
                $p = &$this->state['player']['party'][$index];
                $p['hp'] = max(1, intdiv($max * (int) ($item['value'] ?: 50), 100));
                $p['fainted'] = false;
                $p['status'] = 'none';
                unset($p);
                $this->emit('party_hp', ['index' => $index, 'hp' => $this->state['player']['party'][$index]['hp']]);
                $this->say("{$b['name']} a repris connaissance !");
                break;
        }
    }

    /** Étape 22 : liste des remèdes dans config/items.php ("cures") ; les remèdes complets soignent aussi la confusion */
    private function curesStatus(string $slug, string $status, bool $confused = false): bool
    {
        $cures = config("items.cures.{$slug}");

        return $cures ? in_array($status, $cures, true) : ($status !== 'none' || $confused);
    }

    private function cure(int $index): void
    {
        $b = &$this->state['player']['party'][$index];
        $b['status'] = 'none';
        $b['sleepTurns'] = 0;
        $b['toxic'] = 0;
        if ($index === $this->state['player']['active']) {
            unset($b['volatile']['confusion']);
            $this->emit('status', ['side' => 'player', 'status' => 'none']);
        }
    }

    /**
     * Étape 22 : objets utilisés sur le Pokémon au combat. Renvoie false si ce n'en est pas un.
     *   battle_stat (Attaque +...), dire_hit (Muscle +), guard_spec (Garde-Stats : Brume 5 tours),
     *   cure_confusion (Flûte Jaune, Baie Kika), cure_attract (Flûte Rouge), flee_wild (Poupée Pokémon, Queue Skitty)
     */
    private function battleItem(array $item): bool
    {
        $effect = $item['effect'];
        if (! in_array($effect, ['battle_stat', 'dire_hit', 'guard_spec', 'cure_confusion', 'cure_attract', 'flee_wild'], true)) {
            return false;
        }
        $player = $this->state['player']['name'];
        $side = 'player';
        $b = $this->active($side);
        $value = (int) ($item['value'] ?? 0);

        $noEffect = match ($effect) {
            'battle_stat' => ! isset($b['stages'][$item['stat'] ?? '']) || $b['stages'][$item['stat']] >= 6,
            'dire_hit' => ($b['stages']['crit'] ?? 0) >= 2,
            'guard_spec' => ! empty($this->state['player']['mist']),
            'cure_confusion' => empty($b['volatile']['confusion']),
            'cure_attract' => true,   // pas d'Attraction dans ce jeu
            'flee_wild' => false,
        };
        if ($effect === 'flee_wild' && $this->state['type'] !== 'wild') {
            throw new InvalidAction('Impossible de fuir un combat de Dresseur !');
        }
        if ($noEffect) {
            throw new InvalidAction('Cela n\'aura aucun effet.');
        }

        $flute = str_ends_with($item['slug'], '-flute');
        $this->itemConsumed = ! $flute;
        $this->say($flute ? "{$player} joue de la {$item['name']} !" : "{$player} utilise {$item['name']} !");
        $who = $this->text->subject($b, $side);

        switch ($effect) {
            case 'battle_stat':
                $this->changeStat($side, $item['stat'], max(1, $value));
                $this->state['player']['party'][$this->state['player']['active']]['friendship'][] = 'battleitem';
                break;
            case 'dire_hit':
                $this->active($side)['stages']['crit'] = max(2, $value);
                $this->say("{$who} se concentre au maximum !");
                $this->state['player']['party'][$this->state['player']['active']]['friendship'][] = 'battleitem';
                break;
            case 'guard_spec':
                $this->state['player']['mist'] = max(1, $value ?: 5);
                $this->say('Votre équipe est enveloppée de Brume !');
                $this->state['player']['party'][$this->state['player']['active']]['friendship'][] = 'battleitem';
                break;
            case 'cure_confusion':
                unset($this->active($side)['volatile']['confusion']);
                $this->say("{$who} n'est plus confus !");
                break;
            case 'flee_wild':
                $this->emit('flee', []);
                $this->say('Vous prenez la fuite !');
                $this->endBattle('fled');
                break;
        }

        return true;
    }

    /**
     * Étape 22 : le bonus d'une Poké Ball spéciale (comme Pokémon Essentials v21, règles des jeux récents).
     * Renvoie [multiplicateur, taux de capture modifié].
     */
    private function ballModifier(string $slug, array $foe, int $rate): array
    {
        $mult = (float) config("items.balls.{$slug}", 1);
        $user = $this->active('player');
        $encounter = $this->state['encounter'] ?? 'grass';
        $fishing = in_array($encounter, ['old_rod', 'good_rod', 'super_rod', 'fishing'], true);

        $bonus = match ($slug) {
            'net-ball' => array_intersect(['water', 'bug'], $foe['types']) ? $mult : 1,
            'dive-ball' => $encounter === 'surf' || $fishing ? $mult : 1,
            'nest-ball' => $foe['level'] <= 30 ? max(1, (41 - $foe['level']) / 10) : 1,
            'repeat-ball' => ! empty($foe['owned']) ? $mult : 1,
            'timer-ball' => min(4, 1 + 0.3 * max(0, ($this->state['turn'] ?? 1) - 1)),
            'dusk-ball' => ! empty($this->state['dark']) ? $mult : 1,
            'quick-ball' => ($this->state['turn'] ?? 1) <= 1 ? $mult : 1,
            'lure-ball' => $fishing ? $mult : 1,
            'moon-ball' => ! empty($foe['moonStone']) ? $mult : 1,
            'love-ball' => $user['speciesId'] === $foe['speciesId'] && $user['gender'] && $foe['gender']
                && in_array($user['gender'], ['male', 'female'], true) && $user['gender'] !== $foe['gender'] ? $mult : 1,
            'fast-ball' => ($foe['base']['speed'] ?? 0) >= 100 ? $mult : 1,
            'level-ball' => match (true) {
                $user['level'] >= 4 * $foe['level'] => 8,
                $user['level'] >= 2 * $foe['level'] => 4,
                $user['level'] > $foe['level'] => 2,
                default => 1,
            },
            default => 1,
        };

        // Masse Ball : le POIDS change le taux de capture (poids en hectogrammes, comme PokéAPI)
        if ($slug === 'heavy-ball') {
            $weight = (int) ($foe['weight'] ?? 0);
            $rate = max(1, $rate + match (true) {
                $weight >= 3000 => 30,
                $weight >= 2000 => 20,
                $weight < 1000 => -20,
                default => 0,
            });
        }

        return [$bonus, $rate];
    }

    private function throwBall(array $ball): void
    {
        $player = $this->state['player']['name'];
        $foe = &$this->active('foe');

        $this->say("{$player} lance une {$ball['name']} !");

        // On ne vole pas le Pokémon d'un dresseur
        if (! in_array($this->state['type'], ['wild', 'safari'], true)) {
            $this->emit('ball', ['ball' => $ball['slug'], 'blocked' => true, 'shakes' => 0, 'caught' => false]);
            $this->say('Le Dresseur dévie la Ball !');
            $this->say('Voler, c\'est mal !');

            return;
        }

        $bonus = ((int) ($ball['value'] ?? 10)) / 10;
        if ((int) ($ball['value'] ?? 0) >= 255) {
            $bonus = 255;
        }
        // Étape 15 : au Parc Safari, le taux change avec les Appâts et les Cailloux
        $rate = $this->state['safari']['rate'] ?? $foe['captureRate'];
        // Étape 22 : Balls spéciales (Filet Ball, Bis Ball, Chrono Ball...)
        if ($bonus < 255) {
            [$special, $rate] = $this->ballModifier($ball['slug'], $foe, (int) $rate);
            $bonus *= $special;
        }
        $result = Formulas::capture($this->rng, $foe['stats']['hp'], $foe['hp'], $rate, $bonus, $foe['status']);

        $this->emit('ball', ['ball' => $ball['slug'], 'blocked' => false] + $result);

        if ($result['caught']) {
            $this->say("Et hop ! {$foe['name']} est attrapé !", pause: true);
            $this->state['caught'] = ['ball' => $ball['slug']];
            $this->endBattle('caught');

            return;
        }

        $this->say(match ($result['shakes']) {
            0 => 'Oh non ! Le Pokémon s\'est libéré !',
            1 => 'Raah ! Ça y était presque !',
            2 => 'Aaah ! Presque !',
            default => 'Mince ! Ça y était presque !',
        });
    }

    // =====================================================================
    // Étape 15 : Parc Safari
    // =====================================================================

    /**
     * Un tour au Parc Safari. $action :
     *   ['type' => 'safari_ball']  lancer une Safari Ball
     *   ['type' => 'bait']         Appât : le Pokémon mange (il fuit moins, mais se capture plus mal)
     *   ['type' => 'rock']         Caillou : le Pokémon se fâche (il se capture mieux, mais fuit plus)
     *   ['type' => 'run']          fuite (toujours réussie)
     *
     * Comme dans la 1re génération, le taux de capture modifié par un Caillou ou un Appât le reste
     * jusqu'à la fin de la rencontre.
     *
     * $state['safari'] : balls (restantes), rate (taux de capture actuel), angry / eating (tours restants),
     *                    flee (% de fuite par point de Vitesse), ball (bonus de la Safari Ball x10)
     */
    private function safariTurn(array $action): array
    {
        $this->state['turn']++;
        $safari = &$this->state['safari'];
        $foe = $this->active('foe');
        $who = $this->text->subject($foe, 'foe');
        $player = $this->state['player']['name'];

        switch ($action['type'] ?? null) {
            case 'safari_ball':
                if ($safari['balls'] <= 0) {
                    throw new InvalidAction('Vous n\'avez plus de Safari Balls !');
                }
                $safari['balls']--;
                $this->emit('safari', ['balls' => $safari['balls']]);
                unset($safari);
                $this->throwBall(['slug' => 'safari-ball', 'name' => 'Safari Ball', 'value' => $this->state['safari']['ball'] ?? 15]);
                if ($this->state['phase'] === 'ended') {
                    return $this->events; // capturé !
                }
                break;

            case 'bait':
                // Gen. 1 / RFVF : taux divisé par 2, le Pokémon mange 1 à 5 tours
                $safari['rate'] = max(1, intdiv($safari['rate'], 2));
                $safari['eating'] = min(5, $safari['eating'] + $this->rng->int(1, 5));
                $safari['angry'] = 0;
                unset($safari);
                $this->emit('safari_throw', ['what' => 'bait']);
                $this->say("{$player} lance un Appât.");
                break;

            case 'rock':
                // Taux multiplié par 2 (255 au maximum), le Pokémon se fâche 1 à 5 tours
                $safari['rate'] = min(255, $safari['rate'] * 2);
                $safari['angry'] = min(5, $safari['angry'] + $this->rng->int(1, 5));
                $safari['eating'] = 0;
                unset($safari);
                $this->emit('safari_throw', ['what' => 'rock']);
                $this->say("{$player} lance un Caillou.");
                break;

            case 'run':
                unset($safari);
                $this->emit('flee', []);
                $this->say('Vous prenez la fuite !');
                $this->endBattle('fled');

                return $this->events;

            default:
                throw new InvalidAction('Action inconnue.');
        }

        // Le tour du Pokémon : il mange, il est en colère... et il peut s'enfuir
        $safari = &$this->state['safari'];
        $chance = $foe['stats']['speed'] * ($safari['flee'] ?? 0.4);
        if ($safari['eating'] > 0) {
            $safari['eating']--;
            $chance /= 4;
            $this->say("{$who} mange !");
        } elseif ($safari['angry'] > 0) {
            $safari['angry']--;
            $chance *= 2;
            $this->say("{$who} est en colère !");
        }
        $outOfBalls = $safari['balls'] <= 0;
        unset($safari);

        if ($this->rng->chance(min(90, $chance))) {
            $this->emit('foe_flee', []);
            $this->say("{$who} s'enfuit !", pause: true);
            $this->endBattle('fled');

            return $this->events;
        }

        if ($outOfBalls) {
            $this->say('HAUT-PARLEUR : Ding-dong ! Vous n\'avez plus de Safari Balls !', pause: true);
            $this->state['safariOver'] = 'balls';
            $this->endBattle('fled');
        }

        return $this->events;
    }

    // =====================================================================
    // Intelligence artificielle de l'adversaire
    // =====================================================================

    /** Renvoie l'index de l'attaque choisie (-1 = Lutte) */
    private function chooseFoeAction(): int
    {
        $foe = $this->active('foe');

        if (! empty($foe['volatile']['locked'])) {
            return $foe['volatile']['locked']['slot'];
        }

        $usable = array_keys(array_filter($foe['moves'], fn ($m) => $m['pp'] > 0));
        if (! $usable) {
            return -1;
        }
        // Étape 22 : objet Choix
        $lock = $foe['volatile']['choiceLock'] ?? null;
        if ($lock !== null && in_array($lock, $usable, true) && $this->holds($foe, 'choice')) {
            return $lock;
        }

        $ai = $this->state['foe']['ai'] ?? 1;
        if ($ai <= 1) {
            return $this->rng->pick($usable);
        }

        // IA 2+ : préfère les attaques qui font le plus de dégâts, sans totalement exclure les autres
        $target = $this->active('player');
        $scores = [];
        foreach ($usable as $i) {
            $m = $foe['moves'][$i];
            if ($m['category'] === 'status') {
                $useless = ($m['meta']['ailment'] ?? 'none') !== 'none' && $target['status'] !== 'none';
                $scores[$i] = $useless ? 1 : 30;
                continue;
            }
            $eff = $m['type'] ? $this->effectiveness($m['type'], $target['types']) : 1;
            $stab = in_array($m['type'], $foe['types'], true) ? 1.5 : 1;
            $scores[$i] = max(1, (int) (($m['power'] ?: 40) * $eff * $stab));
        }

        $total = array_sum($scores);
        $roll = $this->rng->int(1, $total);
        foreach ($scores as $i => $score) {
            $roll -= $score;
            if ($roll <= 0) {
                return $i;
            }
        }

        return $usable[0];
    }

    /**
     * Objet du dresseur adverse : quand son Pokémon passe sous 25 % de ses PV (IA 2 et plus),
     * il utilise un de ses objets de soin. Renvoie l'index de l'objet dans foe.items, ou null.
     */
    private function chooseFoeItem(): ?int
    {
        if ($this->state['type'] !== 'trainer' || ($this->state['foe']['ai'] ?? 1) < 2) {
            return null;
        }
        $foe = $this->active('foe');
        if ($foe['hp'] <= 0 || ! empty($foe['volatile']['locked']) || $foe['hp'] * 4 > $foe['stats']['hp']) {
            return null;
        }
        foreach ($this->state['foe']['items'] ?? [] as $i => $item) {
            if (in_array($item['effect'], ['heal_hp', 'heal_hp_full', 'heal_hp_percent', 'full_restore'], true)) {
                return $i;
            }
        }

        return null;
    }

    private function foeUseItem(int $index): void
    {
        $item = $this->state['foe']['items'][$index];
        array_splice($this->state['foe']['items'], $index, 1);

        $foe = $this->active('foe');
        $max = $foe['stats']['hp'];
        $amount = match ($item['effect']) {
            'heal_hp' => (int) $item['value'],
            'heal_hp_percent' => max(1, intdiv($max * (int) $item['value'], 100)),
            default => $max,
        };

        $this->say("{$this->trainerName()} utilise {$item['name']} !", pause: true);
        $this->heal('foe', $amount);
        if ($item['effect'] === 'full_restore' && $foe['status'] !== 'none') {
            $b = &$this->active('foe');
            $b['status'] = 'none';
            $b['sleepTurns'] = 0;
            unset($b);
            $this->emit('status', ['side' => 'foe', 'status' => 'none']);
        }
    }

    /**
     * Obéissance (comme dans Rouge Feu / Vert Feuille) : un Pokémon reçu par échange n'obéit
     * qu'en dessous d'un certain niveau, qui dépend des badges (state.player.obeyCap).
     */
    private function obeys(array $b): bool
    {
        $cap = $this->state['player']['obeyCap'] ?? 100;
        if (empty($b['traded']) || $b['level'] <= $cap) {
            return true;
        }
        $roll = $this->rng->int(0, 255);
        if (intdiv(($b['level'] + $cap) * $roll, 256) < $cap) {
            return true;
        }

        $this->say($this->rng->pick([
            "{$b['name']} n'obéit pas !",
            "{$b['name']} fait la sieste !",
            "{$b['name']} se tourne les pouces !",
            "{$b['name']} ignore les ordres !",
        ]));

        return false;
    }

    // =====================================================================
    // Outils
    // =====================================================================

    // =====================================================================
    // Étape 14 : objets tenus
    // =====================================================================

    /** L'effet de l'objet tenu s'il est de ce genre (config/held_items.php), sinon null */
    private function holds(array $b, string $effect): ?array
    {
        if ($this->hasAbility($b, 'klutz')) {
            return null;   // étape 23 : Maladresse, l'objet tenu n'a aucun effet
        }
        $e = $b['item']['effect'] ?? null;

        return is_array($e) && ($e['effect'] ?? null) === $effect ? $e : null;
    }

    /** Les Baies tenues : mangées quand les PV tombent à la moitié, ou pour soigner un statut */
    private function checkBerries(): void
    {
        foreach (['player', 'foe'] as $side) {
            $b = $this->active($side);
            $e = $b['item']['effect'] ?? null;
            if (! is_array($e) || $b['hp'] <= 0) {
                continue;
            }
            $who = $this->text->subject($b, $side);
            $name = $b['item']['name'];
            $max = $b['stats']['hp'];

            if (in_array($e['effect'], ['berry_heal', 'berry_heal_percent'], true) && $b['hp'] <= intdiv($max, 2)) {
                $amount = $e['effect'] === 'berry_heal' ? (int) $e['value'] : max(1, intdiv($max * (int) $e['value'], 100));
                $this->heal($side, $amount);
                $this->say("{$who} mange sa {$name} et récupère des PV !");
                $this->consumeItem($side);
            } elseif ($e['effect'] === 'berry_cure' && in_array($b['status'], $e['status'] ?? [], true)) {
                $active = &$this->active($side);
                $active['status'] = 'none';
                $active['sleepTurns'] = 0;
                $active['toxic'] = 0;
                if (count($e['status'] ?? []) > 1) {
                    unset($active['volatile']['confusion']);   // Baie Prine : la confusion aussi
                }
                unset($active);
                $this->emit('status', ['side' => $side, 'status' => 'none']);
                $this->say("{$who} mange sa {$name} et guérit !");
                $this->consumeItem($side);
            } else {
                $this->checkBerryGen4($side, $e);   // étape 22
            }
        }
    }

    /** Étape 22 : les Baies des générations 3 et 4 (stress, Figuy..., Mepo, Kika) */
    private function checkBerryGen4(string $side, array $e): void
    {
        $b = $this->active($side);
        $who = $this->text->subject($b, $side);
        $name = $b['item']['name'];
        $max = $b['stats']['hp'];
        // Étape 23 : Gloutonnerie, les Baies "du quart" se mangent dès la moitié des PV
        $quarter = $b['hp'] * 4 <= $max || ($this->hasAbility($b, 'gluttony') && $b['hp'] * 2 <= $max);

        switch ($e['effect']) {
            case 'pinch_berry':
                $stat = $e['stat'] ?? null;
                if (! $quarter || $stat === 'custap') {
                    return;   // la Baie Chérim agit au début du tour suivant (commandTurn)
                }
                $this->say("{$who} mange sa {$name} !");
                $this->consumeItem($side);
                if ($stat === 'crit') {
                    $this->active($side)['stages']['crit'] = min(4, ($b['stages']['crit'] ?? 0) + (int) ($e['value'] ?? 2));
                    $this->say("{$who} se concentre au maximum !");
                } elseif ($stat === 'micle') {
                    $this->active($side)['volatile']['micle'] = (float) ($e['value'] ?? 1.2);
                    $this->say("La précision de la prochaine capacité " . $this->text->of($b, $side) . ' augmente !');
                } elseif ($stat === 'random') {
                    $choices = array_values(array_filter(['attack', 'defense', 'sp_attack', 'sp_defense', 'speed'], fn ($st) => $b['stages'][$st] < 6));
                    if ($choices) {
                        $this->changeStat($side, $this->rng->pick($choices), (int) ($e['value'] ?? 2));
                    }
                } else {
                    $this->changeStat($side, $stat, (int) ($e['value'] ?? 1));
                }

                return;

            case 'confuse_berry':
                // Jeux récents : au quart des PV, rend 1/3 des PV ; confus si la nature n'aime pas ce goût
                if (! $quarter) {
                    return;
                }
                $this->heal($side, max(1, intdiv($max, 3)));
                $this->say("{$who} mange sa {$name} et récupère des PV !");
                $this->consumeItem($side);
                if (($b['nature']['down'] ?? null) === ($e['flavor'] ?? '') && empty($b['volatile']['confusion'])) {
                    $this->say("Pour {$b['name']}, c'est un goût qu'il n'aime pas !");
                    $this->inflictAilment($side, 'confusion', ['slug' => 'berry', 'name' => $name, 'meta' => []], quiet: true);
                }

                return;

            case 'leppa_berry':
                foreach ($b['moves'] as $i => $m) {
                    if ($m['pp'] <= 0) {
                        $active = &$this->active($side);
                        $active['moves'][$i]['pp'] = min($m['maxPp'], (int) ($e['value'] ?? 10));
                        unset($active);
                        $this->say("{$who} mange sa {$name} et restaure les PP de {$m['name']} !");
                        $this->consumeItem($side);

                        return;
                    }
                }

                return;

            case 'persim_berry':
                if (! empty($b['volatile']['confusion'])) {
                    unset($this->active($side)['volatile']['confusion']);
                    $this->say("{$who} mange sa {$name} et n'est plus confus !");
                    $this->consumeItem($side);
                }

                return;
        }
    }

    /** Étape 22 : après une attaque qui a fait des dégâts : Orbe Vie, Grelot Coque, Baies Jacoba / Pommo, Baie Enigma */
    private function afterDamageItems(string $side, array $move, int $damage, float $effectiveness, bool $struggle): void
    {
        if ($damage <= 0) {
            return;
        }
        $other = $this->opponent($side);
        $user = $this->active($side);
        $target = $this->active($other);

        // Baie Jacoba (physique) / Pommo (spéciale) de la cible : l'attaquant perd 1/8 de SES PV max
        $berry = $this->holds($target, 'retaliate_berry');
        if ($berry && ($berry['category'] ?? null) === $move['category'] && $user['hp'] > 0) {
            $this->applyDamage($side, max(1, intdiv($user['stats']['hp'], 8)), 'residual');
            $this->say($this->text->subject($user, $side) . " est blessé par la {$target['item']['name']} " . $this->text->of($target, $other) . ' !');
            $this->consumeItem($other);
        }
        // Baie Enigma : soigne 1/4 des PV après une attaque super efficace
        if ($effectiveness > 1 && $target['hp'] > 0 && $this->holds($target, 'enigma_berry')) {
            $this->heal($other, max(1, intdiv($target['stats']['hp'], 4)));
            $this->say($this->text->subject($target, $other) . " mange sa {$target['item']['name']} et récupère des PV !");
            $this->consumeItem($other);
        }

        $user = $this->active($side);
        if ($user['hp'] <= 0 || $struggle) {
            return;
        }
        // Grelot Coque : 1/8 des dégâts infligés
        if (($bell = $this->holds($user, 'shell_bell')) && $user['hp'] < $user['stats']['hp']) {
            $this->heal($side, max(1, intdiv($damage, (int) ($bell['value'] ?? 8))));
            $this->say($this->text->subject($user, $side) . " récupère un peu de PV grâce au {$user['item']['name']} !");
        }
        // Orbe Vie : le porteur perd 1/10 de ses PV max
        if ($this->holds($user, 'life_orb')) {
            $this->hurt($side, intdiv($user['stats']['hp'], 10), $this->text->subject($user, $side) . " perd un peu de PV à cause de l'{$user['item']['name']} !", 'recoil');
        }
    }

    /** Étape 22 : l'objet tenu réservé à une espèce (Balle Lumière pour Pikachu...), sinon null */
    private function speciesItem(array $b, string $effect): ?array
    {
        $e = $this->holds($b, $effect);

        return $e && in_array($b['slug'] ?? '', $e['species'] ?? [], true) ? $e : null;
    }

    /** Étape 22 : multiplicateur d'une stat par un objet d'espèce (Masse Os x2 en Attaque pour Osselait...) */
    private function speciesStat(array $b, string $stat): float
    {
        $e = $this->speciesItem($b, 'species_stat');

        return $e && in_array($stat, $e['stats'] ?? [], true) ? (float) ($e['value'] ?? 1) : 1.0;
    }

    /** L'objet tenu est consommé (Baie) : Laravel le retirera au Pokémon à la fin du combat */
    private function consumeItem(string $side): void
    {
        $b = &$this->active($side);
        $b['item'] = null;
        $b['itemConsumed'] = true;
        $b['volatile']['unburden'] = true;   // étape 23 : Délestage (Vitesse x2 quand l'objet est utilisé)
    }

    private function &active(string $side): array
    {
        return $this->state[$side]['party'][$this->state[$side]['active']];
    }

    private function &battler(string $side, ?int $index): array
    {
        return $this->state[$side]['party'][$index ?? $this->state[$side]['active']];
    }

    private function opponent(string $side): string
    {
        return $side === 'player' ? 'foe' : 'player';
    }

    private function trainerName(): string
    {
        return trim(($this->state['foe']['class'] ?? '') . ' ' . $this->state['foe']['name']);
    }

    private function freshStages(): array
    {
        return ['attack' => 0, 'defense' => 0, 'sp_attack' => 0, 'sp_defense' => 0, 'speed' => 0, 'accuracy' => 0, 'evasion' => 0, 'crit' => 0];
    }

    private function struggleMove(): array
    {
        return [
            'id' => 0, 'slug' => 'struggle', 'name' => 'Lutte', 'type' => null, 'category' => 'physical',
            'power' => 50, 'accuracy' => null, 'priority' => 0, 'target' => 'selected-pokemon',
            'effect' => 'damage', 'meta' => [],
        ];
    }

    /** "Envoi" d'un Pokémon : on joint ses données du moment (PV, niveau...) pour que l'écran l'affiche */
    private function emitSendOut(string $side): void
    {
        $this->emit('send_out', [
            'side' => $side,
            'index' => $this->state[$side]['active'],
            'battler' => $this->active($side),
        ]);
    }

    /**
     * Un message. $pause = true : le joueur doit appuyer pour continuer (flèche affichée) ;
     * sinon le message disparaît tout seul après un court instant, comme dans Essentials.
     */
    private function say(string $text, bool $pause = false): void
    {
        $this->events[] = ['type' => 'message', 'text' => $text] + ($pause ? ['pause' => true] : []);
    }

    /** Message différent pour chaque joueur (PvP) : $forFoe est lu par le joueur 2 */
    private function sayPov(string $forPlayer, string $forFoe, bool $pause = false): void
    {
        $this->events[] = ['type' => 'message', 'text' => $forPlayer, 'textFoe' => $forFoe] + ($pause ? ['pause' => true] : []);
    }

    private function emit(string $type, array $data): void
    {
        $this->events[] = ['type' => $type] + $data;
    }
}
