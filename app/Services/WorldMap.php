<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Étape 25 : les CARTES COLLÉES, grâce aux « mondes » de Tiled (fichiers public/game/maps/*.world).
 *
 * Dans Tiled : Monde > Nouveau monde..., puis on y ajoute ses cartes et on les place les unes à côté
 * des autres (Tiled les affiche alors ensemble). Le fichier .world est un simple JSON :
 *
 *   { "maps": [ { "fileName": "cremieu.tmj", "x": 0, "y": 0, "width": 960, "height": 640 },
 *               { "fileName": "route_demo.tmj", "x": -960, "y": 0, ... } ], "type": "world" }
 *
 * x et y sont en PIXELS. Le jeu les convertit en cases (32 pixels) : place les cartes sur la grille
 * (Tiled : Affichage > Aligner sur la grille, ou maintiens Ctrl en déplaçant une carte).
 *
 * Deux cartes d'un même monde qui se touchent sont "collées" : on passe de l'une à l'autre en marchant,
 * sans fondu, et on voit la voisine autour de soi.
 */
class WorldMap
{
    /** Distance (en cases) jusqu'à laquelle une carte voisine est dessinée (l'écran fait 16 x 12 cases) */
    public const MARGIN = 10;

    /**
     * Toutes les cartes placées dans un monde : [clé => ['world' => 'demo', 'x', 'y', 'w', 'h']] (en cases).
     * Une carte placée dans deux mondes : le premier (ordre alphabétique) l'emporte.
     */
    public function maps(): array
    {
        $files = File::glob(public_path('game/maps') . '/*.world') ?: [];
        sort($files);
        $stamp = collect($files)->map(fn ($f) => File::lastModified($f) . '-' . File::size($f))->join(',');

        return Cache::remember('world-maps:v1:' . md5(implode(',', $files) . $stamp), now()->addDay(), function () use ($files) {
            $maps = [];
            foreach ($files as $file) {
                $world = basename($file, '.world');
                $data = json_decode(File::get($file), true);
                foreach ($data['maps'] ?? [] as $m) {
                    $key = pathinfo((string) ($m['fileName'] ?? ''), PATHINFO_FILENAME);
                    if ($key === '' || isset($maps[$key]) || ! preg_match('/^[a-z0-9_]+$/', $key)) {
                        continue;
                    }
                    $size = MapInfo::load($key);
                    $maps[$key] = [
                        'world' => $world,
                        'x' => intdiv((int) round($m['x'] ?? 0), 32),
                        'y' => intdiv((int) round($m['y'] ?? 0), 32),
                        // La taille vient de la carte elle-même (celle du .world peut être en retard)
                        'w' => $size?->width() ?? intdiv((int) ($m['width'] ?? 0), 32),
                        'h' => $size?->height() ?? intdiv((int) ($m['height'] ?? 0), 32),
                    ];
                }
            }

            return $maps;
        });
    }

    /** Les cartes du même monde assez proches pour être dessinées autour de celle-ci : [clé => ['dx', 'dy', ...]] */
    public function neighbors(string $mapKey): array
    {
        $maps = $this->maps();
        $me = $maps[$mapKey] ?? null;
        if (! $me) {
            return [];
        }
        $result = [];
        foreach ($maps as $key => $m) {
            if ($key === $mapKey || $m['world'] !== $me['world']) {
                continue;
            }
            $near = $m['x'] < $me['x'] + $me['w'] + self::MARGIN && $m['x'] + $m['w'] > $me['x'] - self::MARGIN
                && $m['y'] < $me['y'] + $me['h'] + self::MARGIN && $m['y'] + $m['h'] > $me['y'] - self::MARGIN;
            if ($near) {
                $result[$key] = $m + ['dx' => $m['x'] - $me['x'], 'dy' => $m['y'] - $me['y'], 'meW' => $me['w'], 'meH' => $me['h']];
            }
        }

        return $result;
    }

    /** La carte du monde qui couvre la case (x, y) de la carte $mapKey (coordonnées de $mapKey), ou null */
    public function mapAt(string $mapKey, int $x, int $y): ?array
    {
        foreach ($this->neighbors($mapKey) as $key => $n) {
            if ($x >= $n['dx'] && $x < $n['dx'] + $n['w'] && $y >= $n['dy'] && $y < $n['dy'] + $n['h']) {
                return ['map' => $key, 'x' => $x - $n['dx'], 'y' => $y - $n['dy']];
            }
        }

        return null;
    }

    /**
     * Problèmes du monde (commande game:monde) : cartes introuvables, qui se chevauchent,
     * ou mal alignées sur la grille. Renvoie [['error'|'warning', message], ...]
     */
    public function problems(): array
    {
        $problems = [];
        foreach (File::glob(public_path('game/maps') . '/*.world') ?: [] as $file) {
            $name = basename($file);
            $data = json_decode(File::get($file), true);
            if (! is_array($data)) {
                $problems[] = ['error', "{$name} : ce n'est pas un fichier JSON valide."];

                continue;
            }
            if (! empty($data['patterns'])) {
                $problems[] = ['warning', "{$name} : les « patterns » de Tiled ne sont pas gérés, seules les cartes de la liste « maps » comptent."];
            }
            foreach ($data['maps'] ?? [] as $m) {
                $key = pathinfo((string) ($m['fileName'] ?? ''), PATHINFO_FILENAME);
                if (! MapInfo::load($key)) {
                    $problems[] = ['error', "{$name} : la carte \"{$m['fileName']}\" est introuvable dans public/game/maps."];
                }
                if (((int) round($m['x'] ?? 0)) % 32 !== 0 || ((int) round($m['y'] ?? 0)) % 32 !== 0) {
                    $problems[] = ['error', "{$name} : {$key} n'est pas alignée sur la grille (x = {$m['x']}, y = {$m['y']} : il faut des multiples de 32)."];
                }
            }
        }
        $maps = $this->maps();
        $keys = array_keys($maps);
        foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i + 1) as $b) {
                $ma = $maps[$a];
                $mb = $maps[$b];
                if ($ma['world'] === $mb['world'] && $ma['x'] < $mb['x'] + $mb['w'] && $mb['x'] < $ma['x'] + $ma['w'] && $ma['y'] < $mb['y'] + $mb['h'] && $mb['y'] < $ma['y'] + $ma['h']) {
                    $problems[] = ['error', "Les cartes {$a} et {$b} se chevauchent (monde {$ma['world']})."];
                }
            }
        }

        return $problems;
    }
}
