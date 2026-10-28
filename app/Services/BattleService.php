<?php

namespace App\Services;

use App\Battle\BattleEngine;
use App\Battle\BattleFactory;
use App\Battle\BattlePresenter;
use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Evolution;
use App\Models\Item;
use App\Models\Nature;
use App\Models\Pokemon;
use App\Models\PokemonMove;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Models\WildEncounter;
use Illuminate\Support\Facades\DB;

/**
 * Tout ce qui relie un combat à la base de données :
 *   - le démarrer (rencontre sauvage, dresseur) ;
 *   - jouer une action (le moteur calcule, on enregistre) ;
 *   - le terminer (PV, XP, attaques, capture, argent, dresseur battu, K.O. total).
 *
 * Pendant le combat, les vrais Pokémon ne bougent PAS en base : seul battles.state change.
 * Tout est écrit d'un coup à la fin. Exception : les objets utilisés sont retirés du sac
 * immédiatement (sinon on pourrait les réutiliser en rechargeant la page).
 */
class BattleService
{
    /** Pas de rencontre si le jeu n'en a pas demandé une depuis assez longtemps (anti-triche) */
    private const MIN_SECONDS_BETWEEN_ENCOUNTERS = 2;

    public function __construct(
        private BattleFactory $factory,
        private BagService $bag,
        private EvolutionService $evolutions,
        private TitleService $titles,
        private SafariService $safari,   // étape 15
    ) {
    }

    /** Le combat en cours du personnage (rechargement de page en plein combat), ou null */
    public function activeBattle(Character $character): ?Battle
    {
        // Les combats entre joueurs ont leur propre service (PvpService)
        return Battle::where('character_id', $character->id)->where('type', '!=', 'pvp')->active()->latest('id')->first();
    }

    // =====================================================================
    // Démarrer un combat
    // =====================================================================

    /**
     * Rencontre sauvage sur la case (x, y) de la carte.
     * Renvoie null si la case n'est pas une case de rencontre ou si la zone n'a aucun Pokémon.
     */
    public function startWild(Character $character, string $mapKey, int $x, int $y): ?Battle
    {
        if ($existing = $this->activeBattle($character)) {
            return $existing;
        }

        $map = MapInfo::load($mapKey);
        // Hautes herbes (propriété "encounter"), ou eau (propriété "surf", étape 11 : le joueur surfe)
        $method = match (true) {
            (bool) $map?->tileHasProperty($x, $y, 'encounter') => 'grass',
            (bool) $map?->tileHasProperty($x, $y, 'surf') => 'surf',
            // Propriété de carte "encounters_everywhere" (grottes) : des Pokémon sur toutes les cases
            (bool) $map?->property('encounters_everywhere') => 'grass',
            default => null,
        };
        if (! $method) {
            return null;
        }

        $last = Battle::where('character_id', $character->id)->latest('id')->value('created_at');
        if ($last && $last->diffInSeconds(now(), true) < self::MIN_SECONDS_BETWEEN_ENCOUNTERS) {
            return null;
        }

        // Étape 17 : dans une GROTTE (propriété de carte "cave"), les cases "encounter" du sol utilisent
        // la table "cave" (Pokédex : « Grotte »), ou "grass" si la carte n'a pas de table "cave"
        $environment = $method === 'surf' ? 'water' : 'grass';
        if ($method === 'grass' && $map->property('cave')) {
            $environment = 'cave';
            if (WildEncounter::at($mapKey, $map->encounterZone($x, $y, 'cave'), 'cave')->exists()) {
                $method = 'cave';
            }
        }

        $encounter = WildEncounter::roll($mapKey, $map->encounterZone($x, $y, $method), $method);
        // Au Parc Safari (étape 15), on n'a pas besoin d'un Pokémon en forme : on ne se bat pas
        if (! $encounter || (! $this->safari->active($character, $mapKey) && ! $this->hasUsablePokemon($character))) {
            return null;
        }

        // Étape 23 : Statik, Magnépiège (type attiré) ; Agitation, Pression, Esprit Vital (niveau le plus haut)
        $fieldAbilities = app(FieldAbilityService::class);
        $encounter = $fieldAbilities->attractType($character, $mapKey, $map->encounterZone($x, $y, $method), $method, $encounter);
        $level = $fieldAbilities->level($character, $encounter, $encounter->rollLevel());
        // Étape 23 : Intimidation, Regard Vif, Puanteur, Voile Sable... évitent des rencontres
        if ($fieldAbilities->avoids($character, $level, $map)) {
            return null;
        }

        // Étape 19 : le Repousse fait fuir les Pokémon sauvages plus faibles que le premier de l'équipe
        if (app(FieldItemService::class)->repels($character, $level)) {
            return null;
        }
        // Étape 22 : Rune Purifiante / Encens Pur tenus par le premier Pokémon : une rencontre sur trois évitée
        if ($this->encounterReduced($character)) {
            return null;
        }

        $background = $map->property('battleback', 'field');

        return $this->wildBattle($character, $mapKey, $encounter, $background, $this->baseFor($background, $environment), $level, $method);
    }

    /**
     * Un combat contre le Pokémon sauvage tiré dans la table de rencontres.
     * Étape 15 : au Parc Safari (partie en cours + carte "safari"), c'est un combat "safari".
     */
    private function wildBattle(Character $character, string $mapKey, WildEncounter $encounter, string $background, string $base, ?int $level = null, string $method = 'grass'): Battle
    {
        // Étape 23 : Synchro (même nature), Joli Sourire (sexe opposé)
        $foe = $this->factory->wild($encounter->species, $level ?? $encounter->rollLevel(), app(FieldAbilityService::class)->wildOptions($character, $encounter->species));
        $foe['owned'] = $character->pokedexEntries()->where('pokemon_species_id', $foe['speciesId'])->where('caught', true)->exists();
        // Étape 22 : pour la Lune Ball (le Pokémon évolue-t-il avec une Pierre Lune ?)
        $foe['moonStone'] = Evolution::where('from_species_id', $foe['speciesId'])->whereHas('item', fn ($q) => $q->where('slug', 'moon-stone'))->exists();
        // Étape 22 : méthode de rencontre (Appât Ball, Scuba Ball) et lieu sombre (Sombre Ball)
        $map = MapInfo::load($mapKey);
        // (étape 23 : la Sombre Ball marche aussi la nuit)
        $extra = ['encounter' => $method, 'dark' => (bool) ($map?->property('cave') || $map?->property('dark')) || TimeOfDay::isNight()];

        if ($this->safari->active($character, $mapKey)) {
            return $this->create($character, 'safari', null, ['name' => $foe['name'], 'party' => [$foe]], $background, $base, [
                'safari' => $this->safari->battleState($character, (int) $foe['captureRate']),
            ] + $extra);
        }

        return $this->create($character, 'wild', null, ['name' => $foe['name'], 'party' => [$foe]], $background, $base, $extra);
    }

    /** Étape 22 : le premier Pokémon de l'équipe tient une Rune Purifiante ou un Encens Pur (1 rencontre sur 3 évitée) */
    private function encounterReduced(Character $character): bool
    {
        $lead = $character->party()->with('heldItem')->first();
        $reducers = config('items.encounter_reducers', []);

        return $lead && in_array($lead->heldItem?->slug, $reducers, true) && random_int(1, 3) === 1;
    }

    /**
     * Étape 11 : un Pokémon sauvage qui surgit d'un rocher brisé (Éclate-Roc). null si la carte
     * n'a pas de rencontres pour cette méthode (table wild_encounters, method = rock_smash).
     */
    public function startFieldEncounter(Character $character, string $mapKey, int $x, int $y, string $method, ?string $environment = null): ?Battle
    {
        $safari = $this->safari->active($character, $mapKey);
        if ($this->activeBattle($character) || (! $safari && ! $this->hasUsablePokemon($character))) {
            return null;
        }
        $map = MapInfo::load($mapKey);
        $encounter = $map ? WildEncounter::roll($mapKey, $map->encounterZone($x, $y, $method), $method) : null;
        if (! $encounter) {
            return null;
        }
        $background = $map->property('battleback', 'field');

        // Étape 15 : un Pokémon pêché se bat sur le socle d'eau
        return $this->wildBattle($character, $mapKey, $encounter, $background, $environment ? $this->baseFor($background, $environment) : $background, null, $method);
    }

    /**
     * Combat sauvage FORCÉ, lancé par un administrateur (commande /spawn) : pas besoin d'herbe
     * ni de table de rencontres. Le jeu du joueur est prévenu (notification "spawn") et le lance.
     */
    public function startSpawn(Character $character, PokemonSpecies $species, int $level, bool $shiny = false, array $options = []): Battle
    {
        if ($this->activeBattle($character) || Battle::involving($character)->active()->where('type', 'pvp')->exists()) {
            throw new InvalidAction("{$character->name} est déjà en plein combat.");
        }
        if (! $this->hasUsablePokemon($character)) {
            throw new InvalidAction("{$character->name} n'a aucun Pokémon en état de se battre.");
        }

        $foe = $this->factory->wild($species, $level, ['shiny' => $shiny]);
        $foe['owned'] = $character->pokedexEntries()->where('pokemon_species_id', $foe['speciesId'])->where('caught', true)->exists();

        $map = MapInfo::load($character->current_map);
        $background = $map?->property('battleback', 'field') ?? 'field';

        return $this->create($character, 'wild', null, [
            'name' => $foe['name'],
            'party' => [$foe],
        ], $background, $this->baseFor($background, 'grass'), self::scriptOptions($options));
    }

    /** Combat contre un dresseur PNJ */
    public function startTrainer(Character $character, Trainer $trainer, array $options = []): Battle
    {
        if ($existing = $this->activeBattle($character)) {
            return $existing;
        }

        if (! $trainer->canChallenge($character)) {
            throw new InvalidAction("{$trainer->trainer_class} {$trainer->name} a déjà été battu.");
        }
        if ($trainer->map_key && $trainer->map_key !== $character->current_map) {
            throw new InvalidAction('Ce dresseur n\'est pas sur cette carte.');
        }
        if (! $this->hasUsablePokemon($character)) {
            throw new InvalidAction('Vous n\'avez aucun Pokémon en état de se battre !');
        }

        $foe = [
            'name' => $trainer->name,
            'class' => $trainer->trainer_class,
            'sprite' => $trainer->sprite_battle,
            'reward' => $trainer->reward_money,
            'defeatText' => $trainer->defeat_text,
            'ai' => max(1, (int) $trainer->ai_level),
            'items' => $this->trainerItems($trainer),
            // Étape 12 : la musique du combat (config/audio.php : battle_trainer, battle_leader, battle_title)
            // Étape 27 : le Conseil 4 a la musique des Champions, le Maître de la Ligue celle du Maître
            'music' => $trainer->is_title || $this->isLeagueChampion($trainer) ? 'title' : ($trainer->badge_id || app(LeagueService::class)->isMember($trainer) ? 'leader' : 'trainer'),
        ];

        if ($trainer->is_title) {
            // Tenant du titre : son équipe est la "photo" de celle du dernier joueur qui l'a battu
            $this->titles->checkChallenger($trainer, $character);
            $holder = $this->titles->holder($trainer);
            $party = collect($this->titles->team($trainer))->map(fn ($s) => isset($s['trainerPokemonId'])
                ? $this->factory->fromTrainerPokemon($trainer->pokemon()->findOrFail($s['trainerPokemonId']))
                : $this->factory->fromSnapshot($s))->all();
            $foe['name'] = $holder?->name ?? $trainer->name;
            // Un joueur tenant : son sprite de combat de joueur (player_male / player_female)
            $foe['sprite'] = $holder?->avatarKey() ? "player_{$holder->avatarKey()}" : $trainer->sprite_battle;
            $foe['defeatText'] = $holder ? 'Tu as gagné... Le titre est à toi. Défends-le bien !' : 'Bravo ! Le titre est à toi.';
            $foe['title'] = ['holderId' => $holder?->id];
        } else {
            // Étape 26 : premier combat (version 0) ou revanche (équipe plus forte, voir RematchService)
            $rematches = app(RematchService::class);
            $team = $rematches->team($character, $trainer);
            $party = $team->map(fn ($tp) => $this->factory->fromTrainerPokemon($tp))->all();
            // La récompense suit la force de l'équipe (plus haut niveau, comme le calcul des jeux)
            $baseMax = (int) $trainer->pokemon()->where('version', 0)->max('level');
            if ($baseMax > 0 && $team->max('level') > $baseMax) {
                $foe['reward'] = (int) round($foe['reward'] * $team->max('level') / $baseMax);
            }
        }

        if (! $party) {
            throw new InvalidAction('Ce dresseur n\'a aucun Pokémon.');
        }
        $foe['party'] = $party;

        $map = MapInfo::load($character->current_map);
        $background = $map?->property('battleback', 'field') ?? 'field';

        return $this->create($character, 'trainer', $trainer, $foe, $background, $background, self::scriptOptions($options));
    }

    /**
     * Étape 28 : un combat de la TOUR DE COMBAT. $foe vient de BattleTowerService::opponent(), $battlers sont
     * les 3 Pokémon choisis (niveau 50, soignés). Rien ne sera écrit sur les vrais Pokémon (voir finish()).
     */
    public function startTower(Character $character, array $foe, array $battlers): Battle
    {
        if ($this->activeBattle($character) || Battle::involving($character)->active()->where('type', 'pvp')->exists()) {
            throw new InvalidAction('Vous êtes déjà en plein combat.');
        }
        if (! $foe['party']) {
            throw new InvalidAction('Aucun adversaire disponible (Pokémon manquants dans la base).');
        }
        $background = MapInfo::load($character->current_map)?->property('battleback', 'indoor1') ?? 'indoor1';

        return $this->create($character, 'trainer', null, $foe, $background, $background, ['tower' => true], $battlers);
    }

    /** Étape 27 : le Maître de la Ligue (dernier membre de config league.members) */
    private function isLeagueChampion(Trainer $trainer): bool
    {
        $members = app(LeagueService::class)->members();

        return $members !== [] && end($members) === $trainer->slug;
    }

    /**
     * Étape 24 : options d'un combat lancé par un événement du scénario.
     *   canLose : le joueur peut perdre sans "tout devient noir" (pas d'argent perdu, équipe soignée après),
     *             comme le premier combat contre le rival dans les jeux (option canLose d'Essentials).
     */
    private static function scriptOptions(array $options): array
    {
        return ! empty($options['canLose']) ? ['canLose' => true] : [];
    }

    /** Les objets que le dresseur peut utiliser pendant le combat (colonne trainers.items) */
    private function trainerItems(Trainer $trainer): array
    {
        $slugs = $trainer->items ?? [];
        if (! $slugs) {
            return [];
        }
        $items = Item::whereIn('slug', $slugs)->get()->keyBy('slug');

        return collect($slugs)->filter(fn ($slug) => isset($items[$slug]))->map(fn ($slug) => [
            'slug' => $slug,
            'name' => $items[$slug]->name,
            'effect' => $items[$slug]->effect_code,
            'value' => $items[$slug]->effect_value,
        ])->values()->all();
    }

    /**
     * Niveau maximum auquel un Pokémon reçu par échange obéit (Rouge Feu / Vert Feuille) :
     * 10 sans badge, 30 avec 2 badges, 50 avec 4, 70 avec 6, tous avec 8.
     */
    public static function obeyCap(int $badges): int
    {
        return match (true) {
            $badges >= 8 => 100,
            $badges >= 6 => 70,
            $badges >= 4 => 50,
            $badges >= 2 => 30,
            default => 10,
        };
    }

    private function create(Character $character, string $type, ?Trainer $trainer, array $foe, string $background, string $base, array $extra = [], ?array $battlers = null): Battle
    {
        // Étape 28 : la Tour de Combat donne son équipe à elle (3 Pokémon, niveau 50)
        if ($battlers === null) {
            $party = $character->battleParty()->with('species.primaryType', 'species.secondaryType', 'nature', 'moves.move.type')->get();
            $battlers = $party->map(fn (Pokemon $p) => $this->factory->fromOwned($p, (int) $character->trainer_id))->values()->all();
        }

        // Premier Pokémon de l'équipe qui n'est pas K.O. (au Parc Safari, il peut ne pas y en avoir)
        $active = collect($battlers)->search(fn ($b) => $b['hp'] > 0);
        $active = $active === false ? 0 : $active;
        $seed = random_int(1, 2_000_000_000);

        $state = [
            'version' => 1,
            'type' => $type,
            'phase' => 'command',
            'turn' => 0,
            'rng' => $seed,
            'background' => $background,
            'base' => $base,
            'player' => [
                'name' => $character->name,
                'gender' => $character->gender,
                'active' => $active,
                'party' => $battlers,
                'obeyCap' => self::obeyCap($character->badges()->count()),
            ],
            'foe' => $foe + ['active' => 0],
            'participants' => [],
            'pendingMoves' => [],
            'seen' => [$foe['party'][0]['speciesId']],
            'result' => null,
            'rewards' => [],
        ] + $extra;
        // Étape 23 : la météo de la carte (propriété Tiled "weather" : rain, sun, sand, hail), pour tout le combat
        $mapWeather = MapInfo::load($character->current_map)?->property('weather');
        if (in_array($mapWeather, ['rain', 'sun', 'sand', 'hail'], true)) {
            $state['weather'] = ['type' => $mapWeather, 'turns' => null];
        }

        $engine = new BattleEngine($state, $this->factory->typeChart());
        $events = $engine->start();
        $state = $engine->state();

        $battle = Battle::create([
            'type' => $type,
            'status' => 'active',
            'character_id' => $character->id,
            'trainer_id' => $trainer?->id,
            'seed' => $seed,
            'turn' => 0,
            'state' => $state,
            'log' => [['turn' => 0, 'events' => $events]],
        ]);

        // Le Pokédex note l'espèce comme "vue"
        $character->registerInPokedex($foe['party'][0]['speciesId']);

        return $battle;
    }

    // =====================================================================
    // Jouer une action
    // =====================================================================

    /**
     * Joue l'action du joueur. Renvoie ['events' => [...], 'battle' => vue du combat].
     * $input : ['type' => 'move'|'switch'|'item'|'run'|'continue'|'learn', 'slot', 'party', 'item_id', 'target', 'forget']
     */
    public function act(Battle $battle, array $input): array
    {
        return DB::transaction(function () use ($battle, $input) {
            // On verrouille la ligne : deux clics très rapides ne joueront pas deux tours en même temps
            $battle = Battle::whereKey($battle->id)->lockForUpdate()->firstOrFail();
            if (! $battle->isActive()) {
                throw new InvalidAction('Ce combat est terminé.');
            }

            $character = $battle->character;
            $action = $this->toEngineAction($character, $input);

            $engine = new BattleEngine($battle->state, $this->factory->typeChart());
            $events = $engine->act($action);
            $state = $engine->state();

            if ($action['type'] === 'item' && $engine->itemConsumed) {
                $character->removeItem($action['item']['id']);
            }

            $log = $battle->log ?? [];
            $log[] = ['turn' => $state['turn'], 'action' => $input, 'events' => $events];
            $battle->forceFill(['state' => $state, 'turn' => $state['turn'], 'log' => $log]);

            $extra = [];
            if ($state['phase'] === 'ended') {
                $extra = $this->finish($battle, $character, $state, $events);
            }
            $battle->save();

            return [
                'events' => BattlePresenter::events($events),
                'battle' => $this->view($battle, $extra),
            ];
        });
    }

    /** Ce que le navigateur reçoit */
    public function view(Battle $battle, array $extra = []): array
    {
        $view = BattlePresenter::view($battle->state, $extra + ['id' => $battle->id]);
        if ($battle->isActive()) {
            // Le Sac d'Essentials, avec ses poches (seuls les objets "useBattle" sont utilisables)
            $view['bag'] = $this->bag->contents($battle->character);
        }

        return $view;
    }

    private function toEngineAction(Character $character, array $input): array
    {
        $type = $input['type'] ?? null;

        if ($type === 'item') {
            $item = Item::find($input['item_id'] ?? 0);
            if (! $item || ! $character->hasItem($item)) {
                throw new InvalidAction('Vous n\'avez pas cet objet.');
            }
            if (! in_array($item->effect_code, BagService::BATTLE_EFFECTS, true)) {
                throw new InvalidAction('Ce n\'est pas le moment d\'utiliser ça !');
            }

            return [
                'type' => 'item',
                'item' => [
                    'id' => $item->id, 'slug' => $item->slug, 'name' => $item->name, 'effect' => $item->effect_code, 'value' => $item->effect_value,
                    // Étape 22 : la stat d'un objet X, la capacité choisie pour une Huile (0 à 3)
                    'stat' => config("items.battle_stats.{$item->slug}"),
                    'move' => isset($input['move']) ? (int) $input['move'] : null,
                ],
                'target' => isset($input['target']) ? (int) $input['target'] : null,
            ];
        }

        return match ($type) {
            'move' => ['type' => 'move', 'slot' => (int) ($input['slot'] ?? -1)],
            'switch' => ['type' => 'switch', 'party' => (int) ($input['party'] ?? -1)],
            'learn' => ['type' => 'learn', 'forget' => isset($input['forget']) ? (int) $input['forget'] : null],
            'run', 'continue' => ['type' => $type],
            // Le dresseur va envoyer un Pokémon : changer le sien (party) ou le garder (party absent)
            'shift' => ['type' => 'shift', 'party' => isset($input['party']) ? (int) $input['party'] : null],
            // Étape 15 : Parc Safari
            'safari_ball', 'bait', 'rock' => ['type' => $type],
            default => throw new InvalidAction('Action inconnue.'),
        };
    }

    // =====================================================================
    // Fin du combat : on écrit tout en base
    // =====================================================================

    private function finish(Battle $battle, Character $character, array $state, array &$events): array
    {
        $result = $state['result'];
        $extra = ['result' => $result];

        // Étape 28 : la Tour de Combat. Les vrais Pokémon ne changent pas (ni PV, ni XP), pas de "tout devient noir" :
        // seuls l'argent gagné, le Pokédex et la série comptent.
        if (! empty($state['tower'])) {
            foreach (array_unique($state['seen'] ?? []) as $speciesId) {
                $character->registerInPokedex($speciesId);
            }
            if ($result === 'win') {
                $character->addMoney($state['rewards']['money'] ?? 0);
            }
            $extra['tower'] = app(BattleTowerService::class)->afterBattle($character->fresh(), $state, $result);
            $battle->forceFill(['status' => 'finished', 'result' => $result,
                'winner_character_id' => $result === 'win' ? $character->id : null, 'ended_at' => now()]);
            $extra['money'] = $character->fresh()->money;

            return $extra;
        }

        // 1) Les Pokémon du joueur : PV, statut, PP, XP, niveau, EV, nouvelles attaques
        $leveledUp = [];
        foreach ($state['player']['party'] as $b) {
            if ($this->saveOwnedPokemon($b)) {
                $leveledUp[] = $b['id'];
            }
        }

        // Jackpot : les pièces ramassées pendant le combat
        if (($state['payDay'] ?? 0) > 0 && $result !== 'loss') {
            $character->addMoney((int) $state['payDay']);
        }

        // 2) Pokédex : toutes les espèces vues pendant le combat
        foreach (array_unique($state['seen'] ?? []) as $speciesId) {
            $character->registerInPokedex($speciesId);
        }

        // 3) Selon le résultat
        if ($result === 'caught') {
            $extra['caught'] = $this->storeCaughtPokemon($character, $state);
        }

        if ($battle->trainer_id && ($state['foe']['title'] ?? null) !== null) {
            $extra['title'] = $this->finishTitle($battle->trainer, $character, $state, $result, $events);
        }

        if ($result === 'win' && $battle->trainer_id) {
            $trainer = $battle->trainer;
            $character->addMoney($state['rewards']['money'] ?? 0);

            $pivot = $character->defeatedTrainers()->whereKey($trainer->id)->first()?->pivot;
            if ($pivot) {
                $character->defeatedTrainers()->updateExistingPivot($trainer->id, [
                    'times_defeated' => $pivot->times_defeated + 1,
                    'last_defeated_at' => now(),
                ]);
            } else {
                $character->defeatedTrainers()->attach($trainer->id, ['first_defeated_at' => now(), 'last_defeated_at' => now()]);
            }

            // Étape 22 : battre un Champion d'Arène rend toute l'équipe plus heureuse
            if ($trainer->badge_id) {
                foreach ($character->party()->where('is_egg', false)->get() as $pokemon) {
                    app(FriendshipService::class)->apply($pokemon, 'gym');
                }
            }

            // Champion d'arène : badge (+ CT éventuelle), avec les messages d'Essentials
            if ($trainer->badge_id && ! $character->hasBadge($trainer->badge_id)) {
                $character->badges()->attach($trainer->badge_id, ['obtained_at' => now()]);
                $badge = $trainer->badge;
                $events[] = ['type' => 'message', 'text' => "{$character->name} reçoit le {$badge->name} de la part de {$trainer->name} !", 'pause' => true, 'me' => 'badge'];
                $extra['badge'] = ['order' => $badge->order, 'name' => $badge->name];
            }
            if ($trainer->reward_item_id && ! $pivot) {   // seulement la 1re victoire (pas aux revanches)
                $item = $trainer->rewardItem;
                // Une CT ne se reçoit qu'une fois (elle est réutilisable)
                if ($item && ! ($item->pocket === 'tms' && $character->hasItem($item))) {
                    $character->addItem($item);
                    $events[] = ['type' => 'message', 'text' => "{$character->name} obtient {$item->name} !", 'pause' => true];
                }
            }
            $extra['trainerDefeated'] = $trainer->slug;
            app(RematchService::class)->defeated($character, $trainer);   // étape 26 : plus de main levée
        }

        // Étape 15 : Parc Safari, les Balls qui restent ; plus de Balls = retour à l'entrée
        if ($state['type'] === 'safari') {
            $extra['safariGame'] = $this->safari->afterBattle($character, $state);
        }

        // Étape 23 : Ramassage, après une victoire
        if ($result === 'win') {
            foreach (app(FieldAbilityService::class)->pickup($character) as $text) {
                $events[] = ['type' => 'message', 'text' => $text, 'pause' => true];
            }
        }

        // Étape 27 : la Ligue (progression de la tentative, et le Panthéon après le Maître)
        $league = app(LeagueService::class);
        if ($battle->trainer_id && $league->isMember($battle->trainer)) {
            $extra['league'] = $league->afterBattle($character, $battle->trainer, $result);
        }

        if ($result === 'loss' && ! empty($state['canLose'])) {
            // Étape 24 : combat "perdable" d'un événement : l'équipe est soignée, et l'histoire continue
            $character->healParty();
        } elseif ($result === 'loss') {
            $extra['whiteout'] = $this->whiteOut($character, $state, $events);
        } else {
            // 4) Évolutions : les Pokémon qui ont gagné un niveau et remplissent les conditions.
            //    Le jeu affichera l'écran d'évolution ; c'est /equipe/evoluer qui la fera vraiment.
            $extra['evolutions'] = [];
            foreach ($leveledUp as $id) {
                $pokemon = Pokemon::with(PokemonPresenter::RELATIONS)->find($id);
                if ($pokemon && $pokemon->current_hp > 0 && ($evolution = $this->evolutions->candidate($pokemon))) {
                    $extra['evolutions'][] = $this->evolutions->preview($pokemon, $evolution);
                }
            }
        }

        $battle->forceFill([
            'status' => 'finished',
            'result' => $result,
            'winner_character_id' => in_array($result, ['win', 'caught'], true) ? $character->id : null,
            'ended_at' => now(),
        ]);

        $extra['money'] = $character->fresh()->money;

        return $extra;
    }

    /**
     * Fin d'un combat contre le Tenant du titre : le vainqueur prend sa place (s'il est le premier),
     * sinon le tenant compte une défense de plus.
     */
    private function finishTitle(Trainer $trainer, Character $character, array $state, string $result, array &$events): array
    {
        $holderId = $state['foe']['title']['holderId'] ?? null;

        if ($result !== 'win') {
            $this->titles->defended($holderId);

            return ['crowned' => false];
        }

        $crowned = $this->titles->crown($trainer, $character, $holderId);
        $events[] = ['type' => 'message', 'pause' => true, 'text' => $crowned
            ? "Félicitations ! {$character->name} devient {$trainer->trainer_class} !"
            : 'Hélas... Un autre dresseur a pris le titre juste avant vous.'];

        return ['crowned' => $crowned, 'info' => $this->titles->info($trainer, $character)];
    }

    /** Recopie l'état d'un Pokémon du joueur après le combat ; renvoie true s'il a gagné un niveau */
    private function saveOwnedPokemon(array $b): bool
    {
        if (! $b['id']) {
            return false;
        }
        $pokemon = Pokemon::with('moves')->find($b['id']);
        if (! $pokemon) {
            return false;
        }
        $leveledUp = $b['level'] > $pokemon->level;

        $pokemon->forceFill([
            'current_hp' => max(0, $b['hp']),
            'status' => $b['hp'] > 0 ? $b['status'] : 'none',
            'sleep_turns' => $b['status'] === 'sleep' ? max(0, $b['sleepTurns']) : 0,
            'level' => $b['level'],
            'experience' => $b['exp'],
        ]);
        // Étape 14 : la Baie tenue a été mangée pendant le combat
        if (! empty($b['itemConsumed'])) {
            $pokemon->held_item_id = null;
        }
        // Larcin / Implore contre un Pokémon sauvage : l'objet volé est gardé
        if (! empty($b['stolenItemId'])) {
            $pokemon->held_item_id = $b['stolenItemId'];
        }
        foreach ($b['evs'] as $stat => $value) {
            $pokemon->{"ev_{$stat}"} = $value;
        }
        // Étape 22 : le Bonheur (niveaux gagnés, K.O., objets X, plantes amères)
        $friendship = app(FriendshipService::class);
        for ($i = $pokemon->getOriginal('level'); $i < $b['level']; $i++) {
            $friendship->change($pokemon, 'levelup');
        }
        for ($i = 0; $i < ($b['faints'] ?? 0); $i++) {
            $friendship->change($pokemon, 'faint');
        }
        foreach ($b['friendship'] ?? [] as $method) {
            $friendship->change($pokemon, $method);
        }
        $pokemon->save();

        // Attaques : si la liste a changé (nouvelle attaque apprise), on la réécrit ; sinon juste les PP
        // (étape 22 : les PP Plus d'une capacité gardée sont conservés)
        if (! empty($b['movesChanged'])) {
            $ppUps = $pokemon->moves->pluck('pp_ups', 'move_id');
            $pokemon->moves()->delete();
            foreach ($b['moves'] as $i => $m) {
                $pokemon->moves()->create(['move_id' => $m['id'], 'slot' => $i + 1, 'current_pp' => $m['pp'], 'pp_ups' => (int) ($ppUps[$m['id']] ?? 0)]);
            }
        } else {
            foreach ($b['moves'] as $i => $m) {
                PokemonMove::where('pokemon_id', $pokemon->id)->where('move_id', $m['id'])->update(['current_pp' => $m['pp']]);
            }
        }

        return $leveledUp;
    }

    /** Le Pokémon capturé rejoint l'équipe (ou le PC si l'équipe est pleine) */
    private function storeCaughtPokemon(Character $character, array $state): array
    {
        $b = $state['foe']['party'][$state['foe']['active']];
        $species = PokemonSpecies::find($b['speciesId']);
        $ball = Item::where('slug', $state['caught']['ball'] ?? 'poke-ball')->first();

        $pokemon = new Pokemon([
            'pokemon_species_id' => $species->id,
            'level' => $b['level'],
            'experience' => $b['exp'],
            'friendship' => $species->base_friendship,
            'gender' => $b['gender'],
            'is_shiny' => $b['shiny'],
            'nature_id' => $b['natureId'] ?? Nature::value('id'),
            'ability_id' => $b['abilityId'],
            'current_hp' => max(1, $b['hp']),
            'status' => $b['status'],
            'sleep_turns' => $b['status'] === 'sleep' ? $b['sleepTurns'] : 0,
        ]);

        foreach ($b['ivs'] as $stat => $value) {
            $pokemon->{"iv_{$stat}"} = $value;
            $pokemon->{"ev_{$stat}"} = $b['evs'][$stat];
        }
        $pokemon->setRelation('species', $species);
        // Étape 22 : Soin Ball (PV, statut et PP rendus), Copain Ball (Bonheur 200)
        $healBall = $ball?->slug === 'heal-ball';
        if ($healBall) {
            $pokemon->forceFill(['current_hp' => $pokemon->max_hp, 'status' => 'none', 'sleep_turns' => 0]);
        }
        if ($ball?->slug === 'friend-ball') {
            $pokemon->friendship = (int) config('items.friendship.friend_ball', 200);
        }

        $map = MapInfo::load($character->current_map);
        $saved = app(PokemonFactory::class)->giveTo($character, $pokemon, [
            'ball_id' => $ball?->id,
            'location' => $map?->property('displayName') ?? $character->current_map,
        ]);

        if (! $saved) {
            return ['name' => $b['name'], 'stored' => 'nowhere'];
        }

        // Ses attaques et leurs PP restants (giveTo lui a appris les attaques de son niveau)
        $saved->moves()->delete();
        foreach ($b['moves'] as $i => $m) {
            $saved->moves()->create(['move_id' => $m['id'], 'slot' => $i + 1, 'current_pp' => $healBall ? $m['maxPp'] : $m['pp'], 'pp_ups' => 0]);
        }

        // Étape 19 : l'id sert à lui donner un surnom juste après la capture
        return ['name' => $b['name'], 'stored' => $saved->location, 'id' => $saved->id, 'icon' => $species->sprite('icon')]; // "party" ou "box"
    }

    /**
     * K.O. total : on perd de l'argent, l'équipe est soignée, et on se réveille
     * au dernier Centre Pokémon (ou au point de départ du jeu).
     */
    private function whiteOut(Character $character, array $state, array &$events): array
    {
        // Comme dans les jeux (4e génération et suivantes) : base selon le nombre de badges x plus haut niveau
        $bases = [8, 16, 24, 36, 48, 64, 80, 100, 120];
        $base = $bases[min(8, $character->badges()->count())];
        $highest = max(array_column($state['player']['party'], 'level'));
        $lost = min($character->money, $base * $highest);

        if ($lost > 0) {
            $character->spendMoney($lost);
            $events[] = ['type' => 'message', 'text' => "Vous perdez {$lost} ₽...", 'pause' => true];
        }
        $events[] = ['type' => 'message', 'text' => '... Tout devient noir !', 'pause' => true];

        $character->healParty();

        $map = $character->last_heal_map ?? config('game.starting_map');
        $x = $character->last_heal_map ? $character->last_heal_x : config('game.starting_x');
        $y = $character->last_heal_map ? $character->last_heal_y : config('game.starting_y');

        $character->forceFill([
            'current_map' => $map,
            'position_x' => $x,
            'position_y' => $y,
            'facing' => 'down',
        ])->save();

        return ['map' => $map, 'x' => $x, 'y' => $y, 'facing' => 'down', 'moneyLost' => $lost];
    }

    // =====================================================================
    // Outils
    // =====================================================================

    private function hasUsablePokemon(Character $character): bool
    {
        return $character->party()->where('current_hp', '>', 0)->exists();
    }

    /** Socle de combat : "grass" dans les hautes herbes s'il existe, sinon celui du fond */
    private function baseFor(string $background, string $environment): string
    {
        return file_exists(public_path("game/battle/backgrounds/{$environment}_base0.png")) ? $environment : $background;
    }
}
