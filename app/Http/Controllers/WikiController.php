<?php

namespace App\Http\Controllers;

use App\Models\Evolution;
use App\Models\PokemonSpecies;
use App\Models\Type;
use App\Models\WildEncounter;
use App\Services\MapInfo;
use App\Support\WorldRegion;

/**
 * Le WIKI du jeu : toutes les informations utiles aux joueurs.
 * Pour l'instant : le Pokédex des Pokémon disponibles en jeu (pokemon_species.is_obtainable).
 * Pour ajouter une rubrique : une méthode ici, une route dans routes/web.php, une vue dans resources/views/wiki,
 * et une ligne dans SECTIONS.
 */
class WikiController extends Controller
{
    /**
     * Les rubriques du wiki, rangées en GROUPES (menu de gauche et page d'accueil du wiki).
     * Pour ajouter une rubrique : une route dans routes/web.php, une méthode de contrôleur, une vue, et une ligne ici.
     * 'params' : paramètres de la route (une page par activité : wiki.activity + slug).
     */
    public const SECTIONS = [
        'Pokémon' => [
            ['route' => 'wiki.pokedex', 'title' => 'Pokédex', 'text' => 'Tous les Pokémon du jeu : types, statistiques, talents, évolutions, capacités et lieux de capture.'],
            ['route' => 'wiki.evolutions', 'title' => 'Évolutions', 'text' => 'Toutes les façons d\'évoluer : niveau, pierres, Bonheur, jour et nuit, objet tenu, lieu spécial, échange...'],
            ['route' => 'wiki.abilities', 'title' => 'Talents', 'text' => 'Chaque talent, son effet en combat (et hors combat) et les Pokémon qui l\'ont.'],
            ['route' => 'wiki.moves', 'title' => 'Capacités', 'text' => 'Toutes les capacités : type, catégorie, puissance, précision, PP, et qui les apprend.'],
            ['route' => 'wiki.types', 'title' => 'Types', 'text' => 'La table des types : forces, faiblesses et immunités.'],
        ],
        'Objets' => [
            // Étape 16
            ['route' => 'wiki.items', 'title' => 'Objets', 'text' => 'Tous les objets du jeu, poche par poche, avec leur effet, leur prix et où les trouver.'],
        ],
        // Étape 27
        'Joueurs' => [
            ['route' => 'wiki.halloffame', 'title' => 'Panthéon', 'text' => 'Les dresseurs qui ont battu la Ligue Pokémon, et leur équipe de champions.'],
            ['route' => 'wiki.tower', 'title' => 'Tour de Combat', 'text' => 'Les règles de la Tour, et le classement des meilleures séries.'],   // étape 28
        ],
        'Activités' => [
            // Étape 18
            ['route' => 'wiki.daycare', 'title' => 'Pension', 'text' => 'Confier ses Pokémon, obtenir des Œufs, l\'hérédité et les groupes d\'Œuf.'],
            ['route' => 'wiki.activity', 'params' => 'baies', 'title' => 'Buissons à Baies', 'text' => 'Cueillir des Baies, le temps de repousse, et à quoi servent les Baies.'],
            ['route' => 'wiki.activity', 'params' => 'peche', 'title' => 'Pêche', 'text' => 'Les cannes, comment ferrer, et ce qui mord.'],
            ['route' => 'wiki.activity', 'params' => 'safari', 'title' => 'Parc Safari', 'text' => 'Les règles du Parc : Safari Balls, Appâts, Cailloux et pas.'],
            ['route' => 'wiki.activity', 'params' => 'casino', 'title' => 'Casino', 'text' => 'Jetons, machines à sous, roulette et lots.'],
            ['route' => 'wiki.activity', 'params' => 'banque', 'title' => 'Banque', 'text' => 'Déposer et retirer de l\'argent, et les intérêts qui tombent chaque jour.'],
            ['route' => 'wiki.activity', 'params' => 'jour-nuit', 'title' => 'Jour et nuit', 'text' => 'Les moments de la journée et ce qu\'ils changent : Pokémon, évolutions, Sombre Ball.'],
        ],
        'Aide' => [
            ['route' => 'wiki.guide', 'title' => 'Guide du joueur', 'text' => 'Les touches, les menus, le chat, la sauvegarde, les échanges, les combats entre joueurs et le Tenant du titre.'],
            ['route' => 'wiki.battle', 'title' => 'Règles du combat', 'text' => 'Statuts, météo, coups critiques, objets X, obéissance, fuite, Exp., EV et Bonheur.'],
        ],
    ];

    /** Le lien d'une rubrique */
    public static function sectionUrl(array $section): string
    {
        return route($section['route'], $section['params'] ?? []);
    }

    /** La rubrique de la page en cours ? */
    public static function isCurrent(array $section): bool
    {
        if (isset($section['params'])) {
            return request()->routeIs($section['route']) && request()->route('slug') === $section['params'];
        }

        return request()->routeIs($section['route'] . '*') && ! (request()->routeIs('wiki.activity') && $section['route'] !== 'wiki.activity');
    }

    /** Étape 16 : ce que fait un objet (colonne items.effect_code), en clair */
    private const EFFECTS = [
        'heal_hp' => 'Rend :value PV', 'heal_hp_full' => 'Rend tous les PV', 'heal_hp_percent' => 'Rend :value % des PV',
        'full_restore' => 'Rend tous les PV et soigne le statut', 'cure_status' => 'Soigne un problème de statut',
        'revive' => 'Ranime un Pokémon K.O. (:value % des PV)', 'restore_pp' => 'Rend :value PP à une capacité',
        'restore_pp_all' => 'Rend :value PP à toutes les capacités', 'level_up' => 'Fait monter d\'un niveau',
        'add_ev' => 'Augmente une statistique de base', 'evolve' => 'Fait évoluer certains Pokémon',
        'teach' => 'Apprend une capacité', 'catch' => 'Capture un Pokémon sauvage', 'repel' => 'Repousse les Pokémon sauvages (:value pas)',
        'escape' => 'Ramène à l\'entrée d\'une grotte', 'fish' => 'Pêcher face à l\'eau (touche F)', 'ride' => 'Se déplacer plus vite (touche B)',
        // Étape 22
        'revive_all' => 'Ranime toute l\'équipe (tous les PV)', 'pp_up' => 'Augmente les PP max d\'une capacité',
        'ev_down' => 'Baisse une statistique de base, le Pokémon vous aime plus', 'battle_stat' => 'Combat : augmente une stat de :value niveaux',
        'dire_hit' => 'Combat : plus de coups critiques', 'guard_spec' => 'Combat : empêche les baisses de stats (:value tours)',
        'cure_confusion' => 'Combat : soigne la confusion', 'cure_attract' => 'Combat : soigne l\'attirance',
        'flee_wild' => 'Combat : fuite assurée face à un Pokémon sauvage', 'encounter_flute' => 'Rencontres x:value % jusqu\'à la carte suivante',
        'coin_case' => 'Montre vos jetons du Casino',
    ];

    /** Étape 16 : ce que fait un objet TENU (config/held_items.php), en clair */
    private const HELD_EFFECTS = [
        'exp_share' => 'Tenu : reçoit de l\'Exp. sans combattre', 'exp_boost' => 'Tenu : plus d\'Exp.', 'money_boost' => 'Tenu : double l\'argent gagné',
        'leftovers' => 'Tenu : rend des PV à chaque tour', 'berry_heal' => 'Tenu : mangée à la moitié des PV', 'berry_heal_percent' => 'Tenu : mangée à la moitié des PV',
        'berry_cure' => 'Tenu : soigne un statut', 'type_boost' => 'Tenu : renforce un type', 'quick_claw' => 'Tenu : agit parfois en premier',
        'flinch' => 'Tenu : peut apeurer', 'focus_band' => 'Tenu : peut tenir avec 1 PV', 'crit_boost' => 'Tenu : plus de coups critiques',
        'flee' => 'Tenu : fuite assurée', 'no_evolve' => 'Tenu : empêche l\'évolution',
        // Étape 22
        'choice' => 'Tenu : x1,5, mais une seule capacité', 'life_orb' => 'Tenu : dégâts x1,3, perd des PV',
        'expert_belt' => 'Tenu : renforce les attaques super efficaces', 'category_boost' => 'Tenu : renforce les attaques physiques ou spéciales',
        'focus_sash' => 'Tenu : tient avec 1 PV s\'il avait tous ses PV', 'shell_bell' => 'Tenu : récupère des PV en attaquant',
        'black_sludge' => 'Tenu : soigne les Pokémon Poison, blesse les autres', 'evasion' => 'Tenu : l\'adversaire rate plus souvent',
        'accuracy' => 'Tenu : meilleure précision', 'metronome' => 'Tenu : renforce la même capacité utilisée de suite',
        'white_herb' => 'Tenu : annule les baisses de stats', 'power_herb' => 'Tenu : attaque en deux tours immédiate',
        'mental_herb' => 'Tenu : soigne l\'attirance', 'ev_double' => 'Tenu : EV doublés, Vitesse réduite',
        'ev_power' => 'Tenu : plus d\'EV dans une stat, Vitesse réduite', 'toxic_orb' => 'Tenu : empoisonne gravement le porteur',
        'flame_orb' => 'Tenu : brûle le porteur', 'iron_ball' => 'Tenu : Vitesse réduite, touché par les attaques Sol',
        'last' => 'Tenu : agit en dernier', 'sticky_barb' => 'Tenu : blesse le porteur à chaque tour',
        'light_clay' => 'Tenu : Protection et Mur Lumière plus longs', 'big_root' => 'Tenu : les attaques qui drainent soignent plus',
        'grip_claw' => 'Tenu : les attaques qui piègent durent plus longtemps', 'shed_shell' => 'Tenu : peut toujours être rappelé',
        'species_stat' => 'Tenu par une espèce précise : stat doublée', 'species_crit' => 'Tenu par une espèce précise : plus de coups critiques',
        'species_type_boost' => 'Tenu par une espèce précise : renforce ses types', 'resist_berry' => 'Tenu : affaiblit une attaque super efficace',
        'pinch_berry' => 'Tenu : mangée au quart des PV (bonus)', 'confuse_berry' => 'Tenu : mangée au quart des PV (peut rendre confus)',
        'leppa_berry' => 'Tenu : rend des PP à une capacité vide', 'persim_berry' => 'Tenu : soigne la confusion',
        'retaliate_berry' => 'Tenu : blesse l\'attaquant', 'enigma_berry' => 'Tenu : soigne après une attaque super efficace',
        'soothe_bell' => 'Tenu : le Pokémon vous aime plus vite', 'encounters' => 'Tenu (en tête) : moins de Pokémon sauvages',
    ];

    /** Étape 18 : objets utiles à la Pension (affichés dans le Wiki → Objets) */
    private const BREEDING_ITEMS = [
        'oval-charm' => 'Pension : plus d\'Œufs',
        'destiny-knot' => 'Pension : transmet 5 IV',
        'everstone' => 'Pension : transmet la nature',
    ];

    /**
     * Étape 18 : la PENSION. Les règles viennent de config/breeding.php (elles restent justes si tu les changes),
     * et les groupes d'Œuf de la base (pokemon_species.egg_groups).
     */
    public function daycare()
    {
        $species = PokemonSpecies::obtainable()->orderBy('pokedex_number')->get();
        $names = config('breeding.groups', []);
        $groups = collect($names)->map(fn ($name, $key) => [
            'key' => $key,
            'name' => $name,
            'species' => $species->filter(fn ($s) => in_array($key, $s->eggGroups(), true))->values(),
        ])->filter(fn ($g) => $g['species']->isNotEmpty())->values();

        return view('wiki.daycare', [
            'groups' => $groups,
            'species' => $species,
            'cycle' => (int) config('breeding.steps_per_cycle', 256),
            'chances' => config('breeding.chances'),
            'charm' => config('breeding.chances_oval_charm'),
            'every' => (int) config('breeding.egg_check_steps', 256),
            'price' => (int) config('breeding.price_per_level', 100),
            'ivs' => (int) config('breeding.inherited_ivs', 3),
            'knotIvs' => (int) config('breeding.inherited_ivs_destiny_knot', 5),
            'hidden' => (int) config('breeding.hidden_ability_chance', 60),
            'slot' => (int) config('breeding.ability_slot_chance', 80),
            'masuda' => (int) config('breeding.shiny_rolls_masuda', 6),
            'shinyOdds' => (int) config('game.shiny_odds', 4096),
            // Étape 23 : les bébés à Encens (seulement ceux du jeu)
            'incense' => collect(config('breeding.incense_babies') ?? \App\Services\BreedingService::INCENSE_BABIES)
                ->map(function ($item, $slug) {
                    $baby = PokemonSpecies::obtainable()->where('slug', $slug)->with('evolutions.to')->first();

                    return $baby ? ['baby' => $baby->name, 'item' => \App\Models\Item::where('slug', $item)->value('name') ?? $item,
                        'next' => $baby->evolutions->first()?->to?->name ?? '—'] : null;
                })->filter()->values(),
            'sections' => self::SECTIONS,
        ]);
    }

    public function index()
    {
        return view('wiki.index', ['sections' => self::SECTIONS]);
    }

    public function pokedex()
    {
        $species = PokemonSpecies::obtainable()
            ->with('primaryType', 'secondaryType')
            ->orderBy('pokedex_number')
            ->get();

        return view('wiki.pokedex', [
            'species' => $species,
            // Mise à jour (étape 23) : filtre par région (Kanto, Johto, Hoenn, Sinnoh...)
            'regions' => collect(config('game.regions', []))->only($species->pluck('generation')->unique()->all()),
            'types' => Type::whereIn('id', $species->pluck('primary_type_id')->merge($species->pluck('secondary_type_id'))->filter()->unique())->orderBy('name')->get(),
            'sections' => self::SECTIONS,
        ]);
    }

    public function pokemon(string $slug)
    {
        $species = PokemonSpecies::obtainable()
            ->where('slug', $slug)
            ->with('primaryType', 'secondaryType', 'abilities', 'evolutions.to', 'evolutions.item', 'preEvolution.from')
            ->firstOrFail();

        // Mise à jour (étape 23) : toute la famille d'évolution, du bébé à la dernière forme
        $family = self::evolutionFamily($species);

        // Capacités apprises par niveau, puis par CT
        $moves = $species->moves()->with('type')->orderByPivot('level')->get();

        // Où le trouver : les tables de rencontres sauvages, regroupées par carte (sans la salle de test).
        // Mise à jour (étape 23) : le moment de la journée (matin, jour, nuit)
        $times = ['morning' => 'Matin', 'day' => 'Jour', 'night' => 'Nuit'];
        $locations = WildEncounter::where('pokemon_species_id', $species->id)->whereIn('map_key', WorldRegion::maps())->get()
            ->groupBy('map_key')
            ->map(fn ($rows, $map) => [
                'name' => MapInfo::load($map)?->property('displayName') ?? $map,
                'levels' => $rows->min('min_level') . ' – ' . $rows->max('max_level'),
                'methods' => $rows->pluck('method')->unique()->values()->all(),
                'times' => $rows->contains(fn ($r) => $r->time_of_day === null) ? 'Toute la journée'
                    : $rows->pluck('time_of_day')->unique()->map(fn ($t) => $times[$t] ?? $t)->join(', '),
            ])->values();

        // Précédent / suivant dans le Pokédex (parmi les Pokémon disponibles)
        $previous = PokemonSpecies::obtainable()->where('pokedex_number', '<', $species->pokedex_number)->orderByDesc('pokedex_number')->first();
        $next = PokemonSpecies::obtainable()->where('pokedex_number', '>', $species->pokedex_number)->orderBy('pokedex_number')->first();

        return view('wiki.pokemon', [
            'species' => $species,
            'levelMoves' => $moves->where('pivot.learn_method', 'level_up')->values(),
            'tmMoves' => $moves->where('pivot.learn_method', 'tm')->sortBy('name')->values(),
            'eggMoves' => $moves->where('pivot.learn_method', 'egg')->sortBy('name')->values(),   // étape 23
            'family' => $family,
            'locations' => $locations,
            'previous' => $previous,
            'next' => $next,
            'sections' => self::SECTIONS,
        ]);
    }

    /**
     * Étape 16 : tous les OBJETS, rangés par poche du Sac, avec leur effet et où les trouver
     * (boutiques, Poké Balls posées sur les cartes, PNJ qui offrent, dresseurs, récompenses du Pokédex).
     */
    public function items()
    {
        $sources = $this->itemSources();
        // Les objets des générations 1 à 4, et ceux d'après seulement s'ils s'obtiennent en jeu
        $items = \App\Models\Item::with('move.type')->orderBy('name')->get()
            ->filter(fn ($i) => WorldRegion::itemGeneration($i->slug) !== null || ! empty($sources[$i->slug]))->values();
        $evolves = $this->evolutionItems();   // étape 23

        $pockets = collect(\App\Services\BagService::POCKETS)->map(fn ($name, $number) => [
            'number' => $number,
            'name' => $name,
            'items' => $items->filter(fn ($i) => (\App\Services\BagService::POCKET_OF[$i->pocket] ?? 1) === $number)
                ->map(function (\App\Models\Item $i) use ($sources) {
                    $effect = self::EFFECTS[$i->effect_code] ?? null;
                    $held = config("held_items.items.{$i->slug}.effect");

                    return [
                        'slug' => $i->slug,
                        'name' => $i->name,
                        'icon' => asset('game/items/' . self::itemIconOrUnknown($i) . '.png'),
                        'description' => \App\Services\PokemonPresenter::clean($i->description),
                        'effect' => $effect ? str_replace(':value', (string) $i->effect_value, $effect) : (self::BREEDING_ITEMS[$i->slug] ?? null),
                        'held' => $held ? (self::HELD_EFFECTS[$held] ?? null) : null,
                        'price' => \App\Services\ShopService::priceOf($i),
                        'sources' => $sources[$i->slug] ?? [],
                        'evolves' => $evolves[$i->slug] ?? [],   // étape 23 : « Évoli → Pyroli »
                    ];
                })->values(),
        ])->filter(fn ($p) => $p['items']->isNotEmpty())->values();

        return view('wiki.items', ['pockets' => $pockets, 'sections' => self::SECTIONS, 'total' => $items->count()]);
    }

    /** L'icône de l'objet (public/game/items), ou le « ? » d'Essentials (000.png) si l'image manque */
    public static function itemIconOrUnknown(\App\Models\Item $item): string
    {
        $icon = \App\Services\PokemonPresenter::itemIcon($item);

        return is_file(public_path("game/items/{$icon}.png")) ? $icon : '000';
    }

    /**
     * Où trouver chaque objet : [slug => ['Boutique : Boutique de Bachut', 'Poké Ball : Route 1', ...]].
     * Seulement les lieux du MONDE (App\Support\WorldRegion : Crémieu, Route 1, Bachut, ORT, Parc Safari) :
     * pas les cartes de démonstration ni de test.
     */
    private function itemSources(): array
    {
        $sources = [];
        $add = function (?string $slug, string $where) use (&$sources) {
            if ($slug && ! in_array($where, $sources[$slug] ?? [], true)) {
                $sources[$slug][] = $where;
            }
        };
        $plural = fn (int $n) => $n . ' badge' . ($n > 1 ? 's' : '');

        $shops = [];       // boutique => [noms des cartes où elle se trouve]
        $counters = [];    // comptoirs des lots du Casino => [noms des cartes]
        $trainers = [];    // slug du dresseur => nom de la carte
        foreach (WorldRegion::maps() as $key) {
            $map = MapInfo::load($key);
            if (! $map) {
                continue;
            }
            $name = $map->property('displayName') ?? $key;
            // Poké Balls par terre (étape 16), PNJ qui offrent un objet (étape 14), buissons à Baies (étape 22)
            foreach ($map->itemBalls() as $ball) {
                $add($ball['item'], ($ball['hidden'] ? 'Objet caché' : 'Poké Ball') . " : {$name}");
            }
            foreach ($map->gifts() as $gift) {
                $add($gift['item'], "Cadeau d'un PNJ : {$name}");
            }
            foreach ($map->berryBushes() as $bush) {
                $add($bush['berry'], "Buisson à Baies : {$name}");
            }
            foreach (array_keys(config('shops', [])) as $shop) {
                if (is_array(config("shops.{$shop}")) && $shop !== 'prices' && $map->hasShop($shop)) {
                    $shops[$shop][] = $name;
                }
            }
            foreach ($map->casinoOptions('prizes') as $counter) {
                $counters[$counter][] = $name;
            }
            foreach (array_keys($map->trainers()) as $slug) {
                $trainers[$slug] ??= $name;
            }
        }

        // Boutiques présentes dans le monde (config/shops.php : [badges => [objets]])
        foreach ($shops as $shop => $places) {
            foreach (config("shops.{$shop}") as $badges => $slugs) {
                foreach ((array) $slugs as $slug) {
                    foreach (array_unique($places) as $place) {
                        $add($slug, "Boutique : {$place}" . ($badges > 0 ? ' (' . $plural((int) $badges) . ')' : ''));
                    }
                }
            }
        }

        // Les PNJ des scénarios (resources/scenario : « donner objet »), avec leurs conditions (badges)
        foreach (WorldRegion::scenarioGifts() as $gift) {
            $add($gift['item'], ($gift['buy'] ? 'Achat' : 'Cadeau') . " : {$gift['who']} ({$gift['map']})" . ($gift['condition'] ? " — {$gift['condition']}" : ''));
        }

        // Dresseurs placés dans le monde (trainers.reward_item_id : CT des Champions, Cherche VS, Canne...)
        foreach (\App\Models\Trainer::whereNotNull('reward_item_id')->whereIn('slug', array_keys($trainers))->with('rewardItem')->get() as $trainer) {
            $add($trainer->rewardItem?->slug, "Récompense : {$trainer->trainer_class} {$trainer->name} ({$trainers[$trainer->slug]})");
        }
        // Récompenses du Pokédex (config/pokedex.php)
        foreach (config('pokedex.rewards', []) as $tier => $reward) {
            foreach (array_keys($reward['items'] ?? []) as $slug) {
                $add($slug, $tier === 'complete' ? 'Pokédex complet' : "Pokédex : {$tier} capturés");
            }
        }

        // Étape 20 : les comptoirs des lots du Casino présents dans le monde (config/game_corner.php)
        foreach (array_keys($counters) as $counter) {
            foreach (config("game_corner.prizes.{$counter}", []) as $p) {
                $slug = ! empty($p['item']) ? $p['item'] : (! empty($p['tm'])
                    ? \App\Models\Item::where('effect_code', 'teach')->whereHas('move', fn ($q) => $q->where('slug', $p['tm']))->value('slug') : null);
                $add($slug, 'Casino : ' . number_format((int) $p['price'], 0, ',', ' ') . ' jetons');
            }
        }

        return $sources;
    }

    /**
     * Mise à jour (étape 23) : la famille d'évolution en "étages" : [[bébé], [formes suivantes], [dernières formes]].
     * Chaque case : ['species' => PokemonSpecies, 'evolution' => Evolution qui y mène ou null]. Munja (Ningale) compris.
     */
    public static function evolutionFamily(PokemonSpecies $species): array
    {
        $root = $species;
        for ($i = 0; $i < 5 && ($from = $root->preEvolution?->from) && $from->is_obtainable; $i++) {
            $root = $from;
        }
        $stages = [[['species' => $root, 'evolution' => null]]];
        for ($depth = 0; $depth < 4; $depth++) {
            $next = [];
            foreach ($stages[$depth] as $cell) {
                $cell['species']->loadMissing('evolutions.to', 'evolutions.item', 'evolutions.heldItem', 'evolutions.knownMove', 'evolutions.partySpecies');
                foreach ($cell['species']->evolutions as $evolution) {
                    if ($evolution->to?->is_obtainable) {
                        $next[] = ['species' => $evolution->to, 'evolution' => $evolution];
                    }
                }
            }
            if (! $next) {
                break;
            }
            $stages[] = $next;
        }

        return count($stages) > 1 ? $stages : [];
    }

    /** Mise à jour (étape 23) : les Pokémon qu'un objet fait évoluer (utilisé ou tenu) : [slug => ['Évoli → Pyroli', ...]] */
    private function evolutionItems(): array
    {
        $out = [];
        foreach (Evolution::with('from', 'to', 'item', 'heldItem')->where(fn ($q) => $q->whereNotNull('item_id')->orWhereNotNull('held_item_id'))->get() as $e) {
            if (! $e->to?->is_obtainable || ! $e->from) {
                continue;
            }
            foreach (array_filter([$e->item?->slug, $e->heldItem?->slug]) as $slug) {
                $out[$slug][] = "{$e->from->name} → {$e->to->name}" . ($e->heldItem?->slug === $slug ? ' (' . ($e->trigger === 'trade' ? 'tenu, échange' : 'tenu' . ($e->time_of_day ? ($e->time_of_day === 'night' ? ', la nuit' : ', le jour') : '')) . ')' : '');
            }
        }

        return $out;
    }

    /** Texte d'une condition d'évolution : "N. 16", "Pierre Feu", "Échange" (étape 23 : "Bonheur, la nuit"...) */
    public static function evolutionLabel(Evolution $e): string
    {
        return ucfirst(str_replace('niv. ', 'N. ', $e->conditionLabel()));
    }
}
