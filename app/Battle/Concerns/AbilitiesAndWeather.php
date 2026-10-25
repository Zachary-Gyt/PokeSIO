<?php

namespace App\Battle\Concerns;

/**
 * Étape 23 : les TALENTS des générations 3 et 4, et la MÉTÉO en combat (soleil, pluie, tempête de sable, grêle).
 *
 * Ce "trait" est une partie du moteur de combat (BattleEngine l'utilise) : il range ici tout ce qui concerne
 * les talents et la météo, pour que BattleEngine.php reste lisible. Les règles suivent Pokémon Essentials v21
 * (jeux récents) : une météo de talent dure 5 tours (8 avec la Roche qui va avec), comme une capacité.
 *
 * Un combattant connaît son talent : $b['ability'] (slug PokéAPI, ex. "intimidate") et $b['abilityName'].
 * La météo est dans $this->state['weather'] = ['type' => sun|rain|sand|hail, 'turns' => tours restants ou null].
 */
trait AbilitiesAndWeather
{
    /** Talents ignorés par Brise Moule (ceux qui protègent la cible) */
    private const BREAKABLE = [
        'battle-armor', 'shell-armor', 'clear-body', 'white-smoke', 'damp', 'dry-skin', 'filter', 'solid-rock', 'flash-fire',
        'heatproof', 'hyper-cutter', 'immunity', 'inner-focus', 'insomnia', 'keen-eye', 'leaf-guard', 'levitate', 'lightning-rod',
        'limber', 'magma-armor', 'marvel-scale', 'motor-drive', 'own-tempo', 'sand-veil', 'shield-dust', 'simple', 'snow-cloak',
        'soundproof', 'sticky-hold', 'storm-drain', 'sturdy', 'suction-cups', 'tangled-feet', 'thick-fat', 'unaware',
        'vital-spirit', 'volt-absorb', 'water-absorb', 'water-veil', 'wonder-guard', 'oblivious', 'flower-gift',
    ];

    /** Capacités sonores (Anti-Bruit) et capacités "poing" (Poing de Fer) */
    private const SOUND_MOVES = ['growl', 'roar', 'sing', 'supersonic', 'screech', 'snore', 'uproar', 'metal-sound', 'grass-whistle',
        'hyper-voice', 'bug-buzz', 'chatter', 'perish-song', 'heal-bell'];

    private const PUNCH_MOVES = ['ice-punch', 'fire-punch', 'thunder-punch', 'mach-punch', 'focus-punch', 'dizzy-punch', 'dynamic-punch',
        'hammer-arm', 'mega-punch', 'comet-punch', 'meteor-mash', 'shadow-punch', 'drain-punch', 'bullet-punch', 'sky-uppercut'];

    private const WEATHER_TEXT = [
        'sun' => ['Les rayons du soleil brillent fort !', 'Le soleil brille fort.', 'Le soleil se calme.'],
        'rain' => ['Il commence à pleuvoir !', 'La pluie continue de tomber.', 'La pluie s\'arrête.'],
        'sand' => ['Une tempête de sable se lève !', 'La tempête de sable fait rage.', 'La tempête de sable se calme.'],
        'hail' => ['Il commence à grêler !', 'La grêle continue de tomber.', 'La grêle s\'arrête.'],
    ];

    /** Pendant l'attaque d'un Pokémon qui a Brise Moule, les talents "protecteurs" de la cible ne comptent pas */
    private bool $moldBreaker = false;

    // =====================================================================
    // Talents : qui a quoi
    // =====================================================================

    /** Le talent du Pokémon a-t-il l'un de ces slugs ? */
    private function hasAbility(array $b, string ...$slugs): bool
    {
        return isset($b['ability']) && in_array($b['ability'], $slugs, true) && empty($b['volatile']['abilitySuppressed']);
    }

    /** Talent de la CIBLE d'une attaque : ignoré si l'attaquant a Brise Moule et que le talent est "protecteur" */
    private function targetHas(array $b, string ...$slugs): bool
    {
        if ($this->moldBreaker && in_array($b['ability'] ?? '', self::BREAKABLE, true)) {
            return false;
        }

        return $this->hasAbility($b, ...$slugs);
    }

    /** "Intimidation du Léviator sauvage !" (+ événement "ability" pour l'écran de combat) */
    private function announce(string $side, ?string $then = null): void
    {
        $b = $this->active($side);
        $this->emit('ability', ['side' => $side, 'name' => $b['abilityName'] ?? '']);
        $this->say(($b['abilityName'] ?? 'Talent') . ' ' . $this->text->of($b, $side) . ' !');
        if ($then) {
            $this->say($then);
        }
    }

    /** Dégâts "indirects" (statut, météo, recul, objets...) : Garde Magik les empêche */
    private function hurt(string $side, int $amount, string $text, string $cause = 'residual'): void
    {
        $b = $this->active($side);
        if ($b['hp'] <= 0 || $this->hasAbility($b, 'magic-guard')) {
            return;
        }
        $this->applyDamage($side, max(1, $amount), $cause);
        $this->say($text);
    }

    // =====================================================================
    // Météo
    // =====================================================================

    /** La météo qui compte (null si Ciel Gris / Air Lock est sur le terrain) */
    private function weather(): ?string
    {
        $w = $this->state['weather']['type'] ?? null;
        if (! $w) {
            return null;
        }
        foreach (['player', 'foe'] as $side) {
            $b = $this->active($side);
            if ($b['hp'] > 0 && $this->hasAbility($b, 'cloud-nine', 'air-lock')) {
                return null;
            }
        }

        return $w;
    }

    /**
     * Change la météo. $turns = null : permanente (météo de la carte) ; sinon 5 tours, 8 avec la Roche qui va avec.
     * $side : le Pokémon qui la déclenche (pour sa Roche Chaude, Humide, Lisse ou Glace).
     */
    private function setWeather(string $type, ?string $side = null, bool $permanent = false): bool
    {
        if (($this->state['weather']['type'] ?? null) === $type && ($this->state['weather']['turns'] ?? null) === null) {
            return false;   // déjà là pour toujours
        }
        $turns = 5;
        $rocks = ['sun' => 'heat-rock', 'rain' => 'damp-rock', 'sand' => 'smooth-rock', 'hail' => 'icy-rock'];
        if ($side && ($this->active($side)['item']['slug'] ?? null) === $rocks[$type]) {
            $turns = 8;
        }
        $this->state['weather'] = ['type' => $type, 'turns' => $permanent ? null : $turns];
        $this->emit('weather', ['weather' => $type]);
        $this->say(self::WEATHER_TEXT[$type][0]);
        $this->forecast();

        return true;
    }

    /** Début du combat : la météo de la carte (propriété Tiled "weather" : rain, sun, sand, hail) */
    private function startWeather(): void
    {
        $type = $this->state['weather']['type'] ?? null;
        if ($type && isset(self::WEATHER_TEXT[$type])) {
            $this->emit('weather', ['weather' => $type]);
            $this->say(self::WEATHER_TEXT[$type][1]);
        }
    }

    /** Fin du tour : dégâts de sable / grêle, talents liés à la météo, puis la météo diminue */
    private function weatherEndOfTurn(): void
    {
        $type = $this->state['weather']['type'] ?? null;
        if (! $type) {
            return;
        }
        $weather = $this->weather();

        if ($weather) {
            $this->say(self::WEATHER_TEXT[$type][1]);
        }
        foreach (['player', 'foe'] as $side) {
            $b = $this->active($side);
            if ($b['hp'] <= 0 || ! $weather) {
                continue;
            }
            $who = $this->text->subject($b, $side);
            $max = $b['stats']['hp'];
            if ($weather === 'sand' && ! array_intersect(['rock', 'ground', 'steel'], $b['types']) && ! $this->hasAbility($b, 'sand-veil')) {
                $this->hurt($side, intdiv($max, 16), "{$who} est blessé par la tempête de sable !");
            }
            if ($weather === 'hail') {
                if ($this->hasAbility($b, 'ice-body')) {
                    if ($b['hp'] < $max) {
                        $this->heal($side, max(1, intdiv($max, 16)));
                        $this->announce($side, "{$who} récupère un peu de PV.");
                    }
                } elseif (! in_array('ice', $b['types'], true) && ! $this->hasAbility($b, 'snow-cloak')) {
                    $this->hurt($side, intdiv($max, 16), "{$who} est blessé par la grêle !");
                }
            }
            if ($weather === 'rain' && $this->hasAbility($b, 'rain-dish', 'dry-skin') && $b['hp'] < $max) {
                $this->heal($side, max(1, intdiv($max, $this->hasAbility($b, 'dry-skin') ? 8 : 16)));
                $this->announce($side, "{$who} récupère un peu de PV.");
            }
            if ($weather === 'sun' && $this->hasAbility($b, 'dry-skin', 'solar-power')) {
                $this->hurt($side, intdiv($max, 8), "{$who} souffre de la chaleur !");
            }
        }

        $turns = $this->state['weather']['turns'] ?? null;
        if ($turns !== null) {
            $turns--;
            if ($turns <= 0) {
                unset($this->state['weather']);
                $this->emit('weather', ['weather' => null]);
                $this->say(self::WEATHER_TEXT[$type][2]);
                $this->forecast();
            } else {
                $this->state['weather']['turns'] = $turns;
            }
        }
    }

    /** Morphéo (Météo) : son type change avec la météo */
    private function forecast(): void
    {
        foreach (['player', 'foe'] as $side) {
            $b = &$this->active($side);
            if ($b['hp'] <= 0 || ! $this->hasAbility($b, 'forecast')) {
                unset($b);
                continue;
            }
            $type = match ($this->weather()) {
                'sun' => 'fire', 'rain' => 'water', 'hail' => 'ice', default => 'normal',
            };
            if ($b['types'] !== [$type]) {
                $b['types'] = [$type];
                unset($b);
                $this->announce($side, $this->text->subject($this->active($side), $side) . ' change de forme !');
            }
            unset($b);
        }
    }

    /** Multiplicateur de puissance dû à la météo (Feu / Eau) et capacités qui en dépendent */
    private function weatherPower(array $move): float
    {
        $w = $this->weather();

        return match (true) {
            $w === 'sun' && $move['type'] === 'fire', $w === 'rain' && $move['type'] === 'water' => 1.5,
            $w === 'sun' && $move['type'] === 'water', $w === 'rain' && $move['type'] === 'fire' => 0.5,
            $move['slug'] === 'solar-beam' && in_array($w, ['rain', 'sand', 'hail'], true) => 0.5,
            default => 1.0,
        };
    }

    /** Ball'Météo : type et puissance selon la météo ; renvoie la capacité modifiée */
    private function weatherBall(array $move): array
    {
        if ($move['slug'] !== 'weather-ball' || ! ($w = $this->weather())) {
            return $move;
        }
        $move['type'] = ['sun' => 'fire', 'rain' => 'water', 'sand' => 'rock', 'hail' => 'ice'][$w];
        $move['power'] = 100;

        return $move;
    }

    /** Précision d'une capacité selon la météo (Fatal-Foudre, Blizzard) ; null = ne change pas, -1 = touche toujours */
    private function weatherAccuracy(array $move): ?int
    {
        $w = $this->weather();

        return match (true) {
            $move['slug'] === 'thunder' && $w === 'rain', $move['slug'] === 'blizzard' && $w === 'hail' => -1,
            $move['slug'] === 'thunder' && $w === 'sun' => 50,
            default => null,
        };
    }

    // =====================================================================
    // Entrée sur le terrain / sortie
    // =====================================================================

    /** Talents qui agissent quand le Pokémon arrive au combat */
    private function onEntry(string $side): void
    {
        $b = $this->active($side);
        if ($b['hp'] <= 0 || empty($b['ability'])) {
            return;
        }
        $other = $this->opponent($side);
        $foe = $this->active($other);
        $who = $this->text->subject($b, $side);

        switch ($b['ability']) {
            case 'intimidate':
                if ($foe['hp'] > 0) {
                    $this->announce($side);
                    $this->changeStat($other, 'attack', -1, $side);
                }
                break;
            case 'drizzle':
                $this->announce($side);
                $this->setWeather('rain', $side);
                break;
            case 'drought':
                $this->announce($side);
                $this->setWeather('sun', $side);
                break;
            case 'sand-stream':
                $this->announce($side);
                $this->setWeather('sand', $side);
                break;
            case 'snow-warning':
                $this->announce($side);
                $this->setWeather('hail', $side);
                break;
            case 'pressure':
                $this->announce($side, "{$who} exerce une Pression !");
                break;
            case 'mold-breaker':
                $this->announce($side, "{$who} brise le moule !");
                break;
            case 'download':
                // Plus faible défense de l'adversaire : Attaque ou Attaque Spéciale +1
                $stat = $foe['stats']['defense'] < $foe['stats']['sp_defense'] ? 'attack' : 'sp_attack';
                $this->announce($side);
                $this->changeStat($side, $stat, 1);
                break;
            case 'trace':
                if ($foe['hp'] > 0 && ! in_array($foe['ability'] ?? '', ['trace', 'forecast', 'multitype', 'wonder-guard', ''], true)) {
                    $a = &$this->active($side);
                    $a['ability'] = $foe['ability'];
                    $a['abilityName'] = $foe['abilityName'];
                    unset($a);
                    $this->say("{$who} copie le talent " . ($foe['abilityName'] ?? '') . ' ' . $this->text->of($foe, $other) . ' !');
                    $this->onEntry($side);
                }
                break;
            case 'anticipation':
                foreach ($foe['moves'] as $m) {
                    if (($m['effect'] ?? '') === 'ohko' || ($m['type'] && $m['category'] !== 'status' && $this->effectiveness($m['type'], $b['types']) > 1)) {
                        $this->announce($side, "{$who} frissonne !");
                        break;
                    }
                }
                break;
            case 'forewarn':
                $best = collect($foe['moves'])->sortByDesc(fn ($m) => ($m['effect'] ?? '') === 'ohko' ? 150 : (int) ($m['power'] ?? 0))->first();
                if ($best) {
                    $this->announce($side, "{$who} a détecté {$best['name']} !");
                }
                break;
            case 'frisk':
                if (! empty($foe['item']['name'])) {
                    $this->announce($side, "{$who} fouille " . $this->text->subject($foe, $other, capital: false) . " et trouve {$foe['item']['name']} !");
                }
                break;
            case 'slow-start':
                $this->active($side)['volatile']['slowStart'] = 5;
                $this->announce($side, "{$who} n'arrive pas à se lancer !");
                break;
            case 'forecast':
                $this->forecast();
                break;
        }
    }

    /** Talents qui agissent quand le Pokémon quitte le terrain (Médic Nature) */
    private function onLeave(string $side): void
    {
        $b = &$this->active($side);
        if ($b['hp'] > 0 && $this->hasAbility($b, 'natural-cure') && $b['status'] !== 'none') {
            $b['status'] = 'none';
            $b['sleepTurns'] = 0;
            $b['toxic'] = 0;
        }
        // Les types changés en combat (Homochromie, Météo) reviennent
        if (isset($b['baseTypes'])) {
            $b['types'] = $b['baseTypes'];
        }
        unset($b);
    }

    /** Au début du combat : talents d'entrée des deux Pokémon (le plus rapide d'abord) */
    private function entryAbilitiesAtStart(): void
    {
        $this->startWeather();
        $order = $this->effectiveSpeed('player') >= $this->effectiveSpeed('foe') ? ['player', 'foe'] : ['foe', 'player'];
        foreach ($order as $side) {
            $this->onEntry($side);
        }
    }

    // =====================================================================
    // Vitesse, précision, coups critiques
    // =====================================================================

    private function abilitySpeed(array $b, string $side): float
    {
        $w = $this->weather();

        return match (true) {
            $this->hasAbility($b, 'swift-swim') && $w === 'rain', $this->hasAbility($b, 'chlorophyll') && $w === 'sun' => 2.0,
            $this->hasAbility($b, 'quick-feet') && $b['status'] !== 'none' => 1.5,
            $this->hasAbility($b, 'unburden') && ! empty($b['volatile']['unburden']) => 2.0,
            $this->hasAbility($b, 'slow-start') && ! empty($b['volatile']['slowStart']) => 0.5,
            default => 1.0,
        };
    }

    /** Multiplicateur de précision dû aux talents (attaquant et cible) ; null = touche à coup sûr (Annule Garde) */
    private function abilityAccuracy(array $user, array $target, array $move): ?float
    {
        if ($this->hasAbility($user, 'no-guard') || $this->hasAbility($target, 'no-guard')) {
            return null;
        }
        $mult = 1.0;
        if ($this->hasAbility($user, 'compound-eyes')) {
            $mult *= 1.3;
        }
        if ($this->hasAbility($user, 'hustle') && $move['category'] === 'physical') {
            $mult *= 0.8;
        }
        $w = $this->weather();
        if (($this->targetHas($target, 'sand-veil') && $w === 'sand') || ($this->targetHas($target, 'snow-cloak') && $w === 'hail')) {
            $mult *= 0.8;
        }
        if ($this->targetHas($target, 'tangled-feet') && ! empty($target['volatile']['confusion'])) {
            $mult *= 0.5;
        }

        return $mult;
    }

    // =====================================================================
    // Immunités et absorptions (avant les dégâts)
    // =====================================================================

    /**
     * La cible est-elle protégée par son talent ? Renvoie true si l'attaque n'a pas d'effet
     * (le talent a déjà parlé : PV rendus, Vitesse +1...). $effectiveness : efficacité de type.
     */
    private function abilityBlocks(string $side, string $other, array $move, float $effectiveness): bool
    {
        $target = $this->active($other);
        $who = $this->text->subject($target, $other);
        $type = $move['type'];
        $max = $target['stats']['hp'];

        if ($type === 'ground' && $move['category'] !== 'status' && $this->targetHas($target, 'levitate')) {
            $this->announce($other, 'Ça n\'affecte pas ' . $this->text->subject($target, $other, capital: false) . '...');

            return true;
        }
        if (in_array($move['slug'], self::SOUND_MOVES, true) && $this->targetHas($target, 'soundproof')) {
            $this->announce($other, 'Ça n\'affecte pas ' . $this->text->subject($target, $other, capital: false) . '...');

            return true;
        }
        if (($move['effect'] ?? '') === 'ohko' && $this->targetHas($target, 'sturdy')) {
            $this->announce($other, "{$who} n'est pas affecté !");

            return true;
        }

        $absorb = match (true) {
            $type === 'electric' && $this->targetHas($target, 'volt-absorb'), $type === 'water' && $this->targetHas($target, 'water-absorb', 'dry-skin') => 'heal',
            $type === 'electric' && $this->targetHas($target, 'motor-drive') => 'speed',
            $type === 'electric' && $this->targetHas($target, 'lightning-rod'), $type === 'water' && $this->targetHas($target, 'storm-drain') => 'sp_attack',
            $type === 'fire' && $this->targetHas($target, 'flash-fire') => 'flash',
            default => null,
        };
        if ($absorb && ($move['target'] ?? '') !== 'user' && $type) {
            if ($absorb === 'heal') {
                if ($target['hp'] < $max) {
                    $this->heal($other, max(1, intdiv($max, 4)));
                    $this->announce($other, "{$who} récupère des PV !");
                } else {
                    $this->announce($other, 'Ça n\'affecte pas ' . $this->text->subject($target, $other, capital: false) . '...');
                }
            } elseif ($absorb === 'flash') {
                $this->active($other)['volatile']['flashFire'] = true;
                $this->announce($other, "La puissance des capacités Feu {$this->text->of($target, $other)} augmente !");
            } else {
                $this->announce($other);
                $this->changeStat($other, $absorb === 'speed' ? 'speed' : 'sp_attack', 1);
            }

            return true;
        }

        // Garde Mystik : seules les attaques super efficaces la touchent
        if ($move['category'] !== 'status' && $type && $effectiveness <= 1 && $this->targetHas($target, 'wonder-guard') && $move['slug'] !== 'struggle') {
            $this->announce($other, 'Ça n\'affecte pas ' . $this->text->subject($target, $other, capital: false) . '...');

            return true;
        }

        return false;
    }

    // =====================================================================
    // Dégâts
    // =====================================================================

    /** Multiplicateurs de talents sur les dégâts : [attaque, défense, puissance, final] */
    private function abilityDamage(array $user, array $target, array $move, float $effectiveness, bool $crit): array
    {
        $atk = 1.0;
        $def = 1.0;
        $power = 1.0;
        $final = 1.0;
        $physical = $move['category'] === 'physical';
        $w = $this->weather();
        $low = $user['hp'] * 3 <= $user['stats']['hp'];

        // ---- l'attaquant ----
        if ($physical && $this->hasAbility($user, 'huge-power', 'pure-power')) {
            $atk *= 2;
        }
        if ($physical && $this->hasAbility($user, 'hustle')) {
            $atk *= 1.5;
        }
        if ($physical && $this->hasAbility($user, 'guts') && $user['status'] !== 'none') {
            $atk *= 1.5;
        }
        if ($physical && $this->hasAbility($user, 'slow-start') && ! empty($user['volatile']['slowStart'])) {
            $atk *= 0.5;
        }
        if ($physical && $this->hasAbility($user, 'flower-gift') && $w === 'sun') {
            $atk *= 1.5;
        }
        if (! $physical && $this->hasAbility($user, 'solar-power') && $w === 'sun') {
            $atk *= 1.5;
        }
        $pinch = ['overgrow' => 'grass', 'blaze' => 'fire', 'torrent' => 'water', 'swarm' => 'bug'];
        if ($low && isset($pinch[$user['ability'] ?? '']) && $pinch[$user['ability']] === $move['type'] && $this->hasAbility($user, $user['ability'])) {
            $power *= 1.5;
        }
        if ($move['type'] === 'fire' && ! empty($user['volatile']['flashFire'])) {
            $power *= 1.5;
        }
        if ($this->hasAbility($user, 'technician') && (int) $move['power'] <= 60) {
            $power *= 1.5;
        }
        if ($this->hasAbility($user, 'iron-fist') && in_array($move['slug'], self::PUNCH_MOVES, true)) {
            $power *= 1.2;
        }
        if ($this->hasAbility($user, 'reckless') && (($move['meta']['drain'] ?? 0) < 0 || in_array($move['slug'], ['jump-kick', 'high-jump-kick'], true))) {
            $power *= 1.2;
        }
        if ($this->hasAbility($user, 'rivalry') && in_array($user['gender'], ['male', 'female'], true) && in_array($target['gender'], ['male', 'female'], true)) {
            $power *= $user['gender'] === $target['gender'] ? 1.25 : 0.75;
        }
        if ($this->hasAbility($user, 'tinted-lens') && $effectiveness < 1) {
            $final *= 2;
        }
        if ($crit && $this->hasAbility($user, 'sniper')) {
            $final *= 1.5;
        }

        // ---- la cible ----
        if (! $physical && $w === 'sand' && in_array('rock', $target['types'], true)) {
            $def *= 1.5;   // tempête de sable : Défense Spéciale des Pokémon Roche x1,5
        }
        if ($physical && $this->targetHas($target, 'marvel-scale') && $target['status'] !== 'none') {
            $def *= 1.5;
        }
        if (! $physical && $this->targetHas($target, 'flower-gift') && $w === 'sun') {
            $def *= 1.5;
        }
        if (in_array($move['type'], ['fire', 'ice'], true) && $this->targetHas($target, 'thick-fat')) {
            $atk *= 0.5;
        }
        if ($move['type'] === 'fire' && $this->targetHas($target, 'heatproof')) {
            $power *= 0.5;
        }
        if ($move['type'] === 'fire' && $this->targetHas($target, 'dry-skin')) {
            $power *= 1.25;
        }
        if ($effectiveness > 1 && $this->targetHas($target, 'filter', 'solid-rock')) {
            $final *= 0.75;
        }

        return [$atk, $def, $power * $this->weatherPower($move), $final];
    }

    // =====================================================================
    // Après une attaque
    // =====================================================================

    /** Contact : Statik, Corps Ardent, Point Poison, Pose Spore, Peau Dure ; Boom Final si la cible est K.O. */
    private function contactAbilities(string $side, string $other, array $move, int $damage): void
    {
        if ($damage <= 0 || empty($move['contact'])) {
            return;
        }
        $user = $this->active($side);
        $target = $this->active($other);
        if ($user['hp'] <= 0) {
            return;
        }
        $whoUser = $this->text->subject($user, $side);

        if ($this->hasAbility($target, 'rough-skin')) {
            $this->announce($other);
            $this->hurt($side, intdiv($user['stats']['hp'], 8), "{$whoUser} est blessé !");
        }
        if ($target['hp'] <= 0) {
            if ($this->hasAbility($target, 'aftermath') && ! $this->hasAbility($user, 'damp')) {
                $this->announce($other);
                $this->hurt($side, intdiv($user['stats']['hp'], 4), "{$whoUser} est blessé !");
            }

            return;
        }
        $user = $this->active($side);
        if ($user['hp'] <= 0 || $user['status'] !== 'none') {
            return;
        }
        $status = match (true) {
            $this->hasAbility($target, 'static') && $this->rng->chance(30) => 'paralysis',
            $this->hasAbility($target, 'flame-body') && $this->rng->chance(30) => 'burn',
            $this->hasAbility($target, 'poison-point') && $this->rng->chance(30) => 'poison',
            $this->hasAbility($target, 'effect-spore') && $this->rng->chance(30) => $this->rng->pick(['poison', 'paralysis', 'sleep']),
            default => null,
        };
        if ($status) {
            $this->announce($other);
            $this->inflictAilment($side, $status, ['slug' => 'ability', 'name' => '', 'meta' => []], quiet: true, source: $other);
        }
    }

    /** Homochromie : le Pokémon prend le type de l'attaque reçue */
    private function colorChange(string $other, array $move, int $damage): void
    {
        $target = &$this->active($other);
        if ($damage > 0 && $target['hp'] > 0 && $move['type'] && $this->hasAbility($target, 'color-change') && $target['types'] !== [$move['type']]) {
            $target['baseTypes'] ??= $target['types'];
            $target['types'] = [$move['type']];
            unset($target);
            $this->announce($other, $this->text->subject($this->active($other), $other) . ' change de type !');

            return;
        }
        unset($target);
    }

    // =====================================================================
    // Statuts et stats
    // =====================================================================

    /** Le talent empêche-t-il ce statut ? (Échauffement, Insomnia, Vaccin, Armumagma, Ignifu-Voile, Tempo Perso, Feuille Garde) */
    private function abilityPreventsStatus(array $b, string $status): bool
    {
        if ($this->targetHas($b, 'leaf-guard') && $this->weather() === 'sun' && $status !== 'confusion') {
            return true;
        }

        return match ($status) {
            'paralysis' => $this->targetHas($b, 'limber'),
            'sleep' => $this->targetHas($b, 'insomnia', 'vital-spirit'),
            'poison', 'bad_poison' => $this->targetHas($b, 'immunity'),
            'freeze' => $this->targetHas($b, 'magma-armor') || $this->weather() === 'sun',
            'burn' => $this->targetHas($b, 'water-veil'),
            'confusion' => $this->targetHas($b, 'own-tempo'),
            default => false,
        };
    }

    /** Synchro : le Pokémon qui a donné un statut (brûlure, paralysie, poison) le reçoit aussi */
    private function synchronize(string $side, string $status, ?string $source): void
    {
        if (! $source || $source === $side || ! in_array($status, ['burn', 'paralysis', 'poison', 'bad_poison'], true)) {
            return;
        }
        if ($this->hasAbility($this->active($side), 'synchronize') && $this->active($source)['status'] === 'none') {
            $this->announce($side);
            $this->inflictAilment($source, $status === 'bad_poison' ? 'poison' : $status, ['slug' => $status === 'bad_poison' ? 'toxic' : 'synchronize', 'name' => '', 'meta' => []], quiet: true);
        }
    }

    /** Corps Sain, Écran Fumée, Hyper Cutter, Regard Vif bloquent les baisses ; Simple double les changements */
    private function abilityStatChange(string $side, string $stat, int $change, ?string $source): ?int
    {
        $b = $this->active($side);
        if ($change < 0 && $source !== null && $source !== $side) {
            $blocked = $this->targetHas($b, 'clear-body', 'white-smoke')
                || ($stat === 'attack' && $this->targetHas($b, 'hyper-cutter'))
                || ($stat === 'accuracy' && $this->targetHas($b, 'keen-eye'));
            if ($blocked) {
                $this->announce($side, ucfirst(\App\Battle\BattleText::STAT_NAMES[$stat] ?? $stat) . ' ' . $this->text->of($b, $side) . ' ne baisse pas !');

                return null;
            }
        }

        return $this->targetHas($b, 'simple') ? $change * 2 : $change;
    }

    // =====================================================================
    // Fin du tour
    // =====================================================================

    private function abilityEndOfTurn(string $side): void
    {
        $b = $this->active($side);
        if ($b['hp'] <= 0 || empty($b['ability'])) {
            return;
        }
        $who = $this->text->subject($b, $side);
        $max = $b['stats']['hp'];

        if ($this->hasAbility($b, 'speed-boost') && ($this->state['turn'] ?? 0) > 0 && $b['stages']['speed'] < 6) {
            $this->announce($side);
            $this->changeStat($side, 'speed', 1);
        }
        if ($b['status'] !== 'none' && (($this->hasAbility($b, 'shed-skin') && $this->rng->chance(30))
            || ($this->hasAbility($b, 'hydration') && $this->weather() === 'rain'))) {
            $a = &$this->active($side);
            $a['status'] = 'none';
            $a['sleepTurns'] = 0;
            $a['toxic'] = 0;
            unset($a);
            $this->emit('status', ['side' => $side, 'status' => 'none']);
            $this->announce($side, "{$who} est guéri !");
        }
        if ($this->hasAbility($b, 'bad-dreams')) {
            $other = $this->opponent($side);
            $foe = $this->active($other);
            if ($foe['hp'] > 0 && $foe['status'] === 'sleep') {
                $this->announce($side);
                $this->hurt($other, intdiv($foe['stats']['hp'], 8), $this->text->subject($foe, $other) . ' fait des cauchemars !');
            }
        }
        if (! empty($b['volatile']['slowStart'])) {
            $left = --$this->active($side)['volatile']['slowStart'];
            if ($left <= 0) {
                unset($this->active($side)['volatile']['slowStart']);
                $this->announce($side, "{$who} se met enfin en route !");
            }
        }
        if ($this->hasAbility($b, 'truant')) {
            $this->active($side)['volatile']['truant'] = empty($b['volatile']['truant']);
        }
    }

    /** Soin Poison : le poison soigne au lieu de blesser */
    private function poisonHeals(array $b): bool
    {
        return $this->hasAbility($b, 'poison-heal') && in_array($b['status'], ['poison', 'bad_poison'], true);
    }

    /** Talents qui piègent l'adversaire (Marque Ombre, Piège Sable, Magnépiège) ; renvoie le nom ou null */
    private function trappedByAbility(string $side): ?string
    {
        $b = $this->active($side);
        $foe = $this->active($this->opponent($side));
        if ($foe['hp'] <= 0 || in_array('ghost', $b['types'], true)) {
            return null;
        }
        $grounded = ! in_array('flying', $b['types'], true) && ! $this->hasAbility($b, 'levitate');

        return match (true) {
            $this->hasAbility($foe, 'shadow-tag') && ! $this->hasAbility($b, 'shadow-tag') => $foe['abilityName'],
            $this->hasAbility($foe, 'arena-trap') && $grounded => $foe['abilityName'],
            $this->hasAbility($foe, 'magnet-pull') && in_array('steel', $b['types'], true) => $foe['abilityName'],
            default => null,
        };
    }
}
