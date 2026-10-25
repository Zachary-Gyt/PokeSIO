<?php

namespace App\Battle;

use App\Models\Move;
use App\Models\Nature;
use App\Models\Pokemon;
use App\Models\PokemonSpecies;
use App\Models\TrainerPokemon;
use App\Models\Type;
use App\Models\TypeEfficacy;
use App\Services\HeldItemService;
use App\Services\PokemonFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Le pont entre la base de données (modèles Eloquent) et le moteur de combat (tableaux simples).
 *
 * Un "combattant" (battler) est un tableau qui contient TOUT ce dont le moteur a besoin :
 * stats, attaques avec leurs PP, statut, XP, courbe de niveau... Il est enregistré dans battles.state.
 */
class BattleFactory
{
    public function __construct(private PokemonFactory $pokemonFactory)
    {
    }

    /** Table des types : ['fire' => ['grass' => 2.0, 'water' => 0.5, ...], ...] (en cache 1 jour) */
    public function typeChart(): array
    {
        return Cache::remember('battle:type-chart', now()->addDay(), function () {
            $slugs = Type::pluck('slug', 'id');
            $chart = [];
            foreach (TypeEfficacy::all() as $row) {
                $chart[$slugs[$row->attacking_type_id]][$slugs[$row->defending_type_id]] = (float) $row->multiplier;
            }

            return $chart;
        });
    }

    /**
     * Un Pokémon du joueur (déjà en base).
     * $ownerTrainerId : ID Dresseur du joueur ; un Pokémon reçu d'un autre dresseur (échange) peut désobéir.
     */
    public function fromOwned(Pokemon $pokemon, ?int $ownerTrainerId = null): array
    {
        $pokemon->loadMissing('species.primaryType', 'species.secondaryType', 'nature', 'moves.move.type', 'heldItem');

        $moves = $pokemon->moves->map(fn ($pm) => $this->moveData($pm->move, $pm->current_pp, $pm->max_pp))->values()->all();

        return $this->battler($pokemon, $moves) + [
            // Attaques que l'espèce apprendra aux niveaux suivants (pour la montée de niveau en combat)
            'learnset' => $this->upcomingMoves($pokemon->species, $pokemon->level),
            'traded' => $ownerTrainerId !== null && (int) $pokemon->original_trainer_id !== $ownerTrainerId,
        ];
    }

    /**
     * Un Pokémon recréé d'après une "photo" (équipe du Tenant du titre, voir TitleService::snapshot).
     * Même espèce, niveau, nature, talent, IV, EV et attaques que l'original : c'est une copie exacte.
     */
    public function fromSnapshot(array $s): array
    {
        $species = PokemonSpecies::findOrFail($s['speciesId']);
        $nature = isset($s['natureId']) ? Nature::find($s['natureId']) : null;

        $pokemon = $this->pokemonFactory->make($species, (int) $s['level'], array_filter([
            'nature' => $nature,
            'shiny' => $s['shiny'] ?? false,
            'ivs' => $s['ivs'] ?? null,
            'nickname' => $s['nickname'] ?? null,
            'gender' => $s['gender'] ?? null,
        ], fn ($v) => $v !== null));

        if (! empty($s['abilityId'])) {
            $pokemon->ability_id = $s['abilityId'];
        }
        $pokemon->held_item_id = $s['heldItemId'] ?? null;   // étape 14 : il garde son objet tenu
        foreach ($s['evs'] ?? [] as $stat => $value) {
            $pokemon->{"ev_{$stat}"} = (int) $value;
        }
        $pokemon->current_hp = $pokemon->max_hp;

        $ids = $s['moves'] ?? [];
        $moves = Move::whereIn('id', $ids)->get()->sortBy(fn (Move $m) => array_search($m->id, $ids))->values();
        if ($moves->isEmpty()) {
            $moves = $species->levelUpMovesUpTo($pokemon->level)->unique('id')->take(-4)->values();
        }

        return $this->battler($pokemon, $moves->map(fn (Move $m) => $this->moveData($m, $m->pp, $m->pp))->all());
    }

    /** Un Pokémon sauvage, créé pour l'occasion (pas en base tant qu'il n'est pas capturé) */
    public function wild(PokemonSpecies $species, int $level, array $options = []): array
    {
        // $options : passé à PokemonFactory::make() (ex. ['shiny' => true] pour la commande /spawn)
        $pokemon = $this->pokemonFactory->make($species, $level, $options);

        return $this->battler($pokemon, $this->startingMoves($species, $level));
    }

    /** Un Pokémon d'un dresseur PNJ (recréé à chaque combat d'après trainer_pokemon) */
    public function fromTrainerPokemon(TrainerPokemon $tp): array
    {
        $tp->loadMissing('species', 'nature');
        $ivs = array_fill_keys(Formulas::STATS, $tp->iv);

        $pokemon = $this->pokemonFactory->make($tp->species, $tp->level, array_filter([
            'nature' => $tp->nature,
            'shiny' => $tp->is_shiny,
            'ivs' => $ivs,
            'nickname' => $tp->nickname,
        ], fn ($v) => $v !== null));

        foreach ($tp->evs ?? [] as $stat => $value) {
            $pokemon->{"ev_{$stat}"} = (int) $value;
        }
        $pokemon->current_hp = $pokemon->max_hp;
        $pokemon->held_item_id = $tp->held_item_id;   // étape 14 : objet tenu (colonne trainer_pokemon.held_item_id)

        $moves = collect($tp->resolveMoves())->map(fn (Move $m) => $this->moveData($m, $m->pp, $m->pp))->all();

        return $this->battler($pokemon, $moves);
    }

    // ---------------------------------------------------------------------

    private function battler(Pokemon $pokemon, array $moves): array
    {
        $species = $pokemon->species;
        $female = $pokemon->gender === 'female';

        $b = [
            'id' => $pokemon->id,                       // null pour un sauvage / un Pokémon de dresseur
            'speciesId' => $species->id,
            'slug' => $species->slug,
            'name' => $pokemon->nickname ?: $species->name,
            'speciesName' => $species->name,
            'level' => $pokemon->level,
            'gender' => $pokemon->gender,
            'shiny' => (bool) $pokemon->is_shiny,
            'natureId' => $pokemon->nature_id,
            'abilityId' => $pokemon->ability_id,
            // Étape 23 : le talent, lu par le moteur (slug PokéAPI : "intimidate") et affiché ("Intimidation")
            'ability' => $pokemon->ability?->slug,
            'abilityName' => $pokemon->ability?->name,
            'types' => $species->types()->pluck('slug')->all(),
            'base' => [],
            'ivs' => [],
            'evs' => [],
            'nature' => ['up' => $pokemon->nature?->increased_stat, 'down' => $pokemon->nature?->decreased_stat],
            'hp' => $pokemon->current_hp,
            'status' => $pokemon->status ?? 'none',
            'sleepTurns' => $pokemon->status === 'sleep' ? max(1, (int) $pokemon->sleep_turns) : 0,
            'toxic' => 0,
            'exp' => $pokemon->experience,
            'growth' => $species->growth_rate,
            'baseExp' => $species->base_experience,
            'evYield' => [],
            'captureRate' => $species->capture_rate,
            'weight' => $species->weight ?? 100,
            'moves' => $moves,
            // Étape 14 : l'objet tenu et son effet (config/held_items.php), ou null
            'item' => HeldItemService::battleData($pokemon->held_item_id ? $pokemon->heldItem : null),
            'stages' => ['attack' => 0, 'defense' => 0, 'sp_attack' => 0, 'sp_defense' => 0, 'speed' => 0, 'accuracy' => 0, 'evasion' => 0, 'crit' => 0],
            'volatile' => [],
            'sprites' => [
                'front' => $species->sprite('front', $pokemon->is_shiny, $female),
                'back' => $species->sprite('back', $pokemon->is_shiny, $female),
                'icon' => $species->sprite('icon'),
                // Cri : fichier copié depuis Essentials (pokemon:essentials-sprites) ou URL PokéAPI
                'cry' => PokemonSpecies::assetUrl($species->cry),
            ],
        ];

        foreach (Formulas::STATS as $stat) {
            $b['base'][$stat] = $species->{"base_{$stat}"};
            $b['ivs'][$stat] = $pokemon->{"iv_{$stat}"};
            $b['evs'][$stat] = $pokemon->{"ev_{$stat}"};
            $b['evYield'][$stat] = $species->{"ev_yield_{$stat}"};
        }

        $b['stats'] = Formulas::stats($b);
        $b['hp'] = min($b['hp'], $b['stats']['hp']);

        return $b;
    }

    /** Ce que le moteur doit savoir d'une attaque */
    public function moveData(Move $move, int $pp, int $maxPp): array
    {
        $move->loadMissing('type');

        return [
            'id' => $move->id,
            'slug' => $move->slug,
            'name' => $move->name,
            'type' => $move->type->slug,
            'category' => $move->category,
            'power' => $move->power,
            'accuracy' => $move->accuracy,
            'pp' => $pp,
            'maxPp' => $maxPp,
            'priority' => $move->priority,
            'target' => $move->target,
            // PokéAPI écrit "damage+ailment" : on garde tel quel
            'effect' => $move->effect_code ?? ($move->category === 'status' ? 'unique' : 'damage'),
            'effectChance' => $move->effect_chance,
            'meta' => $move->meta ?? [],
            'contact' => (bool) $move->makes_contact,
            'hm' => Move::isLockedHm($move->id),   // étape 19 : une CS ne s'oublie que chez le Maître des capacités
        ];
    }

    /** Les 4 dernières attaques apprises par niveau (Pokémon sauvage) */
    private function startingMoves(PokemonSpecies $species, int $level): array
    {
        return $species->levelUpMovesUpTo($level)
            ->unique('id')
            ->take(-4)
            ->map(fn (Move $m) => $this->moveData($m, $m->pp, $m->pp))
            ->values()
            ->all();
    }

    /** [['level' => 9, 'move' => [...]], ...] : attaques apprises APRÈS le niveau actuel */
    private function upcomingMoves(PokemonSpecies $species, int $level): array
    {
        /** @var Collection $moves */
        $moves = $species->moves()
            ->wherePivot('learn_method', 'level_up')
            ->wherePivot('level', '>', $level)
            ->orderByPivot('level')
            ->with('type')
            ->get();

        return $moves->map(fn (Move $m) => [
            'level' => (int) $m->pivot->level,
            'move' => $this->moveData($m, $m->pp, $m->pp),
        ])->values()->all();
    }
}
