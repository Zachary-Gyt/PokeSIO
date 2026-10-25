<?php

namespace App\Console\Commands;

use App\Support\RubyMarshal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Les COLLISIONS et les TERRAINS de chaque tileset, lus dans Pokémon Essentials (Data/Tilesets.rxdata),
 * écrits dans public/game/tilesets/<tileset>.tsj (un "tileset Tiled" en JSON, à côté de son image .png).
 *
 *   php artisan game:tilesets "C:\...\Pokemon Essentials v21.1 2023-07-30"
 *
 * Le jeu lit ces fichiers pour TOUTES les cartes : plus besoin de cocher "collides" carte par carte.
 * (Une propriété posée sur une tuile DANS une carte reste prioritaire, pour cette carte seulement.)
 *
 * Ce qui est traduit, tuile par tuile (comme dans RPG Maker XP) :
 *   - passage bloqué dans les 4 directions       -> collides
 *   - drapeau "comptoir" (0x80)                  -> counter (on parle par-dessus)
 *   - terrain Herbe / Hautes herbes (2, 10)       -> encounter (Pokémon sauvages)
 *   - terrain Eau (5, 6, 7, 8, 9)                 -> surf + collides (Surf pour y aller)
 *   - terrain Rebord (1)                          -> ledge = le sens du saut (down, up, left, right)
 *   - priorité > 0 (dessinée devant le joueur)    -> over (ne compte pas pour les collisions)
 *
 * Puis les RÉGLAGES PROPRES À TES TILESETS (constantes OVERRIDES et TERRAINS ci-dessous) :
 *   - outside n° 39 : tes hautes herbes sans fond (à poser sur n'importe quel sol) -> encounter
 *   - terrain = sable / gravier / roche / grotte sur les sols : sous des hautes herbes, la table de rencontres
 *     de ce terrain est utilisée (wild_encounters.zone = le terrain), s'il y en a une pour la carte
 *
 * Quelle image correspond à quel tileset d'Essentials ? Celle qui a EXACTEMENT le même contenu
 * (ex. ton outside.png = Graphics/Tilesets/Outside.png), sinon le même nom.
 */
class ImportTilesetData extends Command
{
    protected $signature = 'game:tilesets
        {essentials : dossier de Pokémon Essentials (celui qui contient Data et Graphics)}
        {--only= : ne traiter que ce tileset (nom du .png, sans extension)}';

    protected $description = 'Crée les collisions et terrains des tilesets (public/game/tilesets/*.tsj) depuis Pokémon Essentials';

    /** Terrains d'Essentials v21 (GameData::TerrainTag) */
    private const LEDGE = 1;

    private const GRASS = [2, 10];          // Grass, TallGrass

    private const WATER = [5, 6, 7, 8, 9];  // DeepWater, StillWater, Water, Waterfall, WaterfallCrest

    /** Propriétés imposées, quoi que dise Essentials : [nom de l'image => [numéro de tuile => propriétés]] */
    private const OVERRIDES = [
        'outside' => [39 => ['encounter' => true, 'over' => true]],   // hautes herbes sans fond (ton tileset)
    ];

    /** Le terrain des sols : [nom de l'image => [[premier numéro, dernier numéro, terrain], ...]] */
    private const TERRAINS = [
        'outside' => [[152, 207, 'sable'], [208, 239, 'gravier'], [784, 807, 'roche']],
        'Caves' => [[0, 1591, 'grotte']],
    ];

    public function handle(): int
    {
        $root = rtrim($this->argument('essentials'), '\\/');
        $file = "{$root}/Data/Tilesets.rxdata";
        if (! File::exists($file)) {
            $this->error("Introuvable : {$file}");
            $this->line('Indique le dossier de Pokémon Essentials, celui qui contient le dossier Data.');

            return self::FAILURE;
        }

        try {
            $tilesets = RubyMarshal::load(File::get($file));
        } catch (\Throwable $e) {
            $this->error('Lecture impossible de Tilesets.rxdata : ' . $e->getMessage());

            return self::FAILURE;
        }

        // Les tilesets d'Essentials : nom de l'image => données (le premier qui l'utilise)
        $byImage = [];
        foreach ($tilesets as $t) {
            if (! is_array($t) || empty($t['@tileset_name'])) {
                continue;
            }
            $byImage[$t['@tileset_name']] ??= $t;
        }

        // Empreinte des images d'Essentials, pour reconnaître les tiennes même renommées (outside.png = Outside.png)
        $graphics = "{$root}/Graphics/Tilesets";
        $hashes = [];
        foreach (array_keys($byImage) as $name) {
            $png = "{$graphics}/{$name}.png";
            if (File::exists($png)) {
                $hashes[md5_file($png)] = $name;
            }
        }

        $written = 0;
        foreach (File::glob(public_path('game/tilesets/*.png')) as $png) {
            $mine = pathinfo($png, PATHINFO_FILENAME);
            if ($this->option('only') && $this->option('only') !== $mine) {
                continue;
            }
            $source = $hashes[md5_file($png)] ?? $this->byName($mine, array_keys($byImage));
            if (! $source) {
                $this->warn("{$mine}.png : aucun tileset d'Essentials ne correspond (ignoré).");
                continue;
            }
            $count = $this->write($png, $mine, $byImage[$source]);
            $this->info("{$mine}.tsj  (depuis « {$source} » d'Essentials : {$count} tuiles avec des propriétés)");
            $written++;
        }

        $this->newLine();
        $this->info("{$written} tileset(s) écrit(s) dans public/game/tilesets.");
        $this->line('Recharge le jeu (Ctrl+F5) : toutes les cartes utilisent ces collisions.');

        return self::SUCCESS;
    }

    private function byName(string $mine, array $names): ?string
    {
        $aliases = ['interior' => 'Interior general', 'game_corner' => 'Game Corner interior'];
        foreach ($names as $name) {
            if (strcasecmp($name, $mine) === 0 || strcasecmp($name, $aliases[$mine] ?? '') === 0) {
                return $name;
            }
        }

        return null;
    }

    /** Écrit le tileset Tiled (.tsj) ; renvoie le nombre de tuiles qui ont des propriétés */
    private function write(string $png, string $name, array $tileset): int
    {
        [$width, $height] = getimagesize($png);
        $columns = intdiv($width, 32);
        $count = $columns * intdiv($height, 32);
        $passages = RubyMarshal::table($tileset['@passages'] ?? null);
        $priorities = RubyMarshal::table($tileset['@priorities'] ?? null);
        $terrains = RubyMarshal::table($tileset['@terrain_tags'] ?? null);
        $overrides = self::OVERRIDES[$name] ?? [];
        $terrainRanges = self::TERRAINS[$name] ?? [];

        $tiles = [];
        for ($i = 0; $i < $count; $i++) {
            $id = 384 + $i;   // dans RPG Maker, les 384 premiers numéros sont les autotiles
            $p = $passages[$id] ?? 0;
            $terrain = $terrains[$id] ?? 0;
            $props = [];

            if ($terrain === self::LEDGE) {
                $props['ledge'] = $this->ledgeDirection($p);
            } elseif (in_array($terrain, self::WATER, true)) {
                $props['surf'] = true;
                $props['collides'] = true;
            } elseif (($p & 0x0F) === 0x0F) {
                $props['collides'] = true;
            }
            if (in_array($terrain, self::GRASS, true)) {
                $props['encounter'] = true;
            }
            if ($p & 0x80) {
                $props['counter'] = true;
            }
            if (($priorities[$id] ?? 0) > 0 && empty($props['collides'])) {
                $props['over'] = true;
            }
            foreach ($terrainRanges as [$from, $to, $kind]) {
                if ($i >= $from && $i <= $to && empty($props['collides'])) {
                    $props['terrain'] = $kind;
                }
            }
            if (isset($overrides[$i])) {
                $props = $overrides[$i];   // remplace tout ce qu'Essentials disait de cette tuile
            }

            if ($props) {
                $tiles[] = [
                    'id' => $i,
                    'properties' => collect($props)->map(fn ($v, $k) => [
                        'name' => $k, 'type' => is_bool($v) ? 'bool' : 'string', 'value' => $v,
                    ])->values()->all(),
                ];
            }
        }

        $tsj = [
            'type' => 'tileset', 'version' => '1.10', 'tiledversion' => '1.11.0',
            'name' => $name, 'image' => basename($png), 'imagewidth' => $width, 'imageheight' => $height,
            'tilewidth' => 32, 'tileheight' => 32, 'tilecount' => $count, 'columns' => $columns,
            'margin' => 0, 'spacing' => 0, 'tiles' => $tiles,
        ];
        File::put(dirname($png) . "/{$name}.tsj", json_encode($tsj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return count($tiles);
    }

    /**
     * Le sens d'un rebord : celui par lequel on peut ENTRER sur la tuile.
     * Bits de passage RPG Maker : 0x01 bas, 0x02 gauche, 0x04 droite, 0x08 haut (sens bloqués en sortant de la tuile).
     * Entrer en allant vers le bas vérifie le bit "haut" de la tuile, etc.
     */
    private function ledgeDirection(int $p): string
    {
        return match (true) {
            ! ($p & 0x08) => 'down',
            ! ($p & 0x01) => 'up',
            ! ($p & 0x02) => 'right',
            ! ($p & 0x04) => 'left',
            default => 'down',
        };
    }
}
