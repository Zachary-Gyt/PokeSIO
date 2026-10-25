<?php

namespace App\Http\Controllers;

use App\Models\Evolution;
use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\WildEncounter;
use App\Services\BattleService;
use App\Services\GameCornerService;
use App\Services\MapInfo;
use App\Services\TimeOfDay;

/**
 * Wiki : les pages d'AIDE et d'ACTIVITÉS. Le texte est dans les vues ; tous les chiffres viennent de la
 * configuration (config/*.php) : si tu changes un réglage, le Wiki suit.
 */
class WikiGuideController extends Controller
{
    /** Les activités : slug => vue */
    private const ACTIVITIES = ['baies', 'peche', 'safari', 'casino', 'jour-nuit', 'banque'];

    public function guide()
    {
        return view('wiki.guide', [
            'fieldBadges' => config('field_moves.require_badges') ? config('field_moves.moves', []) : [],
            'moves' => \App\Models\Move::whereIn('slug', array_keys(config('field_moves.moves', [])))->pluck('name', 'slug'),
        ]);
    }

    public function battle()
    {
        return view('wiki.battle', [
            'obey' => collect([0, 2, 4, 6, 8])->mapWithKeys(fn ($b) => [$b => BattleService::obeyCap($b)]),
            'friendship' => config('items.friendship'),
            'whiteout' => [8, 16, 24, 36, 48, 64, 80, 100, 120],
        ]);
    }

    /** Étape 28 : la Tour de Combat (règles d'après config/battle_tower.php, et le classement) */
    public function tower()
    {
        $tower = app(\App\Services\BattleTowerService::class);
        $set = (int) config('battle_tower.set_length', 7);

        return view('wiki.tower', [
            'records' => $tower->leaderboard(50),
            'config' => config('battle_tower'),
            // Le niveau des combats, série par série (il monte jusqu'au niveau maximum)
            'levels' => collect(range(0, 30))->mapWithKeys(fn ($i) => [$i * $set + 1 => $tower->levelFor($i * $set + 1)])
                ->unique()->all(),
        ]);
    }

    public function activity(string $slug)
    {
        abort_unless(in_array($slug, self::ACTIVITIES, true), 404);

        $data = match ($slug) {
            'baies' => $this->berries(),
            'peche' => $this->fishing(),
            'safari' => ['safari' => config('safari')],
            'casino' => $this->casino(),
            'jour-nuit' => $this->dayNight(),
            'banque' => $this->bank(),
        };

        return view("wiki.activities.{$slug}", $data);
    }

    /** La Banque : le taux, le plafond, et ce que deviennent 10 000 ₽ */
    private function bank(): array
    {
        $bank = app(\App\Services\BankService::class);
        $examples = [];
        foreach ([1, 3, 7, 14, 30] as $days) {
            $examples[$days] = (int) floor(min($bank->maxBalance(), 10000 * (1 + $bank->rate()) ** $days));
        }

        return [
            'percent' => str_replace('.', ',', (string) round($bank->rate() * 100, 2)),
            'maxBalance' => $bank->maxBalance(),
            'maxMoney' => \App\Models\Character::MAX_MONEY,
            'examples' => $examples,
        ];
    }

    private function berries(): array
    {
        $bushes = [];
        foreach (\App\Support\WorldRegion::maps() as $key) {   // seulement les cartes du monde
            $map = MapInfo::load($key);
            foreach ($map?->berryBushes() ?? [] as $bush) {
                $bushes[$bush['berry']][] = $map->property('displayName') ?? $key;
            }
        }
        $berries = Item::where('pocket', 'berries')->orderBy('id')->get()->map(fn ($i) => [
            'name' => $i->name, 'slug' => $i->slug,
            'icon' => asset('game/items/' . \App\Services\PokemonPresenter::itemIcon($i) . '.png'),
            'description' => \App\Services\PokemonPresenter::clean($i->description),
            'places' => array_values(array_unique($bushes[$i->slug] ?? [])),
        ]);

        return ['berries' => $berries, 'hours' => config('berries.hours', 24), 'yield' => config('berries.yield', [1, 3])];
    }

    /** Les cannes, et les Pokémon que chacune peut attraper (hors salle de test) */
    private function fishing(): array
    {
        $rods = collect(config('fishing.rods', []))->map(function ($rod, $slug) {
            $species = WildEncounter::where('method', $rod['method'])->whereIn('map_key', \App\Support\WorldRegion::maps())->with('species')->get()
                ->pluck('species')->filter(fn ($s) => $s?->is_obtainable)->unique('id')->sortBy('pokedex_number')->values();

            return $rod + ['name' => Item::where('slug', $slug)->value('name') ?? $slug, 'species' => $species];
        });

        return ['rods' => $rods, 'reaction' => (int) config('fishing.reaction', 1500),
            'wait' => [(int) config('fishing.wait_min', 1500), (int) config('fishing.wait_max', 4000)]];
    }

    private function casino(): array
    {
        $corner = app(GameCornerService::class);
        // Seulement les comptoirs des lots présents dans le monde (le Casino de Bachut)
        $present = collect(\App\Support\WorldRegion::maps())->flatMap(fn ($k) => MapInfo::load($k)?->casinoOptions('prizes') ?? [])->unique()->all();
        $counters = array_intersect_key(['pokemon' => 'Pokémon', 'tms' => 'CT', 'items' => 'Objets'], array_flip($present));

        return [
            'max' => $corner->max(),
            'packs' => $corner->packs(),
            'prizes' => collect($counters)->map(fn ($name, $key) => ['name' => $name, 'list' => $corner->prizes($key)])->filter(fn ($c) => $c['list']),
            'payouts' => config('game_corner.slots.payouts', []),
            'rounds' => (int) config('game_corner.roulette.rounds', 6),
        ];
    }

    private function dayNight(): array
    {
        $timed = WildEncounter::whereNotNull('time_of_day')->whereIn('map_key', \App\Support\WorldRegion::maps())->with('species')->get()
            ->filter(fn ($e) => $e->species?->is_obtainable)
            ->groupBy('time_of_day')
            ->map(fn ($rows) => $rows->pluck('species')->unique('id')->sortBy('pokedex_number')->values());

        return [
            'times' => config('game.time', ['morning' => 4, 'day' => 10, 'night' => 20]),
            'clock' => TimeOfDay::clock(),
            'timed' => $timed,
            'evolutions' => Evolution::with('from', 'to', 'heldItem')->whereNotNull('time_of_day')->get()
                ->filter(fn ($e) => $e->from?->is_obtainable && $e->to?->is_obtainable)->values(),
        ];
    }
}
