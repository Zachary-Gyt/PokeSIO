<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Lecture côté serveur d'une carte Tiled (public/game/maps/{clé}.tmj).
 *
 * Le serveur ne fait pas confiance au navigateur : quand le jeu dit "j'ai rencontré un Pokémon
 * en (12, 8)", on vérifie ici que cette case est bien une case de hautes herbes.
 */
class MapInfo
{
    private function __construct(private array $data, private string $key = '')
    {
    }

    /** null si le nom est invalide ou si la carte n'existe pas */
    public static function load(string $mapKey): ?self
    {
        if (! preg_match('/^[a-z0-9_]+$/', $mapKey)) {
            return null;
        }

        $path = public_path("game/maps/{$mapKey}.tmj");
        if (! File::exists($path)) {
            return null;
        }

        // En cache jusqu'à la prochaine modification du fichier (sa date fait partie de la clé),
        // ou d'un tileset partagé (tilesets/*.tsj)
        $data = Cache::remember("map-info:v13:{$mapKey}:" . File::lastModified($path) . "-" . File::size($path) . '-' . self::tilesetStamp(), now()->addDay(), function () use ($path) {
            return self::parse(self::withTilesetData(json_decode(File::get($path), true)));
        });

        return new self($data, $mapKey);
    }

    public function width(): int
    {
        return $this->data['width'];
    }

    public function height(): int
    {
        return $this->data['height'];
    }

    /** Propriété de la carte définie dans Tiled (Carte > Propriétés de la carte), ex. "battleback" */
    public function property(string $name, mixed $default = null): mixed
    {
        return $this->data['properties'][$name] ?? $default;
    }

    /**
     * La carte a-t-elle un objet Tiled avec cette action ? (propriété "action" d'un PNJ ou d'un panneau)
     *   action = heal : infirmière du Centre Pokémon      action = pc : PC de stockage
     */
    public function hasAction(string $action): bool
    {
        return in_array($action, $this->data['actions'] ?? [], true);
    }

    /** Étape 16 : les Poké Balls par terre de la carte ([clé => [x, y, item, quantity, money, pokemon...]]) */
    public function itemBalls(): array
    {
        return $this->data['itemBalls'] ?? [];
    }

    /** Étape 22 : les buissons à Baies (calque d'objets "berries") : [clé => ['key', 'x', 'y', 'berry', 'quantity', 'hours']] */
    public function berryBushes(): array
    {
        return $this->data['berryBushes'] ?? [];
    }

    /** Étape 15 : l'entrée du Parc Safari de cette carte (['map', 'x', 'y', 'facing']), ou null */
    public function safariGate(): ?array
    {
        return $this->data['safariGate'] ?? null;
    }

    /** Étape 14 : le cadeau d'un PNJ de cette carte (['item' => 'bicycle', 'quantity' => 1]), ou null */
    public function gift(string $id): ?array
    {
        return $this->data['gifts'][$id] ?? null;
    }

    /** Étape 16 : tous les cadeaux des PNJ de cette carte (pour le Wiki) */
    public function gifts(): array
    {
        return $this->data['gifts'] ?? [];
    }

    /**
     * Étape 20 : les réglages du Casino présents sur la carte pour une action :
     *   slots -> difficultés ("0", "1", "2"), roulette -> mises ("1", "3"), prizes -> comptoirs ("pokemon"...)
     */
    public function casinoOptions(string $action): array
    {
        return $this->data['casino'][$action] ?? [];
    }

    /** Étape 19 : la sortie qui couvre la case (x, y) : ['x', 'y', 'w', 'h', 'toMap'] ou null */
    public function warpAt(int $x, int $y): ?array
    {
        foreach ($this->data['warps'] ?? [] as $w) {
            if ($x >= $w['x'] && $x < $w['x'] + $w['w'] && $y >= $w['y'] && $y < $w['y'] + $w['h']) {
                return $w;
            }
        }

        return null;
    }

    /**
     * Étape 24 : les objets Tiled liés au scénario (propriétés event, show_if, hide_if), par numéro d'objet Tiled :
     *   [id => ['id', 'name', 'layer', 'x', 'y', 'w', 'h', 'event', 'trigger', 'speaker', 'show_if', 'hide_if']]
     * trigger : 'talk' (PNJ, panneau), 'action' (zone qu'on regarde), 'step' (zone où l'on marche), 'auto' (en arrivant).
     */
    public function eventObjects(): array
    {
        return $this->data['eventObjects'] ?? [];
    }

    /** Étape 26 : les dresseurs posés sur la carte (propriété "trainer" d'un PNJ) : [slug => ['x', 'y']] */
    public function trainers(): array
    {
        return $this->data['trainers'] ?? [];
    }

    public function eventObject(int $id): ?array
    {
        return $this->data['eventObjects'][$id] ?? null;
    }

    /** La carte a-t-elle un vendeur de cette boutique ? (action = shop, propriété shop = clé de config/shops.php) */
    public function hasShop(string $shop): bool
    {
        return in_array($shop, $this->data['shops'] ?? [], true);
    }

    /** La case porte-t-elle une tuile avec cette propriété (calques "ground" et "decor") ? */
    public function tileHasProperty(int $x, int $y, string $property): bool
    {
        if ($x < 0 || $y < 0 || $x >= $this->width() || $y >= $this->height()) {
            return false;
        }
        $index = $y * $this->width() + $x;

        foreach ($this->data['layers'] as $gids) {
            $gid = $gids[$index] ?? 0;
            if ($gid && ! empty($this->data['tileProps'][$gid][$property])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zone de rencontre de la case : un rectangle du calque d'objets "encounters"
     * avec la propriété "zone" (ou "encounter_zone"). Par défaut : "default".
     */
    public function encounterZone(int $x, int $y, string $method = 'grass'): string
    {
        foreach ($this->data['zones'] as $zone) {
            if ($x >= $zone['x'] && $x < $zone['x'] + $zone['w'] && $y >= $zone['y'] && $y < $zone['y'] + $zone['h']) {
                return $zone['name'];
            }
        }

        // Le TERRAIN sous les hautes herbes (propriété de tuile "terrain" : sable, roche, gravier...) :
        // sa table de rencontres si la carte en a une (wild_encounters.zone = le terrain), sinon "default"
        $terrain = $this->tileProperty($x, $y, 'terrain');
        if (is_string($terrain) && $terrain !== '' && $this->key !== ''
            && \App\Models\WildEncounter::where(['map_key' => $this->key, 'zone' => $terrain, 'method' => $method])->exists()) {
            return $terrain;
        }

        return 'default';
    }

    /** La valeur d'une propriété de tuile sur cette case (le premier calque qui l'a, du sol vers le haut), ou null */
    public function tileProperty(int $x, int $y, string $property): mixed
    {
        if ($x < 0 || $y < 0 || $x >= $this->width() || $y >= $this->height()) {
            return null;
        }
        $index = $y * $this->width() + $x;
        foreach ($this->data['layers'] as $gids) {
            $gid = $gids[$index] ?? 0;
            if ($gid && isset($this->data['tileProps'][$gid][$property])) {
                return $this->data['tileProps'][$gid][$property];
            }
        }

        return null;
    }

    /**
     * Étape 11 : l'obstacle du calque d'objets "field" sur cette case, ou null.
     *   'cut' (petit arbre), 'rock_smash' (rocher fissuré), 'strength' (rocher à pousser)
     */
    public function fieldObjectAt(int $x, int $y): ?string
    {
        foreach ($this->data['field'] ?? [] as $o) {
            if ($o['x'] === $x && $o['y'] === $y) {
                return $o['kind'];
            }
        }

        return null;
    }

    /** La carte a-t-elle au moins un obstacle de ce type ? */
    public function hasFieldObject(string $kind): bool
    {
        return collect($this->data['field'] ?? [])->contains('kind', $kind);
    }

    /** Premier objet du calque "spawns" (point d'apparition), ou null */
    public function spawn(): ?array
    {
        return $this->data['spawn'] ?? null;
    }

    /** La date des tilesets partagés (public/game/tilesets/*.tsj) : les changer vide le cache des cartes */
    private static function tilesetStamp(): string
    {
        static $stamp = null;

        return $stamp ??= md5(collect(File::glob(public_path('game/tilesets/*.tsj')))
            ->map(fn ($f) => basename($f) . File::lastModified($f))->implode('|'));
    }

    /**
     * Les propriétés des tuiles partagées par toutes les cartes (public/game/tilesets/<nom>.tsj, voir game:tilesets),
     * recopiées dans la carte ; celles posées dans la carte elle-même restent prioritaires.
     */
    public static function withTilesetData(array $map): array
    {
        foreach ($map['tilesets'] ?? [] as $i => $tileset) {
            $file = public_path('game/tilesets/' . ($tileset['name'] ?? '') . '.tsj');
            if (empty($tileset['name']) || ! File::exists($file)) {
                continue;
            }
            $shared = json_decode(File::get($file), true)['tiles'] ?? [];
            $byId = [];
            foreach ($shared as $t) {
                $byId[$t['id']] = collect($t['properties'] ?? [])->keyBy('name')->all();
            }
            foreach ($tileset['tiles'] ?? [] as $t) {
                $byId[$t['id']] = array_merge($byId[$t['id']] ?? [], collect($t['properties'] ?? [])->keyBy('name')->all());
            }
            $map['tilesets'][$i]['tiles'] = collect($byId)->map(fn ($props, $id) => ['id' => $id, 'properties' => array_values($props)])->values()->all();
        }

        return $map;
    }

    /** On ne garde du .tmj que ce dont le serveur a besoin */
    private static function parse(array $map): array
    {
        $tile = $map['tilewidth'] ?? 32;

        // Propriétés des tuiles : [gid => ['encounter' => true, 'collides' => true]]
        $tileProps = [];
        foreach ($map['tilesets'] ?? [] as $tileset) {
            foreach ($tileset['tiles'] ?? [] as $t) {
                foreach ($t['properties'] ?? [] as $p) {
                    $tileProps[$tileset['firstgid'] + $t['id']][$p['name']] = $p['value'];
                }
            }
        }

        $layers = [];
        $zones = [];
        $actions = [];
        $shops = [];
        $gifts = [];
        $safariGate = null;
        $itemBalls = [];
        $berryBushes = [];
        $field = [];
        $warps = [];
        $casino = [];   // étape 20 : réglages des machines, roulettes et comptoirs ([action => [valeurs]])
        $spawn = null;
        $eventObjects = [];   // étape 24
        $trainers = [];       // étape 26
        foreach ($map['layers'] ?? [] as $layer) {
            // Étape 11 : obstacles des capacités de terrain (calque d'objets "field", propriété "field")
            if ($layer['type'] === 'objectgroup' && $layer['name'] === 'field') {
                foreach ($layer['objects'] as $o) {
                    $props = collect($o['properties'] ?? [])->pluck('value', 'name');
                    $top = isset($o['gid']) ? $o['y'] - $o['height'] : $o['y'];   // objet tuile : y en bas de l'image
                    $field[] = [
                        'kind' => (string) ($props['field'] ?? ''),
                        'x' => (int) floor($o['x'] / $tile),
                        'y' => (int) floor($top / $tile),
                    ];
                }
            }
            // Étape 16 : les Poké Balls posées par terre (calque d'objets "items")
            if ($layer['type'] === 'objectgroup' && $layer['name'] === 'items') {
                foreach ($layer['objects'] as $o) {
                    $props = collect($o['properties'] ?? [])->pluck('value', 'name');
                    $top = isset($o['gid']) ? $o['y'] - $o['height'] : $o['y'];
                    $x = (int) floor($o['x'] / $tile);
                    $y = (int) floor($top / $tile);
                    $key = (string) ($props['id'] ?? "{$x}_{$y}");
                    $itemBalls[$key] = [
                        'key' => $key, 'x' => $x, 'y' => $y,
                        'item' => $props['item'] ?? null,
                        'quantity' => max(1, (int) ($props['quantity'] ?? 1)),
                        'money' => (int) ($props['money'] ?? 0),
                        'coins' => (int) ($props['coins'] ?? 0),   // étape 20 : des jetons
                        'pokemon' => $props['pokemon'] ?? null,
                        'level' => max(1, min(100, (int) ($props['level'] ?? 5))),
                        'shiny' => (bool) ($props['shiny'] ?? false),
                        'hidden' => (bool) ($props['hidden'] ?? false),
                    ];
                }
            }
            // Étape 22 : les buissons à Baies (calque d'objets "berries", propriété berry = slug de la Baie)
            if ($layer['type'] === 'objectgroup' && $layer['name'] === 'berries') {
                foreach ($layer['objects'] as $o) {
                    $props = collect($o['properties'] ?? [])->pluck('value', 'name');
                    if (! is_string($props['berry'] ?? null)) {
                        continue;
                    }
                    $top = isset($o['gid']) ? $o['y'] - $o['height'] : $o['y'];
                    $x = (int) floor($o['x'] / $tile);
                    $y = (int) floor($top / $tile);
                    $key = (string) ($props['id'] ?? "{$x}_{$y}");
                    $berryBushes[$key] = [
                        'key' => $key, 'x' => $x, 'y' => $y,
                        'berry' => (string) $props['berry'],
                        'quantity' => isset($props['quantity']) ? max(1, (int) $props['quantity']) : null,   // sinon : config
                        'hours' => isset($props['hours']) ? max(0.01, (float) $props['hours']) : null,        // sinon : config
                    ];
                }
            }
            // Étape 19 : les sorties (calque "warps"), pour vérifier l'entrée d'une grotte (Corde Sortie)
            if ($layer['type'] === 'objectgroup' && $layer['name'] === 'warps') {
                foreach ($layer['objects'] as $o) {
                    $props = collect($o['properties'] ?? [])->pluck('value', 'name');
                    $top = isset($o['gid']) ? $o['y'] - $o['height'] : $o['y'];
                    $warps[] = [
                        'x' => (int) floor($o['x'] / $tile),
                        'y' => (int) floor($top / $tile),
                        'w' => max(1, (int) round(($o['width'] ?? 0) / $tile)),
                        'h' => max(1, (int) round(($o['height'] ?? 0) / $tile)),
                        'toMap' => (string) ($props['to_map'] ?? ''),
                    ];
                }
            }
            if ($layer['type'] === 'objectgroup' && $layer['name'] === 'spawns' && $spawn === null && ! empty($layer['objects'])) {
                $o = $layer['objects'][0];
                $spawn = ['x' => (int) floor($o['x'] / $tile), 'y' => (int) floor($o['y'] / $tile)];
            }
            if ($layer['type'] === 'tilelayer' && in_array($layer['name'], ['ground', 'decor'], true) && is_array($layer['data'] ?? null)) {
                // Les 3 bits de poids fort d'un gid indiquent un retournement de la tuile : on les enlève
                $layers[$layer['name']] = array_map(fn ($gid) => $gid & 0x1FFFFFFF, $layer['data']);
            }
            if ($layer['type'] === 'objectgroup') {
                foreach ($layer['objects'] as $o) {
                    $props = collect($o['properties'] ?? [])->pluck('value', 'name');
                    // Étape 26 : un dresseur (pour le Cherche VS : qui est à l'écran ?)
                    if ($layer['name'] === 'npcs' && is_string($props['trainer'] ?? null)) {
                        $top = isset($o['gid']) ? $o['y'] - $o['height'] : $o['y'];
                        $trainers[$props['trainer']] = ['x' => (int) floor($o['x'] / $tile), 'y' => (int) floor($top / $tile)];
                    }
                    // Étape 24 : un objet lié au scénario (un événement, ou une condition pour être visible)
                    if (isset($o['id']) && (isset($props['event']) || isset($props['show_if']) || isset($props['hide_if']))) {
                        $top = isset($o['gid']) ? $o['y'] - $o['height'] : $o['y'];
                        $talk = in_array($layer['name'], ['npcs', 'signs'], true);
                        $eventObjects[(int) $o['id']] = [
                            'id' => (int) $o['id'],
                            'name' => (string) ($o['name'] ?? ''),
                            'layer' => $layer['name'],
                            'x' => (int) floor($o['x'] / $tile),
                            'y' => (int) floor($top / $tile),
                            'w' => max(1, (int) round(($o['width'] ?? 0) / $tile)),
                            'h' => max(1, (int) round(($o['height'] ?? 0) / $tile)),
                            'event' => isset($props['event']) ? strtolower(trim((string) $props['event'])) : null,
                            'trigger' => $talk ? 'talk' : (in_array($props['trigger'] ?? 'step', ['step', 'action', 'auto'], true) ? ($props['trigger'] ?? 'step') : 'step'),
                            'speaker' => $layer['name'] === 'npcs' ? (string) ($props['name'] ?? '') : '',
                            'show_if' => isset($props['show_if']) ? (string) $props['show_if'] : null,
                            'hide_if' => isset($props['hide_if']) ? (string) $props['hide_if'] : null,
                        ];
                    }
                    if (is_string($props['action'] ?? null)) {
                        $actions[] = $props['action'];
                        if ($props['action'] === 'shop') {
                            $shops[] = (string) ($props['shop'] ?? 'default');
                        }
                        // Étape 14 : un PNJ qui offre un objet (item = slug, quantity, gift = identifiant facultatif)
                        // Étape 15 : le gardien du Parc Safari (to_map, to_x, to_y : l'arrivée dans le Parc)
                        if ($props['action'] === 'safari' && is_string($props['to_map'] ?? null)) {
                            // Plusieurs zones possibles : to_map = "safari_kanto,safari_jotho" (le joueur choisit)
                            $zones = array_values(array_filter(array_map('trim', explode(',', $props['to_map']))));
                            $safariGate = [
                                'map' => $zones[0] ?? '',
                                'maps' => $zones,
                                'x' => (int) ($props['to_x'] ?? 0),
                                'y' => (int) ($props['to_y'] ?? 0),
                                'facing' => (string) ($props['to_facing'] ?? 'up'),
                            ];
                        }
                        // Étape 20 : le Casino (machine à sous : difficulty ; roulette : wager ; lots : prizes)
                        $setting = match ($props['action']) {
                            'slots' => (string) (int) ($props['difficulty'] ?? 1),
                            'roulette' => (string) (int) ($props['wager'] ?? 1),
                            'prizes' => (string) ($props['prizes'] ?? 'pokemon'),
                            default => null,
                        };
                        if ($setting !== null) {
                            $casino[$props['action']][] = $setting;
                        }
                        if ($props['action'] === 'gift' && is_string($props['item'] ?? null)) {
                            $id = (string) ($props['gift'] ?? $props['item']);
                            $gifts[$id] = ['item' => $props['item'], 'quantity' => max(1, (int) ($props['quantity'] ?? 1))];
                        }
                    }
                }
            }
            if ($layer['type'] === 'objectgroup' && $layer['name'] === 'encounters') {
                foreach ($layer['objects'] as $o) {
                    $props = collect($o['properties'] ?? [])->pluck('value', 'name');
                    $zones[] = [
                        'name' => (string) ($props['zone'] ?? $props['encounter_zone'] ?? 'default'),
                        'x' => (int) floor($o['x'] / $tile),
                        'y' => (int) floor($o['y'] / $tile),
                        'w' => max(1, (int) round($o['width'] / $tile)),
                        'h' => max(1, (int) round($o['height'] / $tile)),
                    ];
                }
            }
        }

        return [
            'width' => (int) $map['width'],
            'height' => (int) $map['height'],
            'properties' => collect($map['properties'] ?? [])->pluck('value', 'name')->all(),
            'tileProps' => $tileProps,
            'layers' => $layers,
            'zones' => $zones,
            'actions' => array_values(array_unique($actions)),
            'shops' => array_values(array_unique($shops)),
            'gifts' => $gifts,
            'safariGate' => $safariGate,
            'itemBalls' => $itemBalls,
            'berryBushes' => $berryBushes,
            'warps' => $warps,
            'casino' => array_map(fn ($v) => array_values(array_unique($v)), $casino),
            'field' => $field,
            'spawn' => $spawn,
            'eventObjects' => $eventObjects,
            'trainers' => $trainers,
        ];
    }
}
