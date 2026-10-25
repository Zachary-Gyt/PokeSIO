<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Models\Move;
use App\Models\Nature;
use App\Models\Pokemon;
use App\Models\PokemonSpecies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Étape 18 : la PENSION et les ŒUFS (règles des jeux récents, réglages dans config/breeding.php).
 *
 * - Chaque joueur a 2 places à la Pension (pokemon.location = "daycare").
 * - Chaque pas du joueur donne 1 point d'Exp. aux Pokémon confiés (ils apprennent leurs attaques,
 *   mais n'évoluent pas). Pour les reprendre : (niveaux gagnés + 1) x 100 ₽.
 * - Tous les 256 pas, deux Pokémon compatibles peuvent produire un Œuf, envoyé directement au PC.
 * - Un Œuf dans l'équipe éclot au bout de (cycles de l'espèce x 256) pas.
 *
 * Le navigateur envoie les pas par paquets (steps()) ; le serveur refuse les valeurs impossibles.
 */
class BreedingService
{
    public const LEVEL_TEXTS = [
        'very_well' => 'Ils ont l\'air de très bien s\'entendre !',
        'well' => 'Ils ont l\'air de bien s\'entendre.',
        'little' => 'Ils ne s\'entendent pas très bien.',
        'none' => 'Ils préfèrent jouer avec d\'autres Pokémon.',
    ];

    /** Bébés à Encens (réglables dans config/breeding.php, "incense_babies") */
    public const INCENSE_BABIES = [
        'azurill' => 'sea-incense', 'wynaut' => 'lax-incense', 'munchlax' => 'full-incense', 'happiny' => 'luck-incense',
        'mime-jr' => 'odd-incense', 'bonsly' => 'rock-incense', 'budew' => 'rose-incense', 'chingling' => 'pure-incense',
        'mantyke' => 'wave-incense',
    ];

    public function __construct(private PokemonFactory $factory)
    {
    }

    // =====================================================================
    // La Pension : ce qu'elle contient, confier, reprendre
    // =====================================================================

    /** Ce que la Pension affiche : les Pokémon confiés (niveaux gagnés, prix) et leur entente */
    public function state(Character $character): array
    {
        $daycare = $this->daycare($character);
        $compat = $daycare->count() === 2 ? $this->compatibility($daycare[0], $daycare[1]) : null;

        return [
            'slots' => $daycare->map(fn (Pokemon $p) => [
                'id' => $p->id,
                'name' => $p->display_name,
                'speciesName' => $p->species->name,
                'level' => $p->level,
                'gender' => $p->gender,
                'gained' => $p->level - (int) $p->daycare_level,
                'price' => $this->price($p),
                'icon' => $p->species->sprite('icon'),
            ])->values()->all(),
            'max' => (int) config('breeding.slots', 2),
            'compatibility' => $daycare->count() === 2 ? ($compat ?? 'none') : null,
            'compatibilityText' => $daycare->count() === 2 ? self::LEVEL_TEXTS[$compat ?? 'none'] : null,
            'money' => (int) $character->money,
            'partyCount' => $character->party()->count(),
        ];
    }

    /** Confier un Pokémon de l'équipe à la Pension */
    public function deposit(Character $character, Pokemon $pokemon): string
    {
        return DB::transaction(function () use ($character, $pokemon) {
            $pokemon = $character->party()->whereKey($pokemon->id)->lockForUpdate()->first();
            if (! $pokemon) {
                throw new InvalidAction('Ce Pokémon n\'est pas dans votre équipe.');
            }
            if ($pokemon->is_egg) {
                throw new InvalidAction('Nous ne pouvons pas garder un Œuf !');
            }
            $used = $character->daycare()->pluck('box_slot')->all();
            $slot = collect(range(1, (int) config('breeding.slots', 2)))->first(fn ($s) => ! in_array($s, $used, true));
            if (! $slot) {
                throw new InvalidAction('La Pension est pleine : nous gardons déjà deux de vos Pokémon.');
            }
            // Comme au PC : l'équipe doit garder au moins un Pokémon en état de se battre
            $others = $character->battleParty()->where('id', '!=', $pokemon->id)->where('current_hp', '>', 0)->exists();
            if (! $others) {
                throw new InvalidAction('C\'est votre dernier Pokémon en état de se battre !');
            }

            $pokemon->forceFill([
                'location' => 'daycare', 'party_slot' => null, 'box_number' => null, 'box_slot' => $slot,
                'daycare_level' => $pokemon->level,
            ])->save();
            $this->compactParty($character);

            return "Nous prendrons bien soin de {$pokemon->display_name}.";
        });
    }

    /** Reprendre un Pokémon de la Pension : il faut une place dans l'équipe et payer */
    public function withdraw(Character $character, int $pokemonId): array
    {
        return DB::transaction(function () use ($character, $pokemonId) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $pokemon = $character->daycare()->whereKey($pokemonId)->with('species')->first();
            if (! $pokemon) {
                throw new InvalidAction('Ce Pokémon n\'est pas à la Pension.');
            }
            $count = $character->party()->count();
            if ($count >= Pokemon::PARTY_SIZE) {
                throw new InvalidAction('Votre équipe est pleine !');
            }
            $price = $this->price($pokemon);
            if (! $character->spendMoney($price)) {
                throw new InvalidAction("Il vous faut {$price} ₽ pour le reprendre.");
            }
            $pokemon->forceFill([
                'location' => 'party', 'party_slot' => $count + 1, 'box_number' => null, 'box_slot' => null,
                'daycare_level' => null,
            ])->save();

            return ['price' => $price, 'name' => $pokemon->display_name, 'money' => (int) $character->money];
        });
    }

    /** Prix pour reprendre : (niveaux gagnés + 1) x 100 ₽ */
    public function price(Pokemon $pokemon): int
    {
        $gained = max(0, $pokemon->level - (int) ($pokemon->daycare_level ?? $pokemon->level));

        return ($gained + 1) * (int) config('breeding.price_per_level', 100);
    }

    // =====================================================================
    // Compatibilité
    // =====================================================================

    /**
     * Les deux Pokémon peuvent-ils avoir un Œuf ? Renvoie leur entente :
     *   very_well (même espèce, dresseurs d'origine différents), well, little (espèces différentes, même dresseur),
     *   ou null (incompatibles).
     */
    public function compatibility(Pokemon $a, Pokemon $b): ?string
    {
        $ga = $a->species->eggGroups();
        $gb = $b->species->eggGroups();
        if (! $ga || ! $gb || in_array('no-eggs', $ga, true) || in_array('no-eggs', $gb, true)
            || $a->species->is_legendary || $b->species->is_legendary) {
            return null;
        }

        $dittoA = in_array('ditto', $ga, true);
        $dittoB = in_array('ditto', $gb, true);
        if ($dittoA && $dittoB) {
            return null;                       // deux Métamorph : rien
        }
        if (! $dittoA && ! $dittoB) {
            // Un mâle et une femelle, d'un groupe d'Œuf commun (un Pokémon asexué a besoin de Métamorph)
            $genders = [$a->gender, $b->gender];
            sort($genders);
            if ($genders !== ['female', 'male'] || ! array_intersect($ga, $gb)) {
                return null;
            }
        }

        $sameSpecies = $a->pokemon_species_id === $b->pokemon_species_id;
        $sameTrainer = (int) $a->original_trainer_id === (int) $b->original_trainer_id;

        return match (true) {
            $sameSpecies && ! $sameTrainer => 'very_well',
            $sameSpecies || ! $sameTrainer => 'well',
            default => 'little',
        };
    }

    /** % de chances d'avoir un Œuf à chaque tentative (Charme Ovale compris) */
    public function eggChance(Character $character, ?string $level): int
    {
        if (! $level) {
            return 0;
        }
        $charm = Item::where('slug', config('breeding.items.oval_charm'))->first();
        $table = $charm && $character->hasItem($charm) ? 'breeding.chances_oval_charm' : 'breeding.chances';

        return (int) config("{$table}.{$level}", 0);
    }

    // =====================================================================
    // Les pas du joueur : Exp. à la Pension, Œufs, éclosions
    // =====================================================================

    /** Le jeu doit-il compter les pas ? Et dans combien de pas éclot le prochain Œuf de l'équipe ? */
    public function status(Character $character): array
    {
        $eggs = $character->party()->where('is_egg', true)->get();
        $factor = $this->hatchFactor($character);

        return [
            'active' => $eggs->isNotEmpty() || $character->daycare()->exists(),
            'hatchIn' => $eggs->isEmpty() ? null : (int) ceil($eggs->min('egg_steps') / $factor),
        ];
    }

    /**
     * $count pas faits par le joueur (envoyés par paquets par le navigateur).
     * Renvoie : eggs (Œufs envoyés au PC), hatched (Œufs éclos, à montrer), status.
     */
    public function steps(Character $character, int $count): array
    {
        $count = $this->allowedSteps($character, $count);
        $result = ['steps' => $count, 'eggs' => [], 'hatched' => []];
        if ($count <= 0) {
            return $result + ['status' => $this->status($character)];
        }

        DB::transaction(function () use ($character, $count, &$result) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $daycare = $this->daycare($character);

            // 1. Exp. des Pokémon confiés
            foreach ($daycare as $pokemon) {
                $this->gainExperience($pokemon, $count * (int) config('breeding.exp_per_step', 1));
            }

            // 2. Un Œuf tous les 256 pas (si les deux Pokémon sont compatibles)
            $flags = $character->story_flags ?? [];
            $counter = (int) ($flags['daycare_steps'] ?? 0) + $count;
            $every = max(1, (int) config('breeding.egg_check_steps', 256));
            while ($counter >= $every) {
                $counter -= $every;
                if ($daycare->count() === 2) {
                    $level = $this->compatibility($daycare[0], $daycare[1]);
                    if ($level && random_int(1, 100) <= $this->eggChance($character, $level)) {
                        $result['eggs'][] = $this->layEgg($character, $daycare[0], $daycare[1]);
                    }
                }
            }
            $flags['daycare_steps'] = $counter;
            $character->forceFill(['story_flags' => $flags])->save();

            // 3. Les Œufs de l'équipe se rapprochent de l'éclosion (Corps Ardent : 2 fois plus vite)
            $done = $count * $this->hatchFactor($character);
            foreach ($character->party()->where('is_egg', true)->get() as $egg) {
                $egg->egg_steps = max(0, (int) $egg->egg_steps - $done);
                $egg->save();
                if ($egg->egg_steps === 0) {
                    $result['hatched'][] = $this->hatch($character, $egg);
                }
            }
        });

        return $result + ['status' => $this->status($character)];
    }

    /** Anti-triche : pas plus de N pas par seconde depuis le dernier envoi */
    private function allowedSteps(Character $character, int $count): int
    {
        $key = "breeding:last-steps:{$character->id}";
        $now = microtime(true);
        $last = Cache::get($key);
        Cache::put($key, $now, now()->addHour());
        $max = (int) config('breeding.max_batch', 64);
        if ($last !== null) {
            $max = min($max, (int) ceil(($now - (float) $last) * (int) config('breeding.max_steps_per_second', 10)) + 2);
        }

        return max(0, min($count, $max));
    }

    /** 2 si un Pokémon de l'équipe a Corps Ardent (ou Armumagma), sinon 1 */
    private function hatchFactor(Character $character): int
    {
        $fast = $character->battleParty()
            ->whereHas('ability', fn ($q) => $q->whereIn('slug', config('breeding.fast_hatch_abilities', [])))
            ->exists();

        return $fast ? 2 : 1;
    }

    /** De l'Exp. pour un Pokémon de la Pension : il monte de niveau et apprend ses attaques (sans évoluer) */
    public function gainExperience(Pokemon $pokemon, int $amount): void
    {
        if ($pokemon->level >= Pokemon::MAX_LEVEL || $amount <= 0) {
            return;
        }
        $species = $pokemon->species;
        $pokemon->experience = min($species->experienceForLevel(Pokemon::MAX_LEVEL), $pokemon->experience + $amount);

        while ($pokemon->level < Pokemon::MAX_LEVEL && $pokemon->experience >= $species->experienceForLevel($pokemon->level + 1)) {
            $oldMax = $pokemon->max_hp;
            $pokemon->level++;
            if ($pokemon->current_hp > 0) {
                $pokemon->current_hp = min($pokemon->max_hp, $pokemon->current_hp + $pokemon->max_hp - $oldMax);
            }
            foreach ($species->movesLearnedAt($pokemon->level) as $move) {
                $this->learnForgettingOldest($pokemon, $move);
            }
        }
        $pokemon->save();
    }

    /** Apprend une attaque ; avec 4 attaques, la plus ancienne (1re) est oubliée, comme à la Pension des jeux */
    private function learnForgettingOldest(Pokemon $pokemon, Move $move): void
    {
        $moves = $pokemon->moves()->orderBy('slot')->get();
        if ($moves->contains('move_id', $move->id)) {
            return;
        }
        if ($moves->count() >= 4) {
            // Étape 19 : la plus ancienne qui n'est pas une CS (une CS ne s'oublie que chez le Maître des capacités)
            $oldest = $moves->first(fn ($pm) => ! Move::isLockedHm((int) $pm->move_id));
            if (! $oldest) {
                return;
            }
            $oldest->delete();
            foreach ($moves->reject(fn ($pm) => $pm->id === $oldest->id)->values() as $i => $pm) {
                $pm->forceFill(['slot' => $i + 1])->save();
            }
        }
        $pokemon->moves()->create(['move_id' => $move->id, 'slot' => min(4, $moves->count() + 1), 'current_pp' => $move->pp, 'pp_ups' => 0]);
        $pokemon->unsetRelation('moves');
    }

    // =====================================================================
    // Œufs
    // =====================================================================

    /** Un Œuf est pondu : il part directement au PC. Renvoie le message pour le chat. */
    private function layEgg(Character $character, Pokemon $a, Pokemon $b): array
    {
        $place = $character->nextFreeBoxSlot();
        if (! $place) {
            return ['box' => null, 'message' => 'La Pension a trouvé un Œuf, mais votre PC est plein : il n\'a pas pu vous être envoyé.'];
        }
        ['egg' => $egg, 'moves' => $moves] = $this->makeEgg($character, $a, $b);
        $egg->fill(['location' => 'box', 'party_slot' => null] + $place);
        $egg->character()->associate($character);
        $egg->save();
        foreach ($moves as $i => $move) {
            $egg->moves()->create(['move_id' => $move->id, 'slot' => $i + 1, 'current_pp' => $move->pp, 'pp_ups' => 0]);
        }

        return [
            'box' => $place['box_number'],
            'message' => "La Pension a trouvé un Œuf ! Il a été envoyé au PC (Boîte {$place['box_number']}).",
        ];
    }

    /**
     * Fabrique l'Œuf (pas encore enregistré) à partir des deux parents, avec l'hérédité des jeux récents :
     * espèce de la mère, IV, nature (Pierre Stase), talent, Ball, attaques, chromatique (Masuda).
     * Renvoie ['egg' => Pokemon, 'moves' => ses attaques (à créer une fois l'Œuf enregistré)].
     */
    public function makeEgg(Character $owner, Pokemon $a, Pokemon $b): array
    {
        [$mother, $father] = $this->parents($a, $b);

        // Espèce : la première forme de la mère (Nidoran♀ : Nidoran♀ ou Nidoran♂ ; Lumivole : Muciole ou Lumivole)
        $species = $mother->species->babySpecies();
        $twins = ['nidoran-f' => 'nidoran-m', 'illumise' => 'volbeat'];
        if (isset($twins[$species->slug]) && random_int(0, 1) === 1) {
            $species = PokemonSpecies::where('slug', $twins[$species->slug])->first() ?? $species;
        }
        // Mise à jour (Wiki) : les bébés à Encens (3e et 4e générations). Sans l'Encens tenu par un parent,
        // l'Œuf donne la forme suivante : Marill sans Encens Mer, Ptiravi seulement avec l'Encens Veine...
        $incense = (config('breeding.incense_babies') ?? self::INCENSE_BABIES)[$species->slug] ?? null;
        if ($incense && ! collect([$a, $b])->contains(fn ($p) => $p->heldItem?->slug === $incense)) {
            $species = $species->evolutions()->with('to')->get()->first(fn ($e) => $e->to?->is_obtainable)?->to ?? $species;
        }
        $species->loadMissing('abilities');

        // Nature : un parent qui tient la Pierre Stase la transmet
        $stone = collect([$a, $b])->filter(fn ($p) => $p->heldItem?->slug === config('breeding.items.everstone'));
        $nature = $stone->isNotEmpty() ? $stone->random()->nature : Nature::inRandomOrder()->first();

        // IV : 3 (5 avec le Nœud Destin) viennent d'un parent ou de l'autre, au hasard
        $knot = collect([$a, $b])->contains(fn ($p) => $p->heldItem?->slug === config('breeding.items.destiny_knot'));
        $count = (int) config($knot ? 'breeding.inherited_ivs_destiny_knot' : 'breeding.inherited_ivs', 3);
        $ivs = [];
        foreach (collect(PokemonSpecies::STATS)->shuffle()->take($count) as $stat) {
            $ivs[$stat] = (random_int(0, 1) ? $a : $b)->{"iv_{$stat}"};
        }

        // Chromatique : méthode Masuda si les parents viennent de dresseurs différents
        $rolls = (int) $a->original_trainer_id !== (int) $b->original_trainer_id ? (int) config('breeding.shiny_rolls_masuda', 6) : 1;
        $odds = max(1, (int) config('game.shiny_odds', 4096));
        $shiny = false;
        for ($i = 0; $i < $rolls && ! $shiny; $i++) {
            $shiny = random_int(1, $odds) === 1;
        }

        $egg = $this->factory->make($species, 1, [
            'nature' => $nature,
            'ability' => $this->inheritedAbility($species, $mother),
            'ivs' => $ivs,
            'shiny' => $shiny,
        ]);

        // Ball de la mère (sauf Master Ball, Safari Ball... : une Poké Ball)
        $ball = $mother->caughtBall;
        if (! $ball || in_array($ball->slug, config('breeding.no_inherit_balls', []), true)) {
            $ball = Item::where('slug', 'poke-ball')->first();
        }

        $egg->fill([
            'is_egg' => true,
            'egg_steps' => max(1, (int) $species->hatch_cycles) * (int) config('breeding.steps_per_cycle', 256),
            'current_hp' => 0,       // un Œuf ne combat pas (et ne compte pas comme Pokémon en état de se battre)
            'experience' => 0,
            'friendship' => $species->base_friendship,
            'original_trainer_id' => $owner->trainer_id,
            'original_trainer_name' => $owner->name,
            'caught_ball_id' => $ball?->id,
            'met_location' => 'Pension',
            'met_level' => null,
            'met_at' => now(),
        ]);
        return ['egg' => $egg, 'moves' => $this->eggMoves($species, $mother, $father)];
    }

    /** [mère, père] : la mère est la femelle, ou le parent qui n'est pas Métamorph */
    private function parents(Pokemon $a, Pokemon $b): array
    {
        $dittoA = in_array('ditto', $a->species->eggGroups(), true);
        $dittoB = in_array('ditto', $b->species->eggGroups(), true);
        if ($dittoA) {
            return [$b, $a];
        }
        if ($dittoB) {
            return [$a, $b];
        }

        return $a->gender === 'female' ? [$a, $b] : [$b, $a];
    }

    /** Talent : la mère transmet souvent le sien (caché : 60 %, normal : 80 %), sinon un talent normal au hasard */
    private function inheritedAbility(PokemonSpecies $baby, Pokemon $mother): ?\App\Models\Ability
    {
        $normal = $baby->abilities->where('pivot.is_hidden', false)->values();
        $hidden = $baby->abilities->firstWhere('pivot.is_hidden', true);
        $motherAbilities = $mother->species->abilities()->get();
        $motherHidden = (bool) $motherAbilities->firstWhere('id', $mother->ability_id)?->pivot?->is_hidden;

        if ($motherHidden && $hidden && random_int(1, 100) <= (int) config('breeding.hidden_ability_chance', 60)) {
            return $hidden;
        }
        if (! $motherHidden && random_int(1, 100) <= (int) config('breeding.ability_slot_chance', 80)) {
            $index = $motherAbilities->where('pivot.is_hidden', false)->values()->search(fn ($ab) => $ab->id === $mother->ability_id);
            if ($index !== false && isset($normal[$index])) {
                return $normal[$index];
            }
        }

        return $normal->isEmpty() ? $baby->abilities->first() : $normal->random();
    }

    /**
     * Les attaques de l'Œuf (4 au plus, les dernières de la liste gardées) :
     *   1. celles que le bébé connaît au niveau 1 ;
     *   2. celles que les DEUX parents connaissent et que le bébé apprend par niveau ;
     *   3. ses attaques Œuf que l'un ou l'autre parent connaît.
     */
    public function eggMoves(PokemonSpecies $baby, Pokemon $mother, Pokemon $father): Collection
    {
        $mother->loadMissing('moves');
        $father->loadMissing('moves');
        $motherMoves = $mother->moves->pluck('move_id')->all();
        $fatherMoves = $father->moves->pluck('move_id')->all();
        $learnset = $baby->moves()->get();

        $list = $baby->levelUpMovesUpTo(1)->all();
        foreach ($learnset->where('pivot.learn_method', 'level_up')->sortBy('pivot.level') as $move) {
            if (in_array($move->id, $motherMoves, true) && in_array($move->id, $fatherMoves, true)) {
                $list[] = $move;
            }
        }
        foreach ($learnset->where('pivot.learn_method', 'egg') as $move) {
            if (in_array($move->id, $motherMoves, true) || in_array($move->id, $fatherMoves, true)) {
                $list[] = $move;
            }
        }

        // Une attaque en double garde sa DERNIÈRE place (les attaques héritées passent après celles du niveau 1)
        $unique = [];
        foreach (array_reverse($list) as $move) {
            $unique[$move->id] ??= $move;
        }

        return collect(array_reverse(array_values($unique)))->take(-4)->values();
    }

    /** L'Œuf éclot : c'est maintenant un Pokémon du joueur, enregistré au Pokédex. Renvoie de quoi l'animer. */
    public function hatch(Character $character, Pokemon $egg): array
    {
        $egg->loadMissing('species', 'nature');
        $location = MapInfo::load((string) $character->current_map)?->property('displayName') ?? null;
        $egg->forceFill([
            'is_egg' => false,
            'egg_steps' => null,
            'level' => 1,
            'experience' => $egg->species->experienceForLevel(1),
            'original_trainer_id' => $character->trainer_id,
            'original_trainer_name' => $character->name,
            'met_location' => $location ? "Éclos : {$location}" : 'Éclos d\'un Œuf',
            'met_level' => 1,
            'met_at' => now(),
        ]);
        $egg->current_hp = $egg->max_hp;
        $egg->save();
        $character->registerInPokedex($egg->pokemon_species_id, caught: true);

        $detail = PokemonPresenter::detail($egg->fresh());

        return [
            'pokemon' => $detail,
            'message' => "{$egg->species->name} est sorti de l'Œuf !",
        ];
    }

    // =====================================================================

    /** Les Pokémon de la Pension, avec ce qu'il faut pour les calculs */
    private function daycare(Character $character): Collection
    {
        return $character->daycare()->with('species.abilities', 'nature', 'heldItem', 'caughtBall', 'moves')->get()->values();
    }

    /** Équipe sans trou : 1, 2, 3... (comme StorageService) */
    private function compactParty(Character $character): void
    {
        $party = $character->pokemon()->where('location', 'party')->orderBy('party_slot')->get();
        foreach ($party as $i => $p) {
            $p->forceFill(['party_slot' => 100 + $i])->save();
        }
        foreach ($party as $i => $p) {
            $p->forceFill(['party_slot' => $i + 1])->save();
        }
    }
}
