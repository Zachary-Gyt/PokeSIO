<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Les fichiers audio présents dans public/game/audio (bgm, me, se), et la correspondance
 * "moment du jeu -> fichier" de config/audio.php. Envoyé au jeu au chargement (GameController).
 */
class AudioLibrary
{
    public const FOLDERS = ['bgm', 'me', 'se'];

    public const EXTENSIONS = ['ogg', 'mp3', 'wav', 'm4a'];

    /**
     * Pour le jeu :
     *   files  : ['bgm' => ['route 1' => 'http://.../Route 1.ogg', ...], 'me' => [...], 'se' => [...]]
     *   events : ['bgm' => ['battle_wild' => 'http://...'], 'me' => [...], 'se' => [...]]
     */
    public function forClient(): array
    {
        $files = $this->files();
        $events = [];
        foreach (self::FOLDERS as $folder) {
            // On cherche d'abord dans le bon dossier, puis dans les autres : dans Essentials, les jingles
            // de victoire sont rangés dans BGM, "GUI save game" dans ME, "Pkmn level up" dans SE...
            $searchOrder = array_merge([$folder], array_diff(self::FOLDERS, [$folder]));
            foreach (config("audio.{$folder}", []) as $event => $names) {
                foreach ((array) $names as $name) {
                    $found = collect($searchOrder)->map(fn ($f) => $files[$f][mb_strtolower($name)] ?? null)->filter()->first();
                    if ($found) {
                        $events[$folder][$event] = $found;
                        break;
                    }
                }
            }
        }

        return [
            'files' => array_map(fn ($list) => (object) $list, $files),
            'events' => array_map(fn ($list) => (object) $list, $events + array_fill_keys(self::FOLDERS, [])),
            'musicVolume' => (float) config('audio.music_volume', 0.6),
            'effectsVolume' => (float) config('audio.effects_volume', 0.8),
            'victorySeconds' => (float) config('audio.victory_seconds', 4),
        ];
    }

    /** Les fichiers de chaque dossier, par nom en minuscules sans extension (en cache tant que les dossiers ne changent pas) */
    public function files(): array
    {
        $root = public_path('game/audio');
        $stamp = collect(self::FOLDERS)->map(fn ($f) => File::isDirectory("{$root}/{$f}") ? File::lastModified("{$root}/{$f}") : 0)->join('-');

        return Cache::remember("audio-files:{$stamp}", now()->addDay(), function () use ($root) {
            $result = [];
            foreach (self::FOLDERS as $folder) {
                $result[$folder] = [];
                if (! File::isDirectory("{$root}/{$folder}")) {
                    continue;
                }
                foreach (File::files("{$root}/{$folder}") as $file) {
                    if (! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                        continue;
                    }
                    $name = mb_strtolower(pathinfo($file->getFilename(), PATHINFO_FILENAME));
                    // rawurlencode : les noms d'Essentials contiennent des espaces ("Battle wild.ogg")
                    $result[$folder][$name] = asset("game/audio/{$folder}/" . rawurlencode($file->getFilename()));
                }
            }

            return $result;
        });
    }
}
