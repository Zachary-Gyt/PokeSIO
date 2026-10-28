<?php

namespace App\Battle\Concerns;

use App\Battle\BattleFactory;
use App\Battle\Formulas;
use App\Models\Move;

/**
 * Les CAPACITÉS AU FONCTIONNEMENT UNIQUE (générations 1 à 4), en combat simple.
 *
 * PokéAPI décrit la plupart des capacités avec des données génériques (dégâts, statut, baisse de stats...),
 * que BattleEngine applique tout seul. Celles d'ici ont une règle à elles :
 *   - dégâts particuliers : Faux-Chage, Riposte, Voile Miroir, Fulmifer, Patience, Triple Pied, Puissance Cachée...
 *   - protection : Abri, Détection, Ténacité, Clonage, Feinte...
 *   - pièges et blocages : Regard Noir, Entrave, Provoc, Encore, Tourmente, Embargo, Anti-Soin...
 *   - effets qui durent : Vœu, Anneau Hydro, Racines, Malédiction, Cauchemar, Requiem, Prescience...
 *   - terrain : Picots, Pics Toxik, Piège de Roc, Vent Arrière, Distorsion, Gravité, Rune Protect...
 *   - capacités qui en appellent d'autres : Métronome, Blabla Dodo, Photocopie, Mimique, Assistance...
 *   - échanges : Morphing, Copie, Gribouille, Passe-Passe, Tour de Magie, Échange, Imitation...
 *
 * Simplifications (combat simple) :
 *   - Demi-Tour et Relais : le joueur choisit son Pokémon à la fin du tour (et non au milieu).
 *   - Poursuite ne double pas sa puissance sur un Pokémon qui est rappelé.
 *   - Coup d'Main et Par Ici n'ont rien à faire sans allié.
 */
trait MoveEffects
{
    /** Capacités que Métronome, Assistance ou Blabla Dodo ne peuvent pas choisir */
    private const NOT_CALLABLE = ['metronome', 'struggle', 'sketch', 'mimic', 'counter', 'mirror-coat', 'protect', 'detect', 'endure',
        'destiny-bond', 'sleep-talk', 'assist', 'copycat', 'me-first', 'mirror-move', 'focus-punch', 'thief', 'covet', 'trick',
        'switcheroo', 'feint', 'follow-me', 'helping-hand', 'snatch', 'chatter', 'transform', 'bide', 'uproar', 'nature-power'];

    /** Capacités que Magic Coat renvoie (statut ou baisse de stats sur la cible) */
    private const REFLECTABLE = ['ailment', 'net-good-stats', 'swagger'];

    /** Capacités qui touchent un Pokémon en plein vol / sous terre / sous l'eau (et parfois x2) */
    private const HITS_SEMI_INVULNERABLE = [
        'fly' => ['gust' => 2, 'twister' => 2, 'thunder' => 1, 'sky-uppercut' => 1],
        'bounce' => ['gust' => 2, 'twister' => 2, 'thunder' => 1, 'sky-uppercut' => 1],
        'dig' => ['earthquake' => 2, 'magnitude' => 2, 'fissure' => 1],
        'dive' => ['surf' => 2, 'whirlpool' => 2],
    ];

    /** Noms des types (messages de Conversion) */
    private const TYPE_NAMES = ['normal' => 'Normal', 'fire' => 'Feu', 'water' => 'Eau', 'electric' => 'Électrik', 'grass' => 'Plante',
        'ice' => 'Glace', 'fighting' => 'Combat', 'poison' => 'Poison', 'ground' => 'Sol', 'flying' => 'Vol', 'psychic' => 'Psy',
        'bug' => 'Insecte', 'rock' => 'Roche', 'ghost' => 'Spectre', 'dragon' => 'Dragon', 'dark' => 'Ténèbres', 'steel' => 'Acier'];

    /** États « statut » de PokéAPI qui ont une règle à eux (par capacité) */
    private const SLUG_AILMENTS = ['attract' => 'infatuation', 'nightmare' => 'nightmare', 'torment' => 'torment', 'yawn' => 'yawn',
        'heal-block' => 'heal-block', 'embargo' => 'embargo', 'foresight' => 'no-type-immunity', 'odor-sleuth' => 'no-type-immunity',
        'miracle-eye' => 'no-type-immunity', 'perish-song' => 'perish-song', 'ingrain' => 'ingrain'];

    /** Durées tirées au hasard [min, max] (4e génération) */
    private const DURATIONS = ['taunt' => [3, 5], 'encore' => [4, 8], 'disable' => [4, 7]];

    // =====================================================================
    // Avant l'attaque
    // =====================================================================

    /** Le tour commence : on oublie les dégâts du tour précédent, et on note ce que chacun a choisi */
    private function startTurnEffects(array $choices): void
    {
        $this->state['turnDamage'] = ['player' => null, 'foe' => null];
        $this->state['moved'] = ['player' => false, 'foe' => false];
        $this->state['choices'] = $choices;   // ['player' => slot ou null, 'foe' => slot ou null]
        // Ce qui ne dure qu'un tour (au cas où la fin du tour précédent n'a pas eu lieu, après un K.O.)
        foreach (['player', 'foe'] as $side) {
            unset($this->state[$side]['party'][$this->state[$side]['active']]['volatile']['protected'],
                $this->state[$side]['party'][$this->state[$side]['active']]['volatile']['endure'],
                $this->state[$side]['party'][$this->state[$side]['active']]['volatile']['magicCoat'],
                $this->state[$side]['party'][$this->state[$side]['active']]['volatile']['snatch']);
        }
    }

    /**
     * Une capacité interdite en ce moment (Entrave, Provoc, Tourmente, Possessif, Anti-Soin, Gravité, Encore) :
     * renvoie la raison, ou null si elle peut être utilisée.
     */
    private function moveRestriction(array $b, array $move, int $slot, ?string $who = null): ?string
    {
        $v = $b['volatile'] ?? [];
        $name = $who ?? $b['name'];
        $slug = $move['slug'];

        if (isset($v['disabled']) && $v['disabled']['slug'] === $slug) {
            return "{$move['name']} est sous entrave !";
        }
        if (! empty($v['taunt']) && $move['category'] === 'status') {
            return "{$name} ne peut pas utiliser {$move['name']} à cause de la Provoc !";
        }
        if (! empty($v['torment']) && ($v['lastMove'] ?? null) === $slug && $slug !== 'struggle') {
            return "{$name} ne peut pas utiliser la même capacité deux fois de suite à cause de la Tourmente !";
        }
        if (! empty($v['imprisoned']) && in_array($slug, $v['imprisoned'], true)) {
            return "{$name} ne peut pas utiliser {$move['name']} à cause de Possessif !";
        }
        if (! empty($v['healBlock']) && (($move['effect'] ?? '') === 'heal' || in_array($slug, ['wish', 'rest', 'aqua-ring', 'ingrain', 'swallow', 'pain-split'], true))) {
            return "{$name} ne peut pas se soigner à cause d'Anti-Soin !";
        }
        if (! empty($this->state['field']['gravity']) && in_array($slug, ['fly', 'bounce', 'splash', 'magnet-rise', 'hi-jump-kick', 'jump-kick'], true)) {
            return "{$name} ne peut pas utiliser {$move['name']} à cause de la Gravité !";
        }
        if (isset($v['encore']) && $v['encore']['slot'] !== $slot && ($b['moves'][$v['encore']['slot']]['pp'] ?? 0) > 0) {
            return "{$name} doit utiliser {$b['moves'][$v['encore']['slot']]['name']} à cause d'Encore !";
        }
        if ($slug === 'last-resort') {
            $others = array_filter($b['moves'], fn ($m) => $m['slug'] !== 'last-resort');
            $used = $v['usedMoves'] ?? [];
            if (! $others || array_diff(array_column($others, 'slug'), $used)) {
                return 'Mais cela échoue !';
            }
        }

        return null;
    }

    /** Les emplacements d'attaque que ce Pokémon peut choisir maintenant (pour l'IA) */
    private function allowedSlots(array $b, array $slots): array
    {
        $ok = array_values(array_filter($slots, fn ($i) => $this->moveRestriction($b, $b['moves'][$i], $i) === null));

        return $ok ?: $slots;
    }

    /**
     * Abri, Détection, Clone, Reflet Magik, Saisie, Feinte, Coup Bas, Moi d'Abord, Mitra-Poing... :
     * renvoie true si l'attaque s'arrête ici (le message a été dit).
     */
    private function beforeMoveEffects(string $side, array &$move): bool
    {
        $other = $this->opponent($side);
        $user = $this->active($side);
        $target = $this->active($other);
        $slug = $move['slug'];
        $selfTarget = in_array($move['target'], self::SELF_TARGETS, true);
        $fieldMove = in_array($move['target'], ['entire-field', 'opponents-field', 'users-field'], true);

        // Mitra-Poing : échoue si le lanceur a été touché avant d'agir
        if ($slug === 'focus-punch' && ! empty($this->state['turnDamage'][$side])) {
            $this->say($this->text->subject($user, $side) . ' perd sa concentration et ne peut pas attaquer !');

            return true;
        }
        // Coup Bas : seulement si la cible prépare une attaque et n'a pas encore agi
        if ($slug === 'sucker-punch' && ! $this->targetPreparesAttack($other)) {
            $this->say('Mais cela échoue !');

            return true;
        }
        // Feinte : en 4e génération, elle ne marche que contre un Pokémon qui se protège
        if ($slug === 'feint') {
            if (empty($target['volatile']['protected'])) {
                $this->say('Mais cela échoue !');

                return true;
            }
            unset($this->active($other)['volatile']['protected']);
            $this->say($this->text->subject($target, $other) . ' tombe dans le piège de la Feinte !');
        }
        // Bluff : seulement au premier tour du lanceur sur le terrain
        if ($slug === 'fake-out' && ($user['volatile']['enteredTurn'] ?? 0) < ($this->state['turn'] ?? 1) - 1) {
            $this->say('Mais cela échoue !');

            return true;
        }
        // Ronflement : seulement en dormant
        if ($slug === 'snore' && $user['status'] !== 'sleep') {
            $this->say('Mais cela échoue !');

            return true;
        }
        // Dernier Recours : seulement quand toutes les autres capacités ont été utilisées
        if ($slug === 'last-resort' && $this->moveRestriction($user, $move, -1) !== null) {
            $this->say('Mais cela échoue !');

            return true;
        }
        // Moi d'Abord : copie l'attaque que la cible s'apprête à lancer, plus forte
        if ($slug === 'me-first') {
            $choice = $this->targetPreparesAttack($other);
            if (! $choice) {
                $this->say('Mais cela échoue !');

                return true;
            }
            $copy = $choice;
            $copy['power'] = (int) floor(($copy['power'] ?? 0) * 1.5);
            $this->callMove($side, $copy, 'Moi d\'Abord');

            return true;
        }

        // Saisie : le Pokémon qui a utilisé Saisie vole un effet sur soi-même de l'adversaire
        if ($selfTarget && $move['category'] === 'status' && ! empty($target['volatile']['snatch']) && $target['hp'] > 0) {
            unset($this->active($other)['volatile']['snatch']);
            $this->say($this->text->subject($target, $other) . " vole l'effet de {$move['name']} !");
            $this->statusMove($other, $move, true);

            return true;
        }

        if ($selfTarget || $fieldMove) {
            return false;
        }

        // Abri / Détection (Feinte et Revenant passent)
        if (! empty($target['volatile']['protected']) && ! in_array($slug, ['feint', 'shadow-force'], true)) {
            $this->say($this->text->subject($target, $other) . ' se protège !');
            unset($this->active($side)['volatile']['rampage'], $this->active($side)['volatile']['locked']);

            return true;
        }

        // Reflet Magik : les capacités de statut sont renvoyées au lanceur
        if ($move['category'] === 'status' && ! empty($target['volatile']['magicCoat'])
            && in_array($move['effect'], self::REFLECTABLE, true)) {
            $this->say($this->text->subject($target, $other) . " renvoie {$move['name']} !");
            $this->statusMove($other, $move, false);

            return true;
        }

        return false;
    }

    /** La capacité d'attaque que la cible a choisie ce tour, si elle n'a pas encore agi ; sinon null */
    private function targetPreparesAttack(string $other): ?array
    {
        if (! empty($this->state['moved'][$other])) {
            return null;
        }
        $slot = $this->state['choices'][$other] ?? null;
        $move = $slot === null ? null : ($this->active($other)['moves'][$slot] ?? null);

        return $move && $move['category'] !== 'status' ? $move : null;
    }

    /** Précision : Verrouillage / Lire-Esprit, Gravité, cible en plein Vol / Tunnel / Plongée ; null = test normal */
    private function specialAccuracy(string $side, array $move): ?bool
    {
        $user = $this->active($side);
        $target = $this->active($this->opponent($side));
        $hidden = $target['volatile']['invulnerable'] ?? null;
        if ($hidden) {
            return isset(self::HITS_SEMI_INVULNERABLE[$hidden][$move['slug']]) && ! ($hidden === 'shadow-force');
        }
        if (! empty($user['volatile']['lockOn']) && ($user['volatile']['lockOnTarget'] ?? null) === $this->state[$this->opponent($side)]['active']) {
            return true;
        }

        return null;
    }

    // =====================================================================
    // Puissance et dégâts
    // =====================================================================

    /** Puissance et type selon la situation (Façade, Saumure, Éruption, Puissance Cachée...) ; null = échec */
    private function situationalMove(string $side, array $move): ?array
    {
        $other = $this->opponent($side);
        $user = $this->active($side);
        $target = $this->active($other);
        $slug = $move['slug'];
        $hpRatio = $user['hp'] / max(1, $user['stats']['hp']);

        switch ($slug) {
            case 'facade':
                if (in_array($user['status'], ['burn', 'poison', 'bad_poison', 'paralysis'], true)) {
                    $move['power'] *= 2;
                }
                break;
            case 'brine':
                if ($target['hp'] * 2 <= $target['stats']['hp']) {
                    $move['power'] *= 2;
                }
                break;
            case 'wake-up-slap':
                if ($target['status'] === 'sleep') {
                    $move['power'] *= 2;
                }
                break;
            case 'smelling-salts':
                if ($target['status'] === 'paralysis') {
                    $move['power'] *= 2;
                }
                break;
            case 'payback':
                if (! empty($this->state['moved'][$other])) {
                    $move['power'] *= 2;
                }
                break;
            case 'avalanche':
            case 'revenge':
                if (($this->state['turnDamage'][$side]['from'] ?? null) === $other) {
                    $move['power'] *= 2;
                }
                break;
            case 'assurance':
                if (! empty($this->state['turnDamage'][$other])) {
                    $move['power'] *= 2;
                }
                break;
            case 'eruption':
            case 'water-spout':
                $move['power'] = max(1, (int) floor(150 * $hpRatio));
                break;
            case 'fury-cutter':
                $chain = ($user['volatile']['furyCutter'] ?? 0);
                $move['power'] = min(160, (int) ($move['power'] * (2 ** $chain)));
                break;
            case 'rollout':
            case 'ice-ball':
                $count = $user['volatile']['rollout']['count'] ?? 0;
                $move['power'] = (int) ($move['power'] * (2 ** $count) * (! empty($user['volatile']['defenseCurl']) ? 2 : 1));
                break;
            case 'hidden-power':
                [$move['type'], $move['power']] = $this->hiddenPower($user);
                break;
            case 'judgment':
                $plate = $user['item']['effect']['type'] ?? null;
                if (($user['item']['effect']['effect'] ?? null) === 'type_boost' && str_ends_with($user['item']['slug'] ?? '', '-plate') && $plate) {
                    $move['type'] = $plate;
                }
                break;
            case 'natural-gift':
                $berry = $user['item'] ?? null;
                if (! $berry || ! str_ends_with($berry['slug'] ?? '', '-berry') || ! empty($user['volatile']['embargo'])) {
                    return null;
                }
                [$move['type'], $move['power']] = self::NATURAL_GIFT[$berry['slug']] ?? ['normal', 60];
                $this->consumeItem($side);
                break;
            case 'fling':
                $item = $user['item'] ?? null;
                if (! $item || ! empty($user['volatile']['embargo'])) {
                    return null;
                }
                $move['power'] = self::FLING_POWER[$item['slug']] ?? (str_ends_with($item['slug'], '-berry') ? 10 : 30);
                $this->say($this->text->subject($user, $side) . " lance son objet : {$item['name']} !");
                $this->consumeItem($side);
                break;
            case 'spit-up':
                $stock = $user['volatile']['stockpile'] ?? 0;
                if ($stock <= 0) {
                    return null;
                }
                $move['power'] = 100 * $stock;
                break;
            case 'punishment':
                $up = array_sum(array_map(fn ($v) => max(0, $v), array_intersect_key($target['stages'], array_flip(['attack', 'defense', 'sp_attack', 'sp_defense', 'speed', 'accuracy', 'evasion']))));
                $move['power'] = min(200, 60 + 20 * $up);
                break;
            case 'trump-card':
                $left = 0;
                foreach ($user['moves'] as $m) {
                    if ($m['slug'] === 'trump-card') {
                        $left = $m['pp'];   // PP déjà retiré
                    }
                }
                $move['power'] = match (true) {
                    $left <= 0 => 200,
                    $left === 1 => 80,
                    $left === 2 => 60,
                    $left === 3 => 50,
                    default => 40,
                };
                break;
            case 'return':
                $move['power'] = max(1, intdiv(($user['happiness'] ?? 70) * 10, 25));
                break;
            case 'frustration':
                $move['power'] = max(1, intdiv((255 - ($user['happiness'] ?? 70)) * 10, 25));
                break;
            case 'stomp':
                if (! empty($target['volatile']['minimized'])) {
                    $move['power'] *= 2;
                }
                break;
            case 'present':
                $roll = $this->rng->int(1, 100);
                if ($roll <= 20) {
                    // Le cadeau soigne la cible
                    $healed = $this->heal($other, max(1, intdiv($target['stats']['hp'], 4)));
                    $this->say($healed > 0 ? $this->text->subject($target, $other) . ' récupère des PV !' : 'Mais ses PV sont au maximum !');

                    return ['skip' => true] + $move;
                }
                $move['power'] = $roll <= 60 ? 40 : ($roll <= 90 ? 80 : 120);
                break;
            case 'beat-up':
                $move['power'] = 10;
                $move['type'] = null;
                break;
        }

        // Pokémon en plein Vol / sous terre / sous l'eau touché par une attaque qui l'atteint quand même : x2
        $hidden = $target['volatile']['invulnerable'] ?? null;
        if ($hidden && (self::HITS_SEMI_INVULNERABLE[$hidden][$slug] ?? 1) === 2 && $move['power']) {
            $move['power'] *= 2;
        }
        // Chargeur : la prochaine capacité Électrik est deux fois plus forte
        if (! empty($user['volatile']['charged']) && $move['type'] === 'electric' && $move['power']) {
            $move['power'] *= 2;
        }
        // Lance-Boue / Tourniquet : les capacités Électrik / Feu sont affaiblies
        if ($move['type'] === 'electric' && ! empty($this->state['field']['mudSport']) && $move['power']) {
            $move['power'] = max(1, intdiv($move['power'], 2));
        }
        if ($move['type'] === 'fire' && ! empty($this->state['field']['waterSport']) && $move['power']) {
            $move['power'] = max(1, intdiv($move['power'], 2));
        }

        return $move;
    }

    /** Puissance Cachée (3e et 4e génération) : type et puissance tirés des IV */
    private function hiddenPower(array $b): array
    {
        $order = ['hp', 'attack', 'defense', 'speed', 'sp_attack', 'sp_defense'];
        $type = 0;
        $power = 0;
        foreach ($order as $i => $stat) {
            $iv = (int) ($b['ivs'][$stat] ?? 0);
            $type += ($iv & 1) << $i;
            $power += (($iv >> 1) & 1) << $i;
        }
        $types = ['fighting', 'flying', 'poison', 'ground', 'rock', 'bug', 'ghost', 'steel', 'fire', 'water', 'grass', 'electric', 'psychic', 'ice', 'dragon', 'dark'];

        return [$types[intdiv($type * 15, 63)], intdiv($power * 40, 63) + 30];
    }

    /** Riposte, Voile Miroir, Fulmifer : dégâts renvoyés ; 0 = échec */
    private function counterDamage(string $side, string $slug): int
    {
        $hit = $this->state['turnDamage'][$side] ?? null;
        if (! $hit || ($hit['from'] ?? null) !== $this->opponent($side) || $hit['amount'] <= 0) {
            return 0;
        }

        return match ($slug) {
            'counter' => $hit['category'] === 'physical' ? $hit['amount'] * 2 : 0,
            'mirror-coat' => $hit['category'] === 'special' ? $hit['amount'] * 2 : 0,
            'metal-burst' => (int) floor($hit['amount'] * 1.5),
            default => 0,
        };
    }

    /**
     * Les dégâts qui vont être infligés, corrigés par la capacité et l'état de la cible :
     * Faux-Chage et Ténacité laissent 1 PV. Renvoie [dégâts, message éventuel].
     */
    private function limitDamage(string $side, array $move, array $target, int $damage): array
    {
        if ($damage < $target['hp']) {
            return [$damage, null];
        }
        if ($move['slug'] === 'false-swipe') {
            return [max(0, $target['hp'] - 1), null];
        }
        if (! empty($target['volatile']['endure'])) {
            return [max(0, $target['hp'] - 1), $this->text->subject($target, $this->opponent($side)) . ' tient bon !'];
        }

        return [$damage, null];
    }

    /** Clone : les dégâts vont au Clone ; renvoie true s'il a encaissé le coup */
    private function hitSubstitute(string $side, array $move, int $damage): bool
    {
        $other = $this->opponent($side);
        $target = &$this->active($other);
        if (empty($target['volatile']['substitute'])) {
            return false;
        }
        $target['volatile']['substitute'] -= $damage;
        $this->say('Le clone prend les dégâts à la place ' . $this->text->of($target, $other) . ' !');
        if ($target['volatile']['substitute'] <= 0) {
            unset($target['volatile']['substitute']);
            $this->say('Le clone ' . $this->text->of($target, $other) . ' disparaît !');
        }

        return true;
    }

    /** Note un coup reçu (Riposte, Voile Miroir, Vendetta, Avalanche, Assurance, Patience, Frénésie, Conversion 2...) */
    private function recordHit(string $side, string $from, array $move, int $damage): void
    {
        if ($damage <= 0) {
            return;
        }
        $previous = $this->state['turnDamage'][$side] ?? null;
        $amount = ($previous['from'] ?? null) === $from ? $previous['amount'] + $damage : $damage;   // coups multiples
        $this->state['turnDamage'][$side] = ['amount' => $amount, 'category' => $move['category'], 'from' => $from];
        $b = &$this->active($side);
        if ($move['type']) {
            $b['volatile']['lastHitType'] = $move['type'];
        }
        if (isset($b['volatile']['bide'])) {
            $b['volatile']['bide']['damage'] += $damage;
        }
        // Frénésie : l'Attaque monte à chaque coup reçu
        if (! empty($b['volatile']['rage']) && $b['hp'] > 0) {
            unset($b);
            $this->changeStat($side, 'attack', 1);
        }
    }

    /** Après une attaque qui a touché : Lien du Destin, Rancune, Sabotage, Larcin, Demi-Tour, Tour Rapide... */
    private function afterHitEffects(string $side, array $move, int $damage, int $slot): void
    {
        $other = $this->opponent($side);
        $user = &$this->active($side);
        $target = &$this->active($other);
        $slug = $move['slug'];

        // Lien du Destin : la cible K.O. emporte le lanceur ; Rancune : la capacité perd tous ses PP
        if ($target['hp'] <= 0) {
            if (! empty($target['volatile']['destinyBond']) && $user['hp'] > 0) {
                $this->say($this->text->subject($target, $other) . ' emporte ' . $this->text->subject($user, $side, capital: false) . ' avec lui !');
                $this->applyDamage($side, $user['hp'], 'destiny');
            }
            if (! empty($target['volatile']['grudge']) && $slot >= 0 && isset($user['moves'][$slot])) {
                $user['moves'][$slot]['pp'] = 0;
                $this->say("{$move['name']} " . $this->text->of($user, $side) . ' perd tous ses PP à cause de la Rancune !');
            }
        }
        if ($damage <= 0) {
            return;
        }

        switch ($slug) {
            case 'knock-off':
                if (! empty($target['item']) && $target['hp'] >= 0 && ! $this->targetHas($target, 'sticky-hold')) {
                    $name = $target['item']['name'];
                    $this->loseItemForBattle($other);
                    $this->say($this->text->subject($user, $side) . " fait tomber l'objet {$name} " . $this->text->of($target, $other) . ' !');
                }
                break;
            case 'thief':
            case 'covet':
                if (empty($user['item']) && ! empty($target['item']) && ! $this->targetHas($target, 'sticky-hold')) {
                    $item = $target['item'];
                    $this->loseItemForBattle($other);
                    // Un objet volé à un Pokémon sauvage est gardé ; à un dresseur, il lui est rendu à la fin
                    if ($side === 'player' && $this->state['type'] === 'wild') {
                        $user['stolenItemId'] = $item['id'] ?? null;
                    } else {
                        $this->rememberItem($side);
                    }
                    $user['item'] = $item;
                    $this->say($this->text->subject($user, $side) . " vole l'objet {$item['name']} " . $this->text->of($target, $other) . ' !');
                }
                break;
            case 'bug-bite':
            case 'pluck':
                if (! empty($target['item']) && str_ends_with($target['item']['slug'] ?? '', '-berry')) {
                    $berry = $target['item'];
                    $this->loseItemForBattle($other);
                    $this->say($this->text->subject($user, $side) . " mange la {$berry['name']} " . $this->text->of($target, $other) . ' !');
                    $heal = match ($berry['slug']) {
                        'oran-berry' => 10,
                        'sitrus-berry' => intdiv($user['stats']['hp'], 4),
                        default => 0,
                    };
                    if ($heal > 0 && $user['hp'] > 0) {
                        $this->heal($side, $heal);
                    }
                }
                break;
            case 'rapid-spin':
                unset($user['volatile']['leech_seed'], $user['volatile']['trapped']);
                if (! empty($this->state[$side]['hazards'])) {
                    unset($this->state[$side]['hazards']);
                    $this->say($this->text->subject($user, $side) . ' se libère des pièges posés sur le terrain !');
                }
                break;
            case 'pay-day':
                if ($side === 'player') {
                    $this->state['payDay'] = ($this->state['payDay'] ?? 0) + 5 * $user['level'];
                    $this->say('Des pièces sont éparpillées partout !');
                }
                break;
            case 'wake-up-slap':
                if ($target['status'] === 'sleep' && $target['hp'] > 0) {
                    $target['status'] = 'none';
                    $this->emit('status', ['side' => $other, 'status' => 'none']);
                    $this->say($this->text->subject($target, $other) . ' se réveille !');
                }
                break;
            case 'smelling-salts':
                if ($target['status'] === 'paralysis' && $target['hp'] > 0) {
                    $target['status'] = 'none';
                    $this->emit('status', ['side' => $other, 'status' => 'none']);
                    $this->say($this->text->subject($target, $other) . " n'est plus paralysé !");
                }
                break;
            case 'tri-attack':
                if ($target['hp'] > 0 && $target['status'] === 'none' && $this->rng->chance(20)) {
                    unset($user, $target);
                    $this->inflictAilment($other, $this->rng->pick(['burn', 'freeze', 'paralysis']), $move, quiet: true, source: $side);
                    $user = &$this->active($side);
                }
                break;
            case 'secret-power':
                if ($target['hp'] > 0 && $this->rng->chance(30)) {
                    unset($user, $target);
                    $this->inflictAilment($other, 'paralysis', $move, quiet: true, source: $side);
                    $user = &$this->active($side);
                }
                break;
            case 'spit-up':
                $this->resetStockpile($side);
                break;
            case 'u-turn':
                unset($user, $target);
                $this->selfSwitch($side, baton: false);
                break;
        }
    }

    /** Un objet perdu pour la durée du combat seulement (Sabotage, Larcin, Picore...) */
    private function loseItemForBattle(string $side): void
    {
        $this->rememberItem($side);
        $this->active($side)['item'] = null;
    }

    /** Garde l'objet d'origine, rendu à la fin du combat (Passe-Passe, Sabotage, Larcin contre un dresseur...) */
    private function rememberItem(string $side): void
    {
        $b = &$this->active($side);
        if (empty($b['itemOriginalSet'])) {
            $b['itemOriginalSet'] = true;
            $b['itemOriginal'] = $b['item'];
            $b['itemConsumedBefore'] = ! empty($b['itemConsumed']);
        }
    }

    /** Demi-Tour, Relais : le lanceur retourne dans sa Ball (le joueur choisit son remplaçant en fin de tour) */
    private function selfSwitch(string $side, bool $baton): bool
    {
        if ($this->state['type'] === 'pvp' || $this->active($side)['hp'] <= 0) {
            return false;
        }
        $party = $this->state[$side]['party'];
        $others = array_keys(array_filter($party, fn ($b, $i) => $b['hp'] > 0 && $i !== $this->state[$side]['active'], ARRAY_FILTER_USE_BOTH));
        if (! $others) {
            return false;
        }
        if ($side === 'player') {
            $this->state['pendingSwitch'] = ['baton' => $baton];

            return true;
        }
        // Le dresseur adverse envoie son Pokémon suivant tout de suite
        $this->say("{$this->trainerName()} rappelle {$this->active('foe')['name']} !");
        $this->emit('recall', ['side' => 'foe']);
        $this->say("{$this->trainerName()} envoie {$party[$others[0]]['name']} !");
        $this->state['batonPass'] = $baton;
        $this->switchIn('foe', $others[0], recall: false);
        $this->state['batonPass'] = false;

        return true;
    }

    // =====================================================================
    // Capacités de statut au fonctionnement unique
    // =====================================================================

    /** Renvoie true si la capacité a été traitée ici */
    private function uniqueStatusMove(string $side, array $move): bool
    {
        $other = $this->opponent($side);
        $user = &$this->active($side);
        $target = &$this->active($other);
        $slug = $move['slug'];
        $who = $this->text->subject($user, $side);
        $them = $this->text->subject($target, $other);
        $fail = function () {
            $this->say('Mais cela échoue !');

            return true;
        };
        $againstFoe = ! in_array($move['target'], self::SELF_TARGETS, true) && ! in_array($move['target'], ['entire-field', 'users-field', 'opponents-field'], true);

        // Clone : les capacités de statut lancées sur un Pokémon protégé par un Clone échouent
        if ($againstFoe && ! empty($target['volatile']['substitute']) && ! in_array($slug, ['roar', 'whirlwind', 'transform', 'perish-song', 'psych-up', 'role-play', 'curse'], true)) {
            return $fail();
        }

        switch ($slug) {
            case 'protect':
            case 'detect':
            case 'endure':
                $count = $user['volatile']['protectCount'] ?? 0;
                if (! $this->rng->chance(100 / (2 ** $count))) {
                    $user['volatile']['protectCount'] = 0;

                    return $fail();
                }
                $user['volatile']['protectCount'] = $count + 1;
                if ($slug === 'endure') {
                    $user['volatile']['endure'] = true;
                    $this->say("{$who} se prépare à encaisser les coups !");
                } else {
                    $user['volatile']['protected'] = true;
                    $this->say("{$who} est prêt à se protéger !");
                }

                return true;

            case 'substitute':
                $cost = intdiv($user['stats']['hp'], 4);
                if (! empty($user['volatile']['substitute'])) {
                    $this->say("{$who} a déjà un clone !");

                    return true;
                }
                if ($user['hp'] <= $cost || $cost <= 0) {
                    $this->say('Mais il n\'a pas assez de PV pour créer un clone !');

                    return true;
                }
                $this->applyDamage($side, $cost, 'substitute');
                $user['volatile']['substitute'] = $cost + 1;
                $this->say("{$who} crée un clone !");

                return true;

            case 'mean-look':
            case 'block':
            case 'spider-web':
                if (! empty($target['volatile']['cantEscape'])) {
                    return $fail();
                }
                $target['volatile']['cantEscape'] = $side;
                $this->say("{$them} ne peut plus s'enfuir !");

                return true;

            case 'disable':
                $last = $target['volatile']['lastMove'] ?? null;
                if (! $last || isset($target['volatile']['disabled']) || $last === 'struggle') {
                    return $fail();
                }
                $name = collect($target['moves'])->firstWhere('slug', $last)['name'] ?? $last;
                $target['volatile']['disabled'] = ['slug' => $last, 'turns' => $this->rng->int(...self::DURATIONS['disable'])];
                $this->say("{$name} " . $this->text->of($target, $other) . ' est mis sous entrave !');

                return true;

            case 'taunt':
                if (! empty($target['volatile']['taunt'])) {
                    return $fail();
                }
                $target['volatile']['taunt'] = $this->rng->int(...self::DURATIONS['taunt']);
                $this->say("{$them} répond à la Provoc !");

                return true;

            case 'encore':
                $last = $target['volatile']['lastMove'] ?? null;
                $slot = collect($target['moves'])->search(fn ($m) => $m['slug'] === $last);
                if ($slot === false || isset($target['volatile']['encore']) || in_array($last, ['encore', 'mimic', 'transform', 'struggle', 'mirror-move', 'sketch'], true)
                    || ($target['moves'][$slot]['pp'] ?? 0) <= 0) {
                    return $fail();
                }
                $target['volatile']['encore'] = ['slot' => $slot, 'turns' => $this->rng->int(...self::DURATIONS['encore'])];
                $this->say("{$them} est soumis à l'Encore !");

                return true;

            case 'spite':
                $last = $target['volatile']['lastMove'] ?? null;
                $slot = collect($target['moves'])->search(fn ($m) => $m['slug'] === $last);
                if ($slot === false || $target['moves'][$slot]['pp'] <= 0) {
                    return $fail();
                }
                $lost = min(4, $target['moves'][$slot]['pp']);
                $target['moves'][$slot]['pp'] -= $lost;
                $this->say("{$target['moves'][$slot]['name']} " . $this->text->of($target, $other) . " perd {$lost} PP !");

                return true;

            case 'imprison':
                $target['volatile']['imprisoned'] = array_column($user['moves'], 'slug');
                $this->say("{$who} scelle les capacités de l'adversaire !");

                return true;

            case 'destiny-bond':
                $user['volatile']['destinyBond'] = true;
                $this->say("{$who} veut emporter son adversaire avec lui !");

                return true;

            case 'grudge':
                $user['volatile']['grudge'] = true;
                $this->say("{$who} veut que l'adversaire éprouve de la rancune !");

                return true;

            case 'lock-on':
            case 'mind-reader':
                $user['volatile']['lockOn'] = 2;
                $user['volatile']['lockOnTarget'] = $this->state[$other]['active'];
                $this->say("{$who} cible {$this->text->subject($target, $other, capital: false)} !");

                return true;

            case 'magic-coat':
                $user['volatile']['magicCoat'] = true;
                $this->say("{$who} s'entoure d'un Reflet Magik !");

                return true;

            case 'snatch':
                $user['volatile']['snatch'] = true;
                $this->say("{$who} attend que l'adversaire utilise une capacité !");

                return true;

            case 'follow-me':
                $this->say("{$who} devient le centre de l'attention !");

                return true;

            case 'helping-hand':
                return $fail();   // pas d'allié en combat simple

            case 'magnet-rise':
                if (! empty($user['volatile']['magnetRise']) || ! empty($this->state['field']['gravity'])) {
                    return $fail();
                }
                $user['volatile']['magnetRise'] = 5;
                $this->say("{$who} lévite grâce à l'électromagnétisme !");

                return true;

            case 'aqua-ring':
                if (! empty($user['volatile']['aquaRing'])) {
                    return $fail();
                }
                $user['volatile']['aquaRing'] = true;
                $this->say("{$who} s'entoure d'un voile d'eau !");

                return true;

            case 'wish':
                if (! empty($this->state[$side]['wish'])) {
                    return $fail();
                }
                $this->state[$side]['wish'] = ['turns' => 2, 'amount' => max(1, intdiv($user['stats']['hp'], 2)), 'name' => $user['name']];
                $this->say("{$who} fait un vœu !");

                return true;

            case 'healing-wish':
            case 'lunar-dance':
                if (! $this->hasReplacement($side)) {
                    return $fail();
                }
                $this->state[$side]['healingWish'] = true;
                $this->applyDamage($side, $user['hp'], 'sacrifice');

                return true;

            case 'memento':
                $this->applyDamage($side, $user['hp'], 'sacrifice');
                unset($user, $target);
                $this->changeStat($other, 'attack', -2, $side);
                $this->changeStat($other, 'sp_attack', -2, $side);

                return true;

            case 'belly-drum':
                $cost = intdiv($user['stats']['hp'], 2);
                if ($user['hp'] <= $cost || $user['stages']['attack'] >= 6) {
                    return $fail();
                }
                $this->applyDamage($side, $cost, 'belly-drum');
                $user['stages']['attack'] = 6;
                $this->emit('stat', ['side' => $side, 'stat' => 'attack', 'change' => 6]);
                $this->say("{$who} sacrifie des PV et monte son Attaque au maximum !");

                return true;

            case 'curse':
                if (in_array('ghost', $user['types'], true)) {
                    if (! empty($target['volatile']['curse'])) {
                        return $fail();
                    }
                    $this->applyDamage($side, max(1, intdiv($user['stats']['hp'], 2)), 'curse');
                    $target['volatile']['curse'] = true;
                    $this->say("{$who} sacrifie des PV et maudit {$this->text->subject($target, $other, capital: false)} !");
                } else {
                    unset($user, $target);
                    $this->changeStat($side, 'speed', -1);
                    $this->changeStat($side, 'attack', 1);
                    $this->changeStat($side, 'defense', 1);
                }

                return true;

            case 'pain-split':
                $total = $user['hp'] + $target['hp'];
                $each = intdiv($total, 2);
                $userHp = min($user['stats']['hp'], $each);
                $targetHp = min($target['stats']['hp'], $each);
                $this->setHp($side, $userHp);
                $this->setHp($other, $targetHp);
                $this->say('Les deux Pokémon partagent leurs PV !');

                return true;

            case 'psych-up':
                $user['stages'] = $target['stages'];
                $this->say("{$who} copie les changements de stats de {$this->text->subject($target, $other, capital: false)} !");

                return true;

            case 'guard-swap':
            case 'power-swap':
            case 'heart-swap':
                $stats = ['guard-swap' => ['defense', 'sp_defense'], 'power-swap' => ['attack', 'sp_attack'], 'heart-swap' => array_keys($user['stages'])][$slug];
                foreach ($stats as $stat) {
                    [$user['stages'][$stat], $target['stages'][$stat]] = [$target['stages'][$stat] ?? 0, $user['stages'][$stat] ?? 0];
                }
                $this->say("{$who} échange ses changements de stats avec la cible !");

                return true;

            case 'power-trick':
                $this->rememberOriginal($side);
                [$user['stats']['attack'], $user['stats']['defense']] = [$user['stats']['defense'], $user['stats']['attack']];
                $this->say("{$who} échange son Attaque et sa Défense !");

                return true;

            case 'acupressure':
                $choices = array_values(array_filter(['attack', 'defense', 'sp_attack', 'sp_defense', 'speed', 'accuracy', 'evasion'], fn ($s) => $user['stages'][$s] < 6));
                if (! $choices) {
                    return $fail();
                }
                unset($user, $target);
                $this->changeStat($side, $this->rng->pick($choices), 2);

                return true;

            case 'stockpile':
                $stock = $user['volatile']['stockpile'] ?? 0;
                if ($stock >= 3) {
                    return $fail();
                }
                $user['volatile']['stockpile'] = $stock + 1;
                $this->say("{$who} stocke {$user['volatile']['stockpile']} !");
                unset($user, $target);
                $this->changeStat($side, 'defense', 1);
                $this->changeStat($side, 'sp_defense', 1);
                $this->active($side)['volatile']['stockpileBoost'] = ($this->active($side)['volatile']['stockpileBoost'] ?? 0) + 1;

                return true;

            case 'swallow':
                $stock = $user['volatile']['stockpile'] ?? 0;
                if ($stock <= 0 || $user['hp'] >= $user['stats']['hp']) {
                    return $fail();
                }
                $this->heal($side, max(1, intdiv($user['stats']['hp'] * [1 => 25, 2 => 50, 3 => 100][$stock], 100)));
                $this->say("{$who} récupère des PV !");
                unset($user, $target);
                $this->resetStockpile($side);

                return true;

            case 'refresh':
                if (! in_array($user['status'], ['burn', 'poison', 'bad_poison', 'paralysis'], true)) {
                    return $fail();
                }
                $user['status'] = 'none';
                $this->emit('status', ['side' => $side, 'status' => 'none']);
                $this->say("{$who} retrouve un statut normal !");

                return true;

            case 'heal-bell':
            case 'aromatherapy':
                foreach ($this->state[$side]['party'] as $i => $b) {
                    if ($b['hp'] > 0 && $b['status'] !== 'none') {
                        $this->state[$side]['party'][$i]['status'] = 'none';
                    }
                }
                $this->emit('status', ['side' => $side, 'status' => 'none']);
                $this->say($slug === 'heal-bell' ? 'Un carillon retentit !' : 'Un parfum apaisant se répand !');
                $this->say("Les problèmes de statut de l'équipe sont soignés !");

                return true;

            case 'psycho-shift':
                if ($user['status'] === 'none' || $target['status'] !== 'none') {
                    return $fail();
                }
                $status = $user['status'];
                $user['status'] = 'none';
                $this->emit('status', ['side' => $side, 'status' => 'none']);
                unset($user, $target);
                $this->inflictAilment($other, $status === 'bad_poison' ? 'poison' : $status, ['slug' => $status === 'bad_poison' ? 'toxic' : 'psycho-shift', 'name' => $move['name'], 'meta' => []], quiet: false, source: $side);

                return true;

            case 'conversion':
                $types = array_values(array_filter(array_unique(array_column($user['moves'], 'type')), fn ($t) => $t && ! in_array($t, $user['types'], true)));
                if (! $types) {
                    return $fail();
                }
                $this->rememberOriginal($side);
                $user['types'] = [$types[0]];
                $this->say("{$who} devient de type " . (self::TYPE_NAMES[$types[0]] ?? $types[0]) . ' !');

                return true;

            case 'conversion-2':
                $hitType = $user['volatile']['lastHitType'] ?? null;
                if (! $hitType) {
                    return $fail();
                }
                $resists = array_values(array_filter(array_keys($this->typeChart), fn ($t) => ($this->typeChart[$hitType][$t] ?? 1) < 1));
                if (! $resists) {
                    return $fail();
                }
                $this->rememberOriginal($side);
                $user['types'] = [$this->rng->pick($resists)];
                $this->say("{$who} devient de type " . (self::TYPE_NAMES[$user['types'][0]] ?? $user['types'][0]) . ' !');

                return true;

            case 'camouflage':
                $this->rememberOriginal($side);
                $user['types'] = ['normal'];
                $this->say("{$who} devient de type Normal !");

                return true;

            case 'role-play':
                if (empty($target['ability']) || $target['ability'] === ($user['ability'] ?? null) || $target['ability'] === 'wonder-guard') {
                    return $fail();
                }
                $this->rememberOriginal($side);
                [$user['ability'], $user['abilityName']] = [$target['ability'], $target['abilityName']];
                $this->say("{$who} copie le talent {$target['abilityName']} !");

                return true;

            case 'skill-swap':
                if (empty($target['ability']) || empty($user['ability']) || in_array('wonder-guard', [$user['ability'], $target['ability']], true)) {
                    return $fail();
                }
                $this->rememberOriginal($side);
                $this->rememberOriginal($other);
                [$user['ability'], $target['ability']] = [$target['ability'], $user['ability']];
                [$user['abilityName'], $target['abilityName']] = [$target['abilityName'], $user['abilityName']];
                $this->say("{$who} échange son talent avec la cible !");

                return true;

            case 'worry-seed':
                if (in_array($target['ability'] ?? '', ['insomnia', 'truant'], true)) {
                    return $fail();
                }
                $this->rememberOriginal($other);
                [$target['ability'], $target['abilityName']] = ['insomnia', 'Insomnia'];
                $this->say("{$them} obtient le talent Insomnia !");

                return true;

            case 'gastro-acid':
                if (empty($target['ability']) || ! empty($target['volatile']['abilitySuppressed'])) {
                    return $fail();
                }
                $target['volatile']['abilitySuppressed'] = true;
                $this->say("Le talent " . $this->text->of($target, $other) . ' est neutralisé !');

                return true;

            case 'recycle':
                if (! empty($user['item']) || empty($user['lastItem'])) {
                    return $fail();
                }
                $user['item'] = $user['lastItem'];
                unset($user['lastItem'], $user['itemConsumed']);
                $this->say("{$who} recycle son objet {$user['item']['name']} !");

                return true;

            case 'trick':
            case 'switcheroo':
                if ((empty($user['item']) && empty($target['item'])) || $this->targetHas($target, 'sticky-hold') || ! empty($target['volatile']['substitute'])) {
                    return $fail();
                }
                $this->rememberItem($side);
                $this->rememberItem($other);
                [$user['item'], $target['item']] = [$target['item'], $user['item']];
                $this->say("{$who} échange son objet avec la cible !");

                return true;

            case 'transform':
                if (! empty($user['volatile']['transformed']) || ! empty($target['volatile']['transformed'])) {
                    return $fail();
                }
                $this->rememberOriginal($side);
                foreach (['types', 'ability', 'abilityName', 'weight'] as $key) {
                    $user[$key] = $target[$key];
                }
                foreach (['attack', 'defense', 'sp_attack', 'sp_defense', 'speed'] as $stat) {
                    $user['stats'][$stat] = $target['stats'][$stat];
                }
                $user['stages'] = $target['stages'];
                $user['moves'] = array_map(fn ($m) => ['pp' => 5, 'maxPp' => 5] + $m, $target['moves']);
                $user['volatile']['transformed'] = true;
                $this->say("{$who} se transforme en {$target['speciesName']} !");

                return true;

            case 'mimic':
            case 'sketch':
                $last = $target['volatile']['lastMove'] ?? null;
                $known = array_column($user['moves'], 'slug');
                if (! $last || in_array($last, $known, true) || in_array($last, ['struggle', 'mimic', 'sketch', 'transform', 'metronome', 'chatter'], true)) {
                    return $fail();
                }
                $copy = $this->moveBySlug($last);
                $slot = collect($user['moves'])->search(fn ($m) => $m['slug'] === $slug);
                if (! $copy || $slot === false) {
                    return $fail();
                }
                if ($slug === 'sketch' && $side === 'player' && ! empty($user['id'])) {
                    // Gribouille : la capacité est apprise pour toujours
                    $user['moves'][$slot] = $copy;
                    $user['movesChanged'] = true;
                } else {
                    $this->rememberOriginal($side);
                    $user['moves'][$slot] = ['pp' => 5, 'maxPp' => 5] + $copy;
                }
                $this->say("{$who} apprend {$copy['name']} !");

                return true;

            case 'metronome':
                $called = $this->randomMove();
                if (! $called) {
                    return $fail();
                }
                $this->callMove($side, $called, 'Métronome');

                return true;

            case 'assist':
                $pool = [];
                foreach ($this->state[$side]['party'] as $i => $b) {
                    if ($i === $this->state[$side]['active']) {
                        continue;
                    }
                    foreach ($b['moves'] as $m) {
                        if (! in_array($m['slug'], self::NOT_CALLABLE, true) && ! isset(self::CHARGE_MOVES[$m['slug']])) {
                            $pool[] = $m;
                        }
                    }
                }
                if (! $pool) {
                    return $fail();
                }
                $this->callMove($side, $this->rng->pick($pool), 'Assistance');

                return true;

            case 'sleep-talk':
                if ($user['status'] !== 'sleep') {
                    return $fail();
                }
                $pool = array_values(array_filter($user['moves'], fn ($m) => ! in_array($m['slug'], self::NOT_CALLABLE, true) && ! isset(self::CHARGE_MOVES[$m['slug']])));
                if (! $pool) {
                    return $fail();
                }
                $this->callMove($side, $this->rng->pick($pool), 'Blabla Dodo');

                return true;

            case 'copycat':
                $last = $this->state['lastMoveAny'] ?? null;
                $copy = $last && ! in_array($last, ['copycat', 'struggle'], true) ? $this->moveBySlug($last) : null;
                if (! $copy) {
                    return $fail();
                }
                $this->callMove($side, $copy, 'Photocopie');

                return true;

            case 'mirror-move':
                $last = $target['volatile']['lastMove'] ?? null;
                $copy = $last && ! in_array($last, self::NOT_CALLABLE, true) ? $this->moveBySlug($last) : null;
                if (! $copy) {
                    return $fail();
                }
                $this->callMove($side, $copy, 'Mimique');

                return true;

            case 'nature-power':
                $copy = $this->moveBySlug('tri-attack');
                if (! $copy) {
                    return $fail();
                }
                $this->say("Force Nature devient {$copy['name']} !");
                $this->callMove($side, $copy, null);

                return true;

            // ---- terrain ----
            case 'spikes':
            case 'toxic-spikes':
            case 'stealth-rock':
                $max = ['spikes' => 3, 'toxic-spikes' => 2, 'stealth-rock' => 1][$slug];
                $key = ['spikes' => 'spikes', 'toxic-spikes' => 'toxicSpikes', 'stealth-rock' => 'stealthRock'][$slug];
                $layers = $this->state[$other]['hazards'][$key] ?? 0;
                if ($layers >= $max) {
                    return $fail();
                }
                $this->state[$other]['hazards'][$key] = $layers + 1;
                $this->say(match ($slug) {
                    'spikes' => 'Des picots sont éparpillés autour de l\'équipe adverse !',
                    'toxic-spikes' => 'Des pics toxiques sont éparpillés autour de l\'équipe adverse !',
                    default => 'Des pierres pointues lévitent autour de l\'équipe adverse !',
                }, );

                return true;

            case 'tailwind':
                if (! empty($this->state[$side]['tailwind'])) {
                    return $fail();
                }
                $this->state[$side]['tailwind'] = 3;
                $this->say('Un vent arrière souffle derrière l\'équipe ' . ($side === 'player' ? 'du joueur' : 'adverse') . ' !');

                return true;

            case 'safeguard':
            case 'lucky-chant':
            case 'mist':
                $key = ['safeguard' => 'safeguard', 'lucky-chant' => 'luckyChant', 'mist' => 'mist'][$slug];
                if (! empty($this->state[$side][$key])) {
                    return $fail();
                }
                $this->state[$side][$key] = 5;
                $this->say(match ($slug) {
                    'safeguard' => 'L\'équipe est protégée par un voile mystérieux !',
                    'lucky-chant' => 'L\'Air Veinard protège l\'équipe des coups critiques !',
                    default => 'L\'équipe s\'entoure de Brume !',
                });

                return true;

            case 'gravity':
            case 'trick-room':
            case 'mud-sport':
            case 'water-sport':
                $key = ['gravity' => 'gravity', 'trick-room' => 'trickRoom', 'mud-sport' => 'mudSport', 'water-sport' => 'waterSport'][$slug];
                if ($slug === 'trick-room' && ! empty($this->state['field']['trickRoom'])) {
                    unset($this->state['field']['trickRoom']);
                    $this->say('Les dimensions redeviennent normales !');

                    return true;
                }
                if (! empty($this->state['field'][$key])) {
                    return $fail();
                }
                $this->state['field'][$key] = 5;
                $this->say(match ($slug) {
                    'gravity' => 'La gravité s\'intensifie !',
                    'trick-room' => "{$who} fausse les dimensions !",
                    'mud-sport' => 'Les capacités Électrik sont affaiblies !',
                    default => 'Les capacités Feu sont affaiblies !',
                });
                if ($slug === 'gravity') {
                    foreach (['player', 'foe'] as $s) {
                        unset($this->state[$s]['party'][$this->state[$s]['active']]['volatile']['magnetRise']);
                    }
                }

                return true;

            case 'defog':
                foreach (['screens', 'safeguard', 'mist', 'hazards'] as $key) {
                    unset($this->state[$other][$key]);
                }
                unset($user, $target);
                $this->changeStat($other, 'evasion', -1, $side);

                return true;

            case 'baton-pass':
                if (! $this->selfSwitch($side, baton: true)) {
                    return $fail();
                }

                return true;

            case 'roar':
            case 'whirlwind':
                return $this->forceSwitch($side, $move);

            case 'captivate':
                if (! $this->oppositeGenders($user, $target) || $this->targetHas($target, 'oblivious')) {
                    return $fail();
                }

                return false;   // la baisse d'Attaque Spéciale est appliquée normalement

            case 'flatter':
                unset($user, $target);
                $this->changeStat($other, 'sp_attack', 1, $side);
                $this->inflictAilment($other, 'confusion', $move, quiet: false, source: $side);

                return true;

            case 'minimize':
                $user['volatile']['minimized'] = true;

                return false;   // + Esquive, appliquée normalement

            case 'defense-curl':
                $user['volatile']['defenseCurl'] = true;

                return false;

            case 'charge':
                $user['volatile']['charged'] = 2;
                $this->say("{$who} se charge d'électricité !");

                return false;

            case 'roost':
                // Atterrissage : le lanceur perd son type Vol jusqu'à la fin du tour
                if (in_array('flying', $user['types'], true) && $user['hp'] < $user['stats']['hp']) {
                    $user['volatile']['roostTypes'] = $user['types'];
                    $user['types'] = array_values(array_diff($user['types'], ['flying'])) ?: ['normal'];
                }

                return false;

            case 'bide':
                return false;
        }

        // ---- états de la cible donnés par PokéAPI comme « statut » ----
        $ailment = self::SLUG_AILMENTS[$slug] ?? ($move['meta']['ailment'] ?? 'none');
        switch ($ailment) {
            case 'infatuation':
                if (! $this->oppositeGenders($user, $target) || ! empty($target['volatile']['attract']) || $this->targetHas($target, 'oblivious')) {
                    return $fail();
                }
                $target['volatile']['attract'] = $side;
                $this->say("{$them} tombe amoureux !");

                return true;

            case 'nightmare':
                if ($target['status'] !== 'sleep' || ! empty($target['volatile']['nightmare'])) {
                    return $fail();
                }
                $target['volatile']['nightmare'] = true;
                $this->say("{$them} commence à cauchemarder !");

                return true;

            case 'torment':
                if (! empty($target['volatile']['torment'])) {
                    return $fail();
                }
                $target['volatile']['torment'] = true;
                $this->say("{$them} est tourmenté !");

                return true;

            case 'yawn':
                if ($target['status'] !== 'none' || isset($target['volatile']['yawn']) || ! empty($this->state[$other]['safeguard'])) {
                    return $fail();
                }
                $target['volatile']['yawn'] = 2;
                $this->say("{$who} rend {$this->text->subject($target, $other, capital: false)} somnolent !");

                return true;

            case 'heal-block':
                if (! empty($target['volatile']['healBlock'])) {
                    return $fail();
                }
                $target['volatile']['healBlock'] = 5;
                $this->say("{$them} ne peut plus se soigner !");

                return true;

            case 'embargo':
                if (! empty($target['volatile']['embargo'])) {
                    return $fail();
                }
                $target['volatile']['embargo'] = 5;
                $this->say("{$them} ne peut plus utiliser d'objets !");

                return true;

            case 'no-type-immunity':
                $target['volatile']['identified'] = $slug === 'miracle-eye' ? 'dark' : 'ghost';
                $target['stages']['evasion'] = min(0, $target['stages']['evasion']);
                $this->say("{$them} est identifié !");

                return true;

            case 'perish-song':
                $any = false;
                foreach ([$side, $other] as $s) {
                    $b = &$this->active($s);
                    if ($b['hp'] > 0 && ! isset($b['volatile']['perish']) && ! $this->hasAbility($b, 'soundproof')) {
                        $b['volatile']['perish'] = 4;
                        $any = true;
                    }
                    unset($b);
                }
                if (! $any) {
                    return $fail();
                }
                $this->say('Les Pokémon qui entendent le Requiem seront K.O. dans trois tours !');

                return true;

            case 'ingrain':
                if (! empty($user['volatile']['ingrain'])) {
                    return $fail();
                }
                $user['volatile']['ingrain'] = true;
                $this->say("{$who} plante ses racines !");

                return true;
        }

        return false;
    }

    /** Hurlement, Cyclone : un Pokémon sauvage s'enfuit, un dresseur doit changer de Pokémon */
    private function forceSwitch(string $side, array $move): bool
    {
        $other = $this->opponent($side);
        $target = $this->active($other);
        if ($this->targetHas($target, 'suction-cups') || ! empty($target['volatile']['ingrain'])) {
            $this->say($this->text->subject($target, $other) . ' s\'accroche au sol !');

            return true;
        }
        if ($this->state['type'] === 'wild') {
            if ($side === 'player' && $this->active('player')['level'] < $target['level']) {
                $this->say('Mais cela échoue !');

                return true;
            }
            $this->emit('flee', []);
            $this->say($side === 'player' ? $this->text->subject($target, $other) . ' prend la fuite !' : 'Vous êtes chassé du combat !');
            $this->endBattle('fled');

            return true;
        }
        $choices = array_keys(array_filter($this->state[$other]['party'], fn ($b, $i) => $b['hp'] > 0 && $i !== $this->state[$other]['active'], ARRAY_FILTER_USE_BOTH));
        if (! $choices) {
            $this->say('Mais cela échoue !');

            return true;
        }
        $next = $this->rng->pick($choices);
        $this->emit('recall', ['side' => $other]);
        $this->say($this->text->subject($this->state[$other]['party'][$next], $other) . ' est entraîné au combat !');
        $this->switchIn($other, $next, recall: false);

        return true;
    }

    /** Une capacité appelée par une autre (Métronome, Blabla Dodo, Photocopie, Mimique...) */
    private function callMove(string $side, array $move, ?string $by): void
    {
        $move['pp'] = $move['pp'] ?? 1;
        if ($by) {
            $this->say("{$by} devient {$move['name']} !");
        }
        $this->state['calling'] = true;
        $this->resolveMove($side, -1, $move, false);
        $this->state['calling'] = false;
    }

    /** Une capacité de la base, au format du moteur ; null si elle n'existe pas */
    private function moveBySlug(string $slug): ?array
    {
        $model = Move::where('slug', $slug)->first();

        return $model ? app(BattleFactory::class)->moveData($model, $model->pp, $model->pp) : null;
    }

    /** Métronome : une capacité au hasard (générations 1 à 4) */
    private function randomMove(): ?array
    {
        $slugs = Move::where('generation', '<=', 4)->whereNotIn('slug', self::NOT_CALLABLE)->pluck('slug')->all();
        if (! $slugs) {
            return null;
        }

        return $this->moveBySlug($this->rng->pick($slugs));
    }

    // =====================================================================
    // Pendant et après le tour
    // =====================================================================

    /** Attirance (50 % de ne pas attaquer) ; renvoie false si le Pokémon ne peut pas agir */
    private function attractCheck(string $side): bool
    {
        $b = $this->active($side);
        $from = $b['volatile']['attract'] ?? null;
        if (! $from) {
            return true;
        }
        $who = $this->text->subject($b, $side);
        $this->say("{$who} est amoureux de {$this->text->subject($this->active($from), $from, capital: false)} !");
        if ($this->rng->chance(50)) {
            $this->say("{$who} est trop amoureux pour attaquer !");

            return false;
        }

        return true;
    }

    /** Après chaque capacité utilisée : compteurs (Taillade, Roulade, Abri...) */
    private function afterMoveUsed(string $side, array $move, bool $hit): void
    {
        $b = &$this->active($side);
        $slug = $move['slug'];
        $this->state['moved'][$side] = true;
        $this->state['lastMoveAny'] = $slug;
        $b['volatile']['usedMoves'] = array_values(array_unique([...($b['volatile']['usedMoves'] ?? []), $slug]));

        if (! in_array($slug, ['protect', 'detect', 'endure'], true)) {
            unset($b['volatile']['protectCount']);
        }
        if ($slug !== 'destiny-bond') {
            unset($b['volatile']['destinyBond']);
        }
        if ($slug !== 'grudge') {
            unset($b['volatile']['grudge']);
        }
        $b['volatile']['furyCutter'] = $slug === 'fury-cutter' && $hit ? min(4, ($b['volatile']['furyCutter'] ?? 0) + 1) : 0;
        // Patience libérée (ou ratée) : c'est fini
        if ($slug === 'bide' && ($b['volatile']['bide']['turns'] ?? 1) <= 0) {
            unset($b['volatile']['bide'], $b['volatile']['locked']);
        }
        if ($slug === 'rage') {
            $b['volatile']['rage'] = true;
        } else {
            unset($b['volatile']['rage']);
        }
        if (! empty($b['volatile']['charged']) && $move['type'] === 'electric' && $slug !== 'charge') {
            unset($b['volatile']['charged']);
        }
        if (! empty($b['volatile']['lockOn']) && ! in_array($slug, ['lock-on', 'mind-reader'], true)) {
            unset($b['volatile']['lockOn'], $b['volatile']['lockOnTarget']);
        }
        // Roulade / Ball'Glace : 5 tours d'affilée, de plus en plus forts
        if (in_array($slug, ['rollout', 'ice-ball'], true)) {
            $count = ($b['volatile']['rollout']['count'] ?? 0) + 1;
            $slot = collect($b['moves'])->search(fn ($m) => $m['slug'] === $slug);
            if ($hit && $count < 5 && $slot !== false) {
                $b['volatile']['rollout'] = ['count' => $count];
                $b['volatile']['locked'] = ['slot' => $slot];
            } else {
                unset($b['volatile']['rollout'], $b['volatile']['locked']);
            }
        }
    }

    /** Patience : 2 tours à encaisser, puis le double des dégâts reçus. Renvoie true si le tour est pris par Patience */
    private function bideTurn(string $side, int $slot): bool
    {
        $b = &$this->active($side);
        if (! isset($b['volatile']['bide'])) {
            $b['volatile']['bide'] = ['turns' => 2, 'damage' => 0];
            $b['volatile']['locked'] = ['slot' => $slot];
            $this->say($this->text->subject($b, $side) . ' se prépare à encaisser les coups !');

            return true;
        }
        $b['volatile']['bide']['turns']--;
        if ($b['volatile']['bide']['turns'] > 0) {
            $this->say($this->text->subject($b, $side) . ' encaisse les coups !');

            return true;
        }

        return false;   // 3e tour : la Patience se libère (dégâts dans damagingMove)
    }

    /** Fin du tour : Vœu, Anneau Hydro, Racines, Cauchemar, Malédiction, Bâillement, Requiem, Prescience, durées... */
    private function moveEffectsEndOfTurn(): void
    {
        foreach (['player', 'foe'] as $side) {
            // Prescience / Vœu Destructeur
            if (! empty($this->state[$side]['future'])) {
                $f = &$this->state[$side]['future'];
                $f['turns']--;
                if ($f['turns'] <= 0) {
                    $hit = $f;
                    unset($this->state[$side]['future']);
                    $target = $this->active($side);
                    if ($target['hp'] > 0) {
                        $this->say($this->text->subject($target, $side) . " subit l'attaque {$hit['name']} !");
                        $this->applyDamage($side, min($target['hp'], $hit['damage']), 'damage');
                    }
                }
                unset($f);
            }

            // Vœu
            if (! empty($this->state[$side]['wish'])) {
                $this->state[$side]['wish']['turns']--;
                if ($this->state[$side]['wish']['turns'] <= 0) {
                    $wish = $this->state[$side]['wish'];
                    unset($this->state[$side]['wish']);
                    $b = $this->active($side);
                    if ($b['hp'] > 0 && $b['hp'] < $b['stats']['hp']) {
                        $this->heal($side, $wish['amount']);
                        $this->say("Le vœu de {$wish['name']} se réalise !");
                    }
                }
            }

            $b = &$this->active($side);
            if ($b['hp'] <= 0) {
                unset($b);
                continue;
            }
            $who = $this->text->subject($b, $side);
            $max = $b['stats']['hp'];
            $other = $this->opponent($side);

            if ((! empty($b['volatile']['aquaRing']) || ! empty($b['volatile']['ingrain'])) && $b['hp'] < $max && empty($b['volatile']['healBlock'])) {
                $this->heal($side, max(1, intdiv($max, 16)));
                $this->say(! empty($b['volatile']['ingrain']) ? "{$who} absorbe des nutriments avec ses racines !" : "Le voile d'eau soigne {$this->text->subject($b, $side, capital: false)} !");
            }
            if (! empty($b['volatile']['nightmare'])) {
                if ($b['status'] === 'sleep') {
                    unset($b);
                    $this->hurt($side, intdiv($max, 4), "{$who} fait un cauchemar !");
                    $b = &$this->active($side);
                } else {
                    unset($b['volatile']['nightmare']);
                }
            }
            if (! empty($b['volatile']['curse']) && $b['hp'] > 0) {
                unset($b);
                $this->hurt($side, intdiv($max, 4), "{$who} est touché par la malédiction !");
                $b = &$this->active($side);
            }
            if (isset($b['volatile']['yawn']) && $b['hp'] > 0) {
                $b['volatile']['yawn']--;
                if ($b['volatile']['yawn'] <= 0) {
                    unset($b['volatile']['yawn']);
                    if ($b['status'] === 'none') {
                        unset($b);
                        $this->inflictAilment($side, 'sleep', ['slug' => 'yawn', 'name' => 'Bâillement', 'meta' => []], quiet: true);
                        $b = &$this->active($side);
                    }
                }
            }
            if (isset($b['volatile']['perish']) && $b['hp'] > 0) {
                $b['volatile']['perish']--;
                $this->say('Le compte à rebours du Requiem ' . $this->text->of($b, $side) . " passe à {$b['volatile']['perish']} !");
                if ($b['volatile']['perish'] <= 0) {
                    unset($b['volatile']['perish']);
                    $this->applyDamage($side, $b['hp'], 'perish');
                }
            }

            // Durées
            foreach (['taunt' => 'n\'est plus sous l\'effet de la Provoc !', 'healBlock' => 'peut de nouveau se soigner !', 'embargo' => 'peut de nouveau utiliser des objets !',
                'magnetRise' => 'ne lévite plus !', 'lockOn' => null, 'charged' => null] as $key => $end) {
                if (! empty($b['volatile'][$key]) && is_int($b['volatile'][$key])) {
                    $b['volatile'][$key]--;
                    if ($b['volatile'][$key] <= 0) {
                        unset($b['volatile'][$key]);
                        if ($key === 'lockOn') {
                            unset($b['volatile']['lockOnTarget']);
                        }
                        $end && $this->say("{$who} {$end}");
                    }
                }
            }
            foreach (['encore' => 'n\'est plus soumis à l\'Encore !', 'disabled' => null] as $key => $end) {
                if (isset($b['volatile'][$key])) {
                    $b['volatile'][$key]['turns']--;
                    if ($b['volatile'][$key]['turns'] <= 0) {
                        $name = $key === 'disabled' ? $b['volatile'][$key]['slug'] : null;
                        unset($b['volatile'][$key]);
                        $this->say($end ? "{$who} {$end}" : "{$who} n'est plus sous entrave !");
                    }
                }
            }
            // Ce qui ne dure qu'un tour
            unset($b['volatile']['protected'], $b['volatile']['endure'], $b['volatile']['magicCoat'], $b['volatile']['snatch']);
            if (isset($b['volatile']['roostTypes'])) {
                $b['types'] = $b['volatile']['roostTypes'];
                unset($b['volatile']['roostTypes']);
            }
            unset($b);
        }

        // Effets d'équipe et de terrain qui s'arrêtent
        foreach (['player', 'foe'] as $side) {
            $team = $side === 'player' ? "de l'équipe du joueur" : "de l'équipe adverse";
            foreach (['tailwind' => "Le vent arrière {$team} retombe !", 'safeguard' => "Le voile mystérieux {$team} se dissipe !",
                'luckyChant' => "L'Air Veinard {$team} s'arrête !"] as $key => $text) {
                if (! empty($this->state[$side][$key]) && --$this->state[$side][$key] <= 0) {
                    unset($this->state[$side][$key]);
                    $this->say($text);
                }
            }
        }
        foreach (['gravity' => 'La gravité redevient normale !', 'trickRoom' => 'Les dimensions redeviennent normales !',
            'mudSport' => 'L\'effet de Lance-Boue se dissipe !', 'waterSport' => 'L\'effet de Tourniquet se dissipe !'] as $key => $text) {
            if (! empty($this->state['field'][$key]) && --$this->state['field'][$key] <= 0) {
                unset($this->state['field'][$key]);
                $this->say($text);
            }
        }
    }

    /** Un Pokémon arrive au combat : Picots, Pics Toxik, Piège de Roc, Vœu Soin, Relais */
    private function entryEffects(string $side): void
    {
        $b = &$this->active($side);
        $b['volatile']['enteredTurn'] = $this->state['turn'] ?? 0;
        $who = $this->text->subject($b, $side);
        $max = $b['stats']['hp'];

        if (! empty($this->state[$side]['healingWish'])) {
            unset($this->state[$side]['healingWish']);
            $b['status'] = 'none';
            $this->emit('status', ['side' => $side, 'status' => 'none']);
            $this->heal($side, $max);
            $this->say("{$who} est soigné par le vœu !");
        }

        $hazards = $this->state[$side]['hazards'] ?? [];
        if (! $hazards || $b['hp'] <= 0) {
            return;
        }
        $grounded = ! in_array('flying', $b['types'], true) && ! $this->hasAbility($b, 'levitate') && empty($b['volatile']['magnetRise'])
            || ! empty($this->state['field']['gravity']) || $this->holds($b, 'iron_ball');

        if (! empty($hazards['stealthRock'])) {
            $eff = $this->effectiveness('rock', $b['types']);
            unset($b);
            $this->hurt($side, (int) floor($max * $eff / 8), "Des pierres pointues blessent {$this->text->subject($this->active($side), $side, capital: false)} !");
            $b = &$this->active($side);
        }
        if (! empty($hazards['spikes']) && $grounded && $b['hp'] > 0) {
            $div = [1 => 8, 2 => 6, 3 => 4][$hazards['spikes']];
            unset($b);
            $this->hurt($side, intdiv($max, $div), "{$who} est blessé par les picots !");
            $b = &$this->active($side);
        }
        if (! empty($hazards['toxicSpikes']) && $grounded && $b['hp'] > 0) {
            if (in_array('poison', $b['types'], true)) {
                unset($this->state[$side]['hazards']['toxicSpikes']);
                $this->say("{$who} absorbe les pics toxiques !");
            } elseif ($b['status'] === 'none' && empty($this->state[$side]['safeguard'])) {
                unset($b);
                $this->inflictAilment($side, 'poison', ['slug' => $hazards['toxicSpikes'] >= 2 ? 'toxic' : 'toxic-spikes', 'name' => 'Pics Toxik', 'meta' => []], quiet: true);
                $b = &$this->active($side);
            }
        }
        unset($b);
    }

    /** Ce que garde un Pokémon qui arrive par Relais */
    private function batonPassKeep(array $old): array
    {
        $keep = array_intersect_key($old['volatile'] ?? [], array_flip(['substitute', 'confusion', 'leech_seed', 'curse', 'ingrain', 'aquaRing', 'perish', 'cantEscape', 'magnetRise', 'lockOn', 'lockOnTarget', 'embargo', 'healBlock', 'abilitySuppressed']));

        return ['stages' => $old['stages'], 'volatile' => $keep];
    }

    // =====================================================================
    // Outils
    // =====================================================================

    private function hasReplacement(string $side): bool
    {
        foreach ($this->state[$side]['party'] as $i => $b) {
            if ($b['hp'] > 0 && $i !== $this->state[$side]['active']) {
                return true;
            }
        }

        return false;
    }

    private function oppositeGenders(array $a, array $b): bool
    {
        return in_array($a['gender'] ?? null, ['male', 'female'], true) && in_array($b['gender'] ?? null, ['male', 'female'], true)
            && $a['gender'] !== $b['gender'];
    }

    private function setHp(string $side, int $hp): void
    {
        $b = &$this->active($side);
        $b['hp'] = max(0, min($b['stats']['hp'], $hp));
        $this->emit('hp', ['side' => $side, 'hp' => $b['hp'], 'maxHp' => $b['stats']['hp'], 'cause' => 'heal']);
    }

    private function resetStockpile(string $side): void
    {
        $b = &$this->active($side);
        $boost = $b['volatile']['stockpileBoost'] ?? 0;
        unset($b['volatile']['stockpile'], $b['volatile']['stockpileBoost']);
        $b['stages']['defense'] = max(-6, $b['stages']['defense'] - $boost);
        $b['stages']['sp_defense'] = max(-6, $b['stages']['sp_defense'] - $boost);
    }

    /** Garde les données d'origine (types, talent, stats, capacités) avant Morphing, Copie, Conversion... */
    private function rememberOriginal(string $side): void
    {
        $b = &$this->active($side);
        $b['original'] ??= array_intersect_key($b, array_flip(['types', 'ability', 'abilityName', 'stats', 'moves', 'weight']));
    }

    /** Remet les données d'origine (en quittant le terrain, ou à la fin du combat) ; les PP utilisés sont gardés */
    private function restoreOriginal(array &$b): void
    {
        if (isset($b['original'])) {
            $original = $b['original'];
            if (empty($b['movesChanged'])) {
                // Les PP des capacités d'origine (Copie : seul l'emplacement copié avait changé)
                foreach ($original['moves'] as $i => $m) {
                    if (isset($b['moves'][$i]) && $b['moves'][$i]['slug'] === $m['slug'] && empty($b['volatile']['transformed'])) {
                        $original['moves'][$i]['pp'] = $b['moves'][$i]['pp'];
                    }
                }
                $b['moves'] = $original['moves'];
            }
            foreach (['types', 'ability', 'abilityName', 'stats', 'weight'] as $key) {
                if (array_key_exists($key, $original)) {
                    $b[$key] = $original[$key];
                }
            }
            $b['stats']['hp'] = $original['stats']['hp'] ?? $b['stats']['hp'];
            unset($b['original']);
        }
    }

    /** Fin du combat : objets échangés ou perdus rendus, formes d'origine remises */
    private function restoreAfterBattle(): void
    {
        foreach (['player', 'foe'] as $side) {
            foreach ($this->state[$side]['party'] as &$b) {
                $this->restoreOriginal($b);
                if (! empty($b['itemOriginalSet'])) {
                    if (empty($b['stolenItemId'])) {
                        $b['item'] = $b['itemOriginal'] ?? null;
                        $b['itemConsumed'] = ! empty($b['itemConsumedBefore']);
                    }
                    unset($b['itemOriginalSet'], $b['itemOriginal'], $b['itemConsumedBefore']);
                }
            }
            unset($b);
        }
    }

    /** Don Naturel : type et puissance selon la Baie (4e génération) */
    private const NATURAL_GIFT = [
        'cheri-berry' => ['fire', 60], 'chesto-berry' => ['water', 60], 'pecha-berry' => ['electric', 60], 'rawst-berry' => ['grass', 60],
        'aspear-berry' => ['ice', 60], 'leppa-berry' => ['fighting', 60], 'oran-berry' => ['poison', 60], 'persim-berry' => ['ground', 60],
        'lum-berry' => ['flying', 60], 'sitrus-berry' => ['psychic', 60], 'figy-berry' => ['bug', 60], 'wiki-berry' => ['rock', 60],
        'mago-berry' => ['ghost', 60], 'aguav-berry' => ['dragon', 60], 'iapapa-berry' => ['dark', 60], 'razz-berry' => ['steel', 60],
        'occa-berry' => ['fire', 60], 'passho-berry' => ['water', 60], 'wacan-berry' => ['electric', 60], 'rindo-berry' => ['grass', 60],
        'yache-berry' => ['ice', 60], 'chople-berry' => ['fighting', 60], 'kebia-berry' => ['poison', 60], 'shuca-berry' => ['ground', 60],
        'coba-berry' => ['flying', 60], 'payapa-berry' => ['psychic', 60], 'tanga-berry' => ['bug', 60], 'charti-berry' => ['rock', 60],
        'kasib-berry' => ['ghost', 60], 'haban-berry' => ['dragon', 60], 'colbur-berry' => ['dark', 60], 'babiri-berry' => ['steel', 60],
        'chilan-berry' => ['normal', 60], 'liechi-berry' => ['grass', 80], 'ganlon-berry' => ['ice', 80], 'salac-berry' => ['fighting', 80],
        'petaya-berry' => ['poison', 80], 'apicot-berry' => ['ground', 80], 'lansat-berry' => ['flying', 80], 'starf-berry' => ['psychic', 80],
        'enigma-berry' => ['bug', 80], 'micle-berry' => ['rock', 80], 'custap-berry' => ['ghost', 80], 'jaboca-berry' => ['dragon', 80],
        'rowap-berry' => ['dark', 80],
    ];

    /** Dégommage : puissance selon l'objet lancé (les autres : 30, les Baies : 10) */
    private const FLING_POWER = [
        'iron-ball' => 130, 'hard-stone' => 100, 'rare-bone' => 100, 'thick-club' => 90, 'dome-fossil' => 100, 'helix-fossil' => 100,
        'old-amber' => 100, 'root-fossil' => 100, 'claw-fossil' => 100, 'skull-fossil' => 100, 'armor-fossil' => 100,
        'flame-plate' => 90, 'splash-plate' => 90, 'zap-plate' => 90, 'meadow-plate' => 90, 'icicle-plate' => 90, 'fist-plate' => 90,
        'toxic-plate' => 90, 'earth-plate' => 90, 'sky-plate' => 90, 'mind-plate' => 90, 'insect-plate' => 90, 'stone-plate' => 90,
        'spooky-plate' => 90, 'draco-plate' => 90, 'dread-plate' => 90, 'iron-plate' => 90, 'deep-sea-tooth' => 90, 'grip-claw' => 90,
        'quick-claw' => 80, 'razor-claw' => 80, 'sticky-barb' => 80, 'dragon-fang' => 70, 'poison-barb' => 70, 'power-bracer' => 70,
        'power-belt' => 70, 'power-lens' => 70, 'power-band' => 70, 'power-anklet' => 70, 'power-weight' => 70, 'kings-rock' => 30,
        'razor-fang' => 30, 'light-ball' => 30, 'flame-orb' => 30, 'toxic-orb' => 30, 'scope-lens' => 30, 'shell-bell' => 30,
        'black-sludge' => 30, 'leftovers' => 10, 'choice-band' => 10, 'choice-scarf' => 10, 'choice-specs' => 10, 'focus-sash' => 10,
        'silk-scarf' => 10, 'wide-lens' => 10, 'zoom-lens' => 10, 'expert-belt' => 10, 'life-orb' => 30, 'muscle-band' => 10, 'wise-glasses' => 10,
    ];
}
