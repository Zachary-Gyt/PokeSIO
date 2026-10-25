<?php

namespace App\Services;

use App\Battle\BattleFactory;
use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\BattleTowerRecord;
use App\Models\Character;
use App\Models\Evolution;
use App\Models\Item;
use App\Models\Nature;
use App\Models\Pokemon;
use App\Models\PokemonSpecies;
use Illuminate\Support\Collection;

/**
 * Étape 28 : la TOUR DE COMBAT, comme dans Platine.
 *
 *   - On choisit 3 Pokémon de son équipe (pas de légendaire, pas deux fois la même espèce ni le même objet).
 *     Pendant la Tour, ceux qui dépassent le niveau 50 sont ramenés au niveau 50.
 *   - Les combats s'enchaînent par séries de 7, contre des dresseurs tirés au sort, de plus en plus forts.
 *     Avant chaque combat, l'équipe est soignée. Rien n'est écrit sur les vrais Pokémon (ni PV, ni XP).
 *   - Une défaite arrête la série. On peut faire une pause entre deux combats : la série est gardée.
 *   - Au 21e combat, puis au 49e, le Maître de la Tour (Palmer).
 *   - Chaque victoire rapporte de l'argent ; la meilleure série de chacun va au classement (battle_tower_records).
 *
 * La série en cours : characters.story_flags['tower'] = ['team' => [ids], 'streak' => victoires d'affilée]
 */
class BattleTowerService
{
    public function __construct(private BattleFactory $factory)
    {
    }

    public function run(Character $character): ?array
    {
        return $character->story_flags['tower'] ?? null;
    }

    /** Ce que le comptoir doit montrer : la série en cours, le record, et l'équipe possible */
    public function status(Character $character): array
    {
        $run = $this->run($character);
        $record = BattleTowerRecord::where('character_id', $character->id)->first();

        return [
            'run' => $run ? ['streak' => $run['streak'], 'inSet' => $run['streak'] % (int) config('battle_tower.set_length', 7), 'team' => $this->teamNames($character, $run['team'])] : null,
            'best' => (int) ($record?->best_streak ?? 0),
            'rules' => [
                'size' => (int) config('battle_tower.team_size', 3),
                'level' => $this->levelFor($run ? $run['streak'] + 1 : 1),   // le niveau du prochain combat
                'startLevel' => $this->levelFor(1),
                'levelStep' => (int) (config('battle_tower.level.step') ?? 0),
                'maxLevel' => $this->levelFor(PHP_INT_MAX >> 8),
                'set' => (int) config('battle_tower.set_length', 7),
            ],
            'eligible' => $character->party()->where('is_egg', false)->with('species', 'heldItem')->get()
                ->map(fn (Pokemon $p) => ['id' => $p->id, 'name' => $p->display_name, 'level' => $p->level, 'banned' => $this->isBanned($p)])->values(),
        ];
    }

    /** Le classement : les meilleures séries */
    public function leaderboard(int $limit = 10): Collection
    {
        return BattleTowerRecord::with('character:id,name')->where('best_streak', '>', 0)
            ->orderByDesc('best_streak')->orderBy('best_at')->limit($limit)->get();
    }

    // =====================================================================
    // Commencer, combattre, s'arrêter
    // =====================================================================

    /** Commencer une série avec ces Pokémon de l'équipe */
    public function start(Character $character, array $ids): array
    {
        if ($this->run($character)) {
            throw new InvalidAction('Vous avez déjà une série en cours.');
        }
        $size = (int) config('battle_tower.team_size', 3);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) !== $size) {
            throw new InvalidAction("Il faut choisir {$size} Pokémon différents.");
        }
        $team = $character->party()->whereIn('id', $ids)->where('is_egg', false)->with('species', 'heldItem')->get();
        if ($team->count() !== $size) {
            throw new InvalidAction('Ces Pokémon ne sont pas dans votre équipe.');
        }
        if ($banned = $team->first(fn ($p) => $this->isBanned($p))) {
            throw new InvalidAction("{$banned->display_name} ne peut pas participer à la Tour de Combat.");
        }
        if ($team->pluck('pokemon_species_id')->unique()->count() !== $size) {
            throw new InvalidAction('Il faut des Pokémon d\'espèces différentes.');
        }
        $items = $team->pluck('held_item_id')->filter();
        if ($items->unique()->count() !== $items->count()) {
            throw new InvalidAction('Deux Pokémon ne peuvent pas tenir le même objet.');
        }

        $character->setFlag('tower', ['team' => $ids, 'streak' => 0]);

        return $this->status($character->fresh());
    }

    /** Le combat suivant de la série : renvoie le combat créé */
    public function nextBattle(Character $character): Battle
    {
        $run = $this->run($character) ?? throw new InvalidAction('Aucune série en cours.');
        $team = $character->party()->whereIn('id', $run['team'])->where('is_egg', false)
            ->with('species.primaryType', 'species.secondaryType', 'nature', 'moves.move.type', 'heldItem')->get()
            ->sortBy(fn ($p) => array_search($p->id, $run['team'], true))->values();
        if ($team->count() !== count($run['team'])) {
            // Un Pokémon de la série a quitté l'équipe (PC, échange...) : la série s'arrête
            $this->end($character, $run['streak']);
            throw new InvalidAction('Un Pokémon de votre série n\'est plus dans votre équipe : la série est terminée.');
        }

        $level = $this->levelFor($run['streak'] + 1);
        $battlers = $team->map(fn (Pokemon $p) => $this->battler($p, (int) $character->trainer_id, $level))->all();
        $foe = $this->opponent($run['streak'] + 1);

        return app(BattleService::class)->startTower($character, $foe, $battlers);
    }

    /** Fin d'un combat de la Tour (BattleService::finish) : la série avance ou s'arrête. Renvoie ce que le jeu affiche. */
    public function afterBattle(Character $character, array $state, string $result): array
    {
        $run = $this->run($character);
        if (! $run) {
            return ['ended' => true, 'streak' => 0];
        }
        if ($result !== 'win') {
            $this->end($character, $run['streak']);

            return ['ended' => true, 'streak' => $run['streak'], 'best' => $this->best($character)];
        }

        $run['streak']++;
        $character->setFlag('tower', $run);
        $record = BattleTowerRecord::firstOrNew(['character_id' => $character->id]);
        $record->total_wins = (int) $record->total_wins + 1;
        $set = (int) config('battle_tower.set_length', 7);
        $setDone = $run['streak'] % $set === 0;
        if ($setDone) {
            $record->sets = (int) $record->sets + 1;
        }
        $record->save();
        $this->updateBest($character, $run);

        return [
            'ended' => false,
            'streak' => $run['streak'],
            'inSet' => $run['streak'] % $set,
            'setDone' => $setDone,
            'nextTycoon' => $this->isTycoonBattle($run['streak'] + 1),
            'best' => $this->best($character),
            // Le niveau monte : on le dit au joueur avant le combat suivant
            'nextLevel' => $this->levelFor($run['streak'] + 1),
            'levelUp' => $this->levelFor($run['streak'] + 1) > $this->levelFor($run['streak']),
        ];
    }

    /** Arrêter la série (abandon, défaite) : le record est gardé */
    public function end(Character $character, int $streak): void
    {
        $flags = $character->story_flags ?? [];
        unset($flags['tower']);
        $character->forceFill(['story_flags' => $flags])->save();
    }

    public function best(Character $character): int
    {
        return (int) BattleTowerRecord::where('character_id', $character->id)->value('best_streak');
    }

    // =====================================================================
    // Les équipes
    // =====================================================================

    public function isBanned(Pokemon $pokemon): bool
    {
        $pokemon->loadMissing('species');

        return in_array($pokemon->species->slug, config('battle_tower.banned', []), true);
    }

    /**
     * Le NIVEAU du combat n° $number de la série : il commence bas et monte à chaque série de combats
     * (config battle_tower.level : start, step, every, max). Un nombre seul = niveau fixe (ancien réglage).
     */
    public function levelFor(int $number): int
    {
        $c = config('battle_tower.level', 50);
        if (! is_array($c)) {
            return max(1, min(100, (int) $c));
        }
        $every = max(1, (int) ($c['every'] ?? config('battle_tower.set_length', 7)));
        $level = (int) ($c['start'] ?? 20) + (int) ($c['step'] ?? 5) * intdiv(max(1, $number) - 1, $every);

        return max(1, min((int) ($c['max'] ?? 100), 100, $level));
    }

    /** Un Pokémon du joueur pour la Tour : ramené au niveau du combat s'il est plus fort, soigné, PP pleins (une copie : rien n'est enregistré) */
    private function battler(Pokemon $pokemon, int $trainerId, int $level): array
    {
        $copy = clone $pokemon;
        if ($copy->level > $level) {
            $copy->level = $level;
            $copy->experience = $copy->species->experienceForLevel($level);
        }
        $copy->current_hp = $copy->max_hp;
        $copy->status = 'none';
        $copy->sleep_turns = 0;
        $b = $this->factory->fromOwned($copy, $trainerId);
        foreach ($b['moves'] as &$move) {
            $move['pp'] = $move['maxPp'];
        }
        $b['learnset'] = [];   // pas de nouvelle capacité pendant la Tour

        return $b;
    }

    /** L'adversaire du combat n° $number de la série : un dresseur tiré au sort, ou le Maître de la Tour */
    public function opponent(int $number): array
    {
        $level = $this->levelFor($number);
        $tier = collect(config('battle_tower.tiers'))->filter(fn ($t, $from) => $number - 1 >= $from)->last() ?? ['bst' => [300, 460], 'iv' => 10, 'ai' => 2];
        $money = (int) config('battle_tower.money.base', 1000) + (int) config('battle_tower.money.per_win', 100) * ($number - 1);
        if ($number % (int) config('battle_tower.set_length', 7) === 0) {
            $money *= 2;   // le 7e combat d'une série
        }
        $money = min($money, (int) config('battle_tower.money.max', 10000));
        // Le moteur de combat paie "récompense x niveau le plus haut" (comme les jeux) : tous sont au niveau du combat
        $reward = fn (int $amount) => ['reward' => max(1, intdiv($amount, max(1, $level))), 'money' => $amount];

        if ($tycoon = $this->isTycoonBattle($number)) {
            $t = config('battle_tower.tycoon');
            $party = collect($t[$tycoon])->map(fn ($m) => $this->trainerPokemon($m[0], $level, 31, $m[1]))->filter()->values()->all();
            if ($party) {
                return ['name' => $t['name'], 'class' => $t['class'], 'sprite' => $t['sprite'], ...$reward($money * 2),
                    'defeatText' => $t['defeat'], 'intro' => $t['intro'], 'ai' => 3, 'items' => [], 'music' => 'title', 'party' => $party, 'tycoon' => $tycoon];
            }
        }

        $classes = config('battle_tower.classes', ['Dresseur' => 'tamer']);
        $class = array_rand($classes);
        $pool = $this->pool($tier['bst']);
        $items = Item::whereIn('slug', config('battle_tower.held_items', []))->pluck('slug')->shuffle()->values();
        $party = [];
        foreach ($pool->shuffle()->take((int) config('battle_tower.team_size', 3))->values() as $i => $species) {
            $party[] = $this->trainerPokemon($species, $level, (int) $tier['iv'], $items[$i] ?? null);
        }

        return [
            'name' => collect(config('battle_tower.names', ['Alex']))->random(),
            'class' => $class,
            'sprite' => $classes[$class],
            ...$reward($money),
            'defeatText' => collect(config('battle_tower.defeats', ['Bien joué !']))->random(),
            'intro' => collect(config('battle_tower.intros', ['Combat !']))->random(),
            'ai' => (int) $tier['ai'],
            'items' => [],
            'music' => 'trainer',
            'party' => array_values(array_filter($party)),
        ];
    }

    /** 'silver' ou 'gold' si ce combat est celui du Maître de la Tour, sinon null */
    public function isTycoonBattle(int $number): ?string
    {
        $t = config('battle_tower.tycoon');
        if (! $t) {
            return null;
        }
        if ($number === (int) $t['silver_at']) {
            return 'silver';
        }
        $gold = (int) $t['gold_at'];

        return $gold > 0 && $number >= $gold && $number % $gold === 0 ? 'gold' : null;
    }

    /** Les espèces possibles : formes finales (ou sans évolution), obtenables, pas légendaires, dans la fourchette de stats */
    private function pool(array $bst): Collection
    {
        $evolving = Evolution::pluck('from_species_id')->unique()->all();
        $banned = config('battle_tower.banned', []);
        $pool = PokemonSpecies::where('is_obtainable', true)->where('is_legendary', false)->whereNotIn('slug', $banned)
            ->whereNotIn('id', $evolving)
            ->whereRaw('(base_hp + base_attack + base_defense + base_sp_attack + base_sp_defense + base_speed) between ? and ?', $bst)
            ->get();
        if ($pool->count() < 3) {   // petite base (tests, import partiel) : on prend ce qu'il y a
            $pool = PokemonSpecies::where('is_obtainable', true)->where('is_legendary', false)->whereNotIn('slug', $banned)->get();
        }

        return $pool;
    }

    /** Un Pokémon d'adversaire, au niveau voulu, avec ses capacités de ce niveau */
    private function trainerPokemon(PokemonSpecies|string $species, int $level, int $iv, ?string $item): ?array
    {
        $species = $species instanceof PokemonSpecies ? $species : PokemonSpecies::where('slug', $species)->first();
        if (! $species) {
            return null;
        }
        $tp = new \App\Models\TrainerPokemon([
            'pokemon_species_id' => $species->id, 'level' => $level, 'iv' => $iv,
            'nature_id' => Nature::inRandomOrder()->value('id'),
            'held_item_id' => $item ? Item::where('slug', $item)->value('id') : null,
        ]);
        $tp->setRelation('species', $species);

        return $this->factory->fromTrainerPokemon($tp);
    }

    /** Le meilleur record : la série, et l'équipe qui l'a faite */
    private function updateBest(Character $character, array $run): void
    {
        $record = BattleTowerRecord::firstOrNew(['character_id' => $character->id]);
        if ($run['streak'] <= (int) $record->best_streak) {
            return;
        }
        $record->best_streak = $run['streak'];
        $record->best_at = now();
        $record->best_team = $this->teamNames($character, $run['team']);
        $record->save();
    }

    private function teamNames(Character $character, array $ids): array
    {
        return $character->pokemon()->whereIn('id', $ids)->with('species')->get()
            ->map(fn ($p) => ['name' => $p->display_name, 'speciesName' => $p->species->name, 'slug' => $p->species->slug, 'icon' => $p->species->sprite('icon')])
            ->values()->all();
    }
}
