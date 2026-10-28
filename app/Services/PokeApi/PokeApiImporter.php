<?php

namespace App\Services\PokeApi;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Remplit les tables de référence depuis PokéAPI.
 * Idempotent : on peut le relancer, les lignes sont retrouvées par leur `slug` et mises à jour.
 */
class PokeApiImporter
{
    private const STAT_MAP = [
        'hp' => 'hp',
        'attack' => 'attack',
        'defense' => 'defense',
        'special-attack' => 'sp_attack',
        'special-defense' => 'sp_defense',
        'speed' => 'speed',
    ];

    private const LEARN_METHODS = [
        'level-up' => 'level_up',
        'machine' => 'tm',
        'egg' => 'egg',
        'tutor' => 'tutor',
    ];

    private const GROWTH_RATES = [
        'slow' => 'slow',
        'medium' => 'medium_fast',
        'fast' => 'fast',
        'medium-slow' => 'medium_slow',
        'slow-then-very-fast' => 'erratic',
        'fast-then-very-slow' => 'fluctuating',
    ];

    private string $lang;

    /** Caches slug => id pour éviter les requêtes répétées */
    private array $typeIds = [];
    private array $moveIds = [];
    private array $abilityIds = [];
    private array $itemIds = [];

    /** @var Closure(string):void|null */
    private ?Closure $logger = null;

    public function __construct(
        private PokeApiClient $api,
        private bool $downloadSprites = false,
    ) {
        $this->lang = config('pokeapi.language', 'fr');
    }

    public function setLogger(Closure $logger): void
    {
        $this->logger = $logger;
    }

    // ---------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------

    public function importTypes(): int
    {
        $colors = config('pokeapi.type_colors');
        $data = [];

        foreach ($this->api->list('type') as $entry) {
            // On ignore les types spéciaux ("unknown", "shadow", "stellar")
            if (! array_key_exists($entry['name'], $colors)) {
                continue;
            }
            $type = $this->api->get($entry['url']);
            $data[$type['name']] = $type;

            $this->typeIds[$type['name']] = $this->upsert('types', ['slug' => $type['name']], [
                'name' => $this->localizedName($type),
                'color' => $colors[$type['name']],
            ]);
        }

        // Une fois tous les types créés, la table des efficacités
        DB::table('type_efficacies')->delete();
        $rows = [];
        foreach ($data as $slug => $type) {
            $relations = [
                'double_damage_to' => 2,
                'half_damage_to' => 0.5,
                'no_damage_to' => 0,
            ];
            foreach ($relations as $key => $multiplier) {
                foreach ($type['damage_relations'][$key] as $target) {
                    if (isset($this->typeIds[$target['name']])) {
                        $rows[] = [
                            'attacking_type_id' => $this->typeIds[$slug],
                            'defending_type_id' => $this->typeIds[$target['name']],
                            'multiplier' => $multiplier,
                        ];
                    }
                }
            }
        }
        DB::table('type_efficacies')->insert($rows);

        return count($data);
    }

    // ---------------------------------------------------------------------
    // Natures
    // ---------------------------------------------------------------------

    public function importNatures(): int
    {
        $count = 0;
        foreach ($this->api->list('nature') as $entry) {
            $nature = $this->api->get($entry['url']);
            $up = self::STAT_MAP[$nature['increased_stat']['name'] ?? ''] ?? null;
            $down = self::STAT_MAP[$nature['decreased_stat']['name'] ?? ''] ?? null;

            if ($up === $down) { // nature neutre
                $up = $down = null;
            }

            $this->upsert('natures', ['slug' => $nature['name']], [
                'name' => $this->localizedName($nature),
                'increased_stat' => $up,
                'decreased_stat' => $down,
            ]);
            $count++;
        }

        return $count;
    }

    // ---------------------------------------------------------------------
    // Objets
    // ---------------------------------------------------------------------

    /** Slugs des objets à importer (catégories de la config + objets rares). */
    public function itemSlugs(): array
    {
        $slugs = [];
        foreach (config('pokeapi.item_categories') as $category) {
            foreach ($this->api->get("item-category/{$category}")['items'] as $item) {
                $slugs[$item['name']] = true;
            }
        }
        foreach (config('pokeapi.key_items') as $slug) {
            $slugs[$slug] = true;
        }

        return array_keys($slugs);
    }

    public function importItem(string $slug): ?int
    {
        if (isset($this->itemIds[$slug])) {
            return $this->itemIds[$slug];
        }

        try {
            $item = $this->api->get("item/{$slug}");
        } catch (\RuntimeException $e) {
            $this->log($e->getMessage());

            return null;
        }

        $category = $item['category']['name'];
        $attributes = array_column($item['attributes'], 'name');
        $isKey = in_array($slug, config('pokeapi.key_items'), true);

        if ($isKey) {
            $pocket = 'key_items';
        } elseif (in_array($category, config('pokeapi.held_item_categories'), true)) {
            $pocket = 'held_items';
        } else {
            $apiPocket = $this->api->get("item-category/{$category}")['pocket']['name'];
            $pocket = config("pokeapi.pocket_map.{$apiPocket}", 'misc');
        }

        [$effectCode, $effectValue] = config("pokeapi.item_effects.{$slug}")
            ?? [config("pokeapi.category_effects.{$category}"), null];

        if ($effectCode === 'catch' && $effectValue === null) {
            $effectValue = 10; // balls spéciales : x1 par défaut, à ajuster à la main
        }

        $sprite = $item['sprites']['default'] ?? null;
        if ($sprite && $this->downloadSprites) {
            $sprite = $this->downloadFile($sprite, "sprites/items/{$slug}.png");
        }

        return $this->itemIds[$slug] = $this->upsert('items', ['slug' => $slug], [
            'name' => $this->localizedName($item),
            'pocket' => $pocket,
            'category' => $category,
            'description' => $this->flavorText($item['flavor_text_entries'], 'text'),
            'sprite' => $sprite,
            'price' => $item['cost'] ?? 0,
            'sell_price' => intdiv($item['cost'] ?? 0, 2),
            'consumable' => ! $isKey && in_array('consumable', $attributes, true),
            'tradable' => ! $isKey,
            'usable_in_battle' => in_array('usable-in-battle', $attributes, true),
            'usable_overworld' => in_array('usable-overworld', $attributes, true),
            'holdable' => in_array('holdable', $attributes, true) || in_array('holdable-active', $attributes, true),
            'effect_code' => $effectCode,
            'effect_value' => $effectValue,
        ]);
    }

    // ---------------------------------------------------------------------
    // Espèces (+ talents, attaques, learnset)
    // ---------------------------------------------------------------------

    public function importSpecies(int $dexNumber): string
    {
        $species = $this->api->get("pokemon-species/{$dexNumber}");
        $defaultVariety = collect($species['varieties'])->firstWhere('is_default', true);
        $pokemon = $this->api->get($defaultVariety['pokemon']['url']);

        $types = collect($pokemon['types'])->sortBy('slot')->pluck('type.name')->values();
        $stats = collect($pokemon['stats'])->mapWithKeys(
            fn ($s) => [self::STAT_MAP[$s['stat']['name']] => $s]
        );
        $generation = $this->romanToInt($species['generation']['name']);

        $values = [
            'pokedex_number' => $species['id'],
            'name' => $this->localizedName($species),
            'generation' => $generation,
            'is_obtainable' => in_array($generation, config('pokeapi.obtainable_generations'), true),
            'category' => $this->localized($species['genera'] ?? [], 'genus'),
            'pokedex_entry' => $this->flavorText($species['flavor_text_entries']),
            'primary_type_id' => $this->typeId($types[0]),
            'secondary_type_id' => isset($types[1]) ? $this->typeId($types[1]) : null,
            'base_experience' => $pokemon['base_experience'] ?? 50,
            'growth_rate' => self::GROWTH_RATES[$species['growth_rate']['name']] ?? 'medium_fast',
            'capture_rate' => $species['capture_rate'],
            'base_friendship' => $species['base_happiness'] ?? 50,
            'gender_rate' => $species['gender_rate'],
            // Étape 18 : la Pension (groupes d'Œuf et cycles d'éclosion)
            'egg_groups' => collect($species['egg_groups'] ?? [])->pluck('name')->implode(',') ?: null,
            'hatch_cycles' => $species['hatch_counter'] ?? 20,
            'height' => $pokemon['height'],
            'weight' => $pokemon['weight'],
            'is_starter' => in_array($species['name'], config('pokeapi.starters'), true),
            'is_legendary' => $species['is_legendary'] || $species['is_mythical'],
            'cry' => $pokemon['cries']['latest'] ?? null,
        ];

        foreach (self::STAT_MAP as $col) {
            $values["base_{$col}"] = $stats[$col]['base_stat'];
            $values["ev_yield_{$col}"] = $stats[$col]['effort'];
        }

        $values += $this->spriteColumns($pokemon, $species['id']);

        $speciesId = $this->upsert('pokemon_species', ['slug' => $species['name']], $values);

        $this->syncAbilities($speciesId, $pokemon['abilities']);
        $this->syncLearnset($speciesId, $pokemon['moves']);

        return $values['name'];
    }

    private function spriteColumns(array $pokemon, int $dex): array
    {
        $cfg = config('pokeapi.sprites');
        $columns = [];

        foreach (['front', 'back', 'front_shiny', 'back_shiny', 'icon'] as $name) {
            // Toutes les URL candidates, dans l'ordre de la config, sans doublon
            $candidates = collect((array) $cfg[$name])
                ->map(fn ($path) => Arr::get($pokemon['sprites'], $path))
                ->filter()
                ->unique()
                ->values();

            $value = $candidates->first();

            if ($this->downloadSprites && $candidates->isNotEmpty()) {
                $value = null;
                foreach ($candidates as $url) {
                    $value = $this->downloadFile($url, "{$cfg['directory']}/{$dex}/{$name}.png", quiet: true);
                    if (! str_starts_with($value, 'http')) {
                        break; // téléchargé
                    }
                }
                if (str_starts_with($value, 'http')) {
                    $this->log("Aucune source disponible pour le sprite {$name} du n°{$dex}");
                }
            }

            $columns["sprite_{$name}"] = $value;
        }

        return $columns;
    }

    private function syncAbilities(int $speciesId, array $abilities): void
    {
        DB::table('pokemon_species_ability')->where('pokemon_species_id', $speciesId)->delete();

        foreach ($abilities as $entry) {
            DB::table('pokemon_species_ability')->insertOrIgnore([
                'pokemon_species_id' => $speciesId,
                'ability_id' => $this->abilityId($entry['ability']['name'], $entry['ability']['url']),
                'is_hidden' => $entry['is_hidden'],
            ]);
        }
    }

    private function syncLearnset(int $speciesId, array $moves): void
    {
        // On choisit UN jeu de référence pour toute l'espèce (premier dispo dans la config)
        $available = [];
        foreach ($moves as $move) {
            foreach ($move['version_group_details'] as $detail) {
                $available[$detail['version_group']['name']] = true;
            }
        }
        $versionGroup = collect(config('pokeapi.version_groups'))->first(fn ($vg) => isset($available[$vg]))
            ?? array_key_last($available);

        DB::table('pokemon_species_move')->where('pokemon_species_id', $speciesId)->delete();

        $rows = [];
        foreach ($moves as $move) {
            foreach ($move['version_group_details'] as $detail) {
                $method = self::LEARN_METHODS[$detail['move_learn_method']['name']] ?? null;
                if ($detail['version_group']['name'] !== $versionGroup || ! $method) {
                    continue;
                }
                $moveId = $this->moveId($move['move']['name'], $move['move']['url']);
                $key = "{$moveId}-{$method}";
                // Une attaque peut apparaître 2 fois en level_up (ex: niv. 1 et niv. 13) : on garde le plus bas > 0
                $level = $method === 'level_up' ? max(1, $detail['level_learned_at']) : null;
                if (! isset($rows[$key]) || ($level !== null && $level < $rows[$key]['level'])) {
                    $rows[$key] = [
                        'pokemon_species_id' => $speciesId,
                        'move_id' => $moveId,
                        'learn_method' => $method,
                        'level' => $level,
                    ];
                }
            }
        }

        DB::table('pokemon_species_move')->insert(array_values($rows));
    }

    private function abilityId(string $slug, string $url): int
    {
        if (isset($this->abilityIds[$slug])) {
            return $this->abilityIds[$slug];
        }
        $ability = $this->api->get($url);

        return $this->abilityIds[$slug] = $this->upsert('abilities', ['slug' => $slug], [
            'name' => $this->localizedName($ability),
            'description' => $this->flavorText($ability['flavor_text_entries']),
        ]);
    }

    private function moveId(string $slug, string $url): int
    {
        if (isset($this->moveIds[$slug])) {
            return $this->moveIds[$slug];
        }
        $move = $this->api->get($url);
        $meta = $move['meta'] ?? null;

        return $this->moveIds[$slug] = $this->upsert('moves', ['slug' => $slug], [
            'name' => $this->localizedName($move),
            'generation' => $this->romanToInt($move['generation']['name']),
            'type_id' => $this->typeId($move['type']['name']),
            'category' => $move['damage_class']['name'],          // physical / special / status
            'power' => $move['power'],
            'accuracy' => $move['accuracy'],
            'pp' => $move['pp'] ?? 1,
            'priority' => $move['priority'],
            'target' => $move['target']['name'],
            'effect_code' => $meta['category']['name'] ?? null,
            'effect_chance' => $move['effect_chance'],
            'meta' => json_encode([
                'ailment' => $meta['ailment']['name'] ?? 'none',
                'ailment_chance' => $meta['ailment_chance'] ?? 0,
                'flinch_chance' => $meta['flinch_chance'] ?? 0,
                'crit_rate' => $meta['crit_rate'] ?? 0,
                'drain' => $meta['drain'] ?? 0,              // >0 = draine, <0 = recul (% des dégâts)
                'healing' => $meta['healing'] ?? 0,          // % des PV max
                'min_hits' => $meta['min_hits'] ?? null,
                'max_hits' => $meta['max_hits'] ?? null,
                'min_turns' => $meta['min_turns'] ?? null,
                'max_turns' => $meta['max_turns'] ?? null,
                'stat_chance' => $meta['stat_chance'] ?? 0,
                'stat_changes' => collect($move['stat_changes'] ?? [])->map(fn ($c) => [
                    'stat' => self::STAT_MAP[$c['stat']['name']] ?? $c['stat']['name'], // accuracy, evasion...
                    'change' => $c['change'],
                ])->all(),
            ]),
            'description' => $this->flavorText($move['flavor_text_entries']),
        ]);
    }

    private function typeId(string $slug): int
    {
        return $this->typeIds[$slug] ??= DB::table('types')->where('slug', $slug)->value('id')
            ?? throw new \RuntimeException("Type inconnu : {$slug}. Lance d'abord l'import des types.");
    }

    // ---------------------------------------------------------------------
    // Évolutions
    // ---------------------------------------------------------------------

    public function importEvolutions(): int
    {
        $speciesIds = DB::table('pokemon_species')->pluck('id', 'slug');
        $chains = [];
        foreach ($speciesIds->keys() as $slug) {
            $url = $this->api->get("pokemon-species/{$slug}")['evolution_chain']['url'] ?? null;
            if ($url) {
                $chains[$url] = true;
            }
        }

        $count = 0;
        foreach (array_keys($chains) as $url) {
            $chain = $this->api->get($url)['chain'];
            $count += $this->walkChain($chain, $speciesIds->all());
        }

        return $count;
    }

    private function walkChain(array $node, array $speciesIds): int
    {
        $count = 0;
        $from = $node['species']['name'];

        foreach ($node['evolves_to'] as $next) {
            $to = $next['species']['name'];

            // On ne relie que des espèces importées (Nosferalto -> Nostenfer sera ajouté avec la Gen 2)
            if (isset($speciesIds[$from], $speciesIds[$to])) {
                $d = $this->pickEvolutionDetail($next['evolution_details'] ?? []);
                $trigger = match ($d['trigger']['name'] ?? 'other') {
                    'level-up' => ! empty($d['min_happiness']) ? 'friendship' : 'level',
                    'use-item' => 'item',
                    'trade' => 'trade',
                    'shed' => 'shed',   // étape 23 : Munja
                    default => 'other',
                };

                DB::table('evolutions')->updateOrInsert(
                    ['from_species_id' => $speciesIds[$from], 'to_species_id' => $speciesIds[$to]],
                    [
                        'trigger' => $trigger,
                        'min_level' => $d['min_level'] ?? null,
                        'item_id' => $this->evolutionItemId($d['item']['name'] ?? null),
                        'min_friendship' => $d['min_happiness'] ?? null,
                        'time_of_day' => in_array($d['time_of_day'] ?? '', ['day', 'night'], true) ? $d['time_of_day'] : null,
                        // Étape 23 : conditions des générations 2 à 4
                        'held_item_id' => $this->evolutionItemId($d['held_item']['name'] ?? null),
                        'known_move_id' => ! empty($d['known_move']['name']) ? DB::table('moves')->where('slug', $d['known_move']['name'])->value('id') : null,
                        'known_move_type_id' => ! empty($d['known_move_type']['name']) ? ($this->typeIds[$d['known_move_type']['name']] ?? DB::table('types')->where('slug', $d['known_move_type']['name'])->value('id')) : null,
                        'gender' => [1 => 'female', 2 => 'male'][(int) ($d['gender'] ?? 0)] ?? null,
                        'location' => ! empty($d['location']['name']) ? $this->evolutionArea($d['location']['name']) : null,
                        'party_species_id' => ! empty($d['party_species']['name']) ? ($speciesIds[$d['party_species']['name']] ?? null) : null,
                        'relative_stats' => $d['relative_physical_stats'] ?? null,
                    ]
                );
                $count++;
            }

            $count += $this->walkChain($next, $speciesIds);
        }

        return $count;
    }

    /**
     * Étape 23 : PokéAPI donne parfois plusieurs façons d'évoluer (une par jeu). On garde celle des jeux de la
     * 4e génération quand elle existe : le LIEU (Mont Couronné, Forêt de Vestigion, Route 217) plutôt que la
     * pierre de Épée / Bouclier ; et pour Barpau, l'échange avec l'Écaille Prisme plutôt que la Beauté (Poffins).
     */
    private function pickEvolutionDetail(array $details): array
    {
        foreach ($details as $d) {
            if (! empty($d['location'])) {
                return $d;
            }
        }
        foreach ($details as $d) {
            if (empty($d['min_beauty'])) {
                return $d;
            }
        }

        return $details[0] ?? [];
    }

    private function evolutionItemId(?string $slug): ?int
    {
        if (! $slug) {
            return null;
        }

        return $this->itemIds[$slug] ?? DB::table('items')->where('slug', $slug)->value('id') ?? $this->importItem($slug);
    }

    /** Étape 23 : lieu d'évolution PokéAPI -> zone du jeu (config/pokeapi.php, "evolution_areas") */
    private function evolutionArea(string $location): string
    {
        foreach (config('pokeapi.evolution_areas', []) as $area => $locations) {
            if (in_array($location, $locations, true)) {
                return $area;
            }
        }

        return $location;
    }

    // ---------------------------------------------------------------------
    // Utilitaires
    // ---------------------------------------------------------------------

    /** Insère ou met à jour une ligne et renvoie son id. */
    private function upsert(string $table, array $keys, array $values): int
    {
        $now = now();
        $id = DB::table($table)->where($keys)->value('id');

        if ($id) {
            DB::table($table)->where('id', $id)->update($values + ['updated_at' => $now]);

            return $id;
        }

        return DB::table($table)->insertGetId($keys + $values + ['created_at' => $now, 'updated_at' => $now]);
    }

    private function localizedName(array $resource): string
    {
        return $this->localized($resource['names'] ?? [], 'name') ?? ucfirst(str_replace('-', ' ', $resource['name']));
    }

    /** Valeur dans la langue du jeu, sinon en anglais. */
    private function localized(array $entries, string $field): ?string
    {
        foreach ([$this->lang, 'en'] as $lang) {
            foreach ($entries as $entry) {
                if ($entry['language']['name'] === $lang) {
                    return $entry[$field];
                }
            }
        }

        return null;
    }

    /** Description la plus récente dans la langue du jeu (sinon anglais), nettoyée. */
    private function flavorText(array $entries, string $field = 'flavor_text'): ?string
    {
        foreach ([$this->lang, 'en'] as $lang) {
            // Le texte le plus récent, sauf le message d'Épée / Bouclier « Il est recommandé de l'oublier »
            // (capacités retirées de ces jeux) : on prend alors celui d'un jeu plus ancien
            $match = collect($entries)->where('language.name', $lang)
                ->reject(fn ($e) => preg_match(\App\Console\Commands\UpdateMoveDescriptions::OBSOLETE, $e[$field] ?? '')
                    || preg_match('/This move can.t be used|recommended that this move is forgotten/i', $e[$field] ?? ''))
                ->last();
            if ($match) {
                $text = str_replace(["\u{00AD}\n", "\f", "\u{00AD}"], ['', ' ', ''], $match[$field]);

                return trim(preg_replace('/\s+/u', ' ', $text));
            }
        }

        return null;
    }

    private function romanToInt(string $generation): int
    {
        $roman = strtoupper(str_replace('generation-', '', $generation));
        $values = ['I' => 1, 'V' => 5, 'X' => 10];
        $total = 0;
        for ($i = 0, $len = strlen($roman); $i < $len; $i++) {
            $current = $values[$roman[$i]];
            $next = $values[$roman[$i + 1] ?? ''] ?? 0;
            $total += $current < $next ? -$current : $current;
        }

        return $total;
    }

    /** Renvoie le chemin local si le téléchargement réussit, sinon l'URL d'origine. */
    private function downloadFile(string $url, string $path, bool $quiet = false): string
    {
        $disk = Storage::disk(config('pokeapi.sprites.disk'));
        if ($disk->exists($path)) {
            return $path;
        }

        try {
            $response = Http::timeout(30)->retry(2, 1000, throw: false)->get($url);
        } catch (\Throwable $e) {
            $response = null; // erreur réseau
        }

        if ($response?->successful()) {
            $disk->put($path, $response->body());

            return $path;
        }

        // Sprite absent chez PokéAPI (404) : on n'interrompt pas l'import, on garde juste l'URL.
        if (! $quiet) {
            $this->log('Sprite non téléchargé (' . ($response?->status() ?? 'réseau') . ") : {$url}");
        }

        return $url;
    }

    private function log(string $message): void
    {
        $this->logger && ($this->logger)($message);
    }
}
