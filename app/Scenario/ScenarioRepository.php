<?php

namespace App\Scenario;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Étape 24 : les fichiers de scénario, rangés dans resources/scenario :
 *
 *   resources/scenario/cremieu.txt   les événements de la carte "cremieu"
 *   resources/scenario/commun.txt    des événements utilisables sur TOUTES les cartes
 *
 * Un événement est d'abord cherché dans le fichier de la carte, puis dans commun.txt.
 * Chaque fichier est compilé une seule fois, puis gardé en cache tant que son contenu ne change pas.
 */
class ScenarioRepository
{
    public const COMMON = 'commun';

    /** Le dossier des scénarios (réglable avec config('game.scenario_path') : les tests en utilisent un autre) */
    public static function directory(): string
    {
        return config('game.scenario_path') ?: resource_path('scenario');
    }

    /**
     * L'événement à jouer sur cette carte, ou null s'il n'existe pas (ou s'il contient des erreurs).
     *
     * @return array{name: string, file: string, line: int, code: array}|null
     */
    public function find(string $mapKey, string $event): ?array
    {
        foreach ([$mapKey, self::COMMON] as $file) {
            $parsed = $this->file($file);
            if ($parsed && isset($parsed['events'][$event])) {
                return ['name' => $event, 'file' => $file] + $parsed['events'][$event];
            }
        }

        return null;
    }

    /**
     * Un fichier lu et compilé : ['events' => [...], 'errors' => [...]], ou null s'il n'existe pas.
     * Un événement qui contient une erreur est retiré de la liste (il ne sera pas joué).
     */
    public function file(string $name): ?array
    {
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            return null;
        }
        $path = self::directory() . "/{$name}.txt";
        if (! File::exists($path)) {
            return null;
        }

        return Cache::remember("scenario:v1:{$name}:" . md5_file($path), now()->addDay(), function () use ($path) {
            $parsed = (new ScriptParser)->parse(File::get($path));
            $broken = self::brokenEvents($parsed);
            foreach ($broken as $event) {
                unset($parsed['events'][$event]);
            }

            return $parsed;
        });
    }

    /** Tous les fichiers de scénario : [nom => chemin] */
    public function files(): array
    {
        if (! File::isDirectory(self::directory())) {
            return [];
        }

        return collect(File::files(self::directory()))
            ->filter(fn ($f) => strtolower($f->getExtension()) === 'txt')
            ->mapWithKeys(fn ($f) => [$f->getFilenameWithoutExtension() => $f->getPathname()])
            ->sortKeys()
            ->all();
    }

    /** Les événements dont une ligne contient une erreur (d'après les numéros de ligne) */
    private static function brokenEvents(array $parsed): array
    {
        $starts = collect($parsed['events'])->map(fn ($e) => $e['line'])->sort();
        $broken = [];
        foreach ($parsed['errors'] as $error) {
            if (! preg_match('/^ligne (\d+)/', $error, $m)) {
                continue;
            }
            // L'erreur appartient au dernier événement qui commence avant elle
            $owner = $starts->filter(fn ($line) => $line <= (int) $m[1])->keys()->last();
            if ($owner !== null) {
                $broken[] = $owner;
            }
        }

        return array_values(array_unique($broken));
    }
}
