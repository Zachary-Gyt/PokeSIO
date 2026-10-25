<?php

namespace App\Console\Commands;

use App\Services\WorldMap;
use Illuminate\Console\Command;

/**
 * Étape 25 : vérifie les mondes Tiled (public/game/maps/*.world) et montre quelles cartes sont collées.
 *
 *   php artisan game:monde
 */
class CheckWorld extends Command
{
    protected $signature = 'game:monde';

    protected $description = 'Vérifie les mondes Tiled (cartes collées) et liste les voisines de chaque carte';

    public function handle(WorldMap $world): int
    {
        $maps = $world->maps();
        if (! $maps) {
            $this->warn('Aucun monde : crée un fichier .world dans public/game/maps avec Tiled (Monde > Nouveau monde).');

            return self::SUCCESS;
        }

        foreach (collect($maps)->groupBy('world', true) as $name => $list) {
            $this->info("Monde \"{$name}\" :");
            foreach ($list as $key => $m) {
                $touching = collect($world->neighbors($key))
                    ->filter(fn ($n) => self::touches($n))
                    ->map(fn ($n, $k) => $k . ' (' . self::side($n) . ')')
                    ->values()->join(', ');
                $this->line(sprintf('  %-24s case (%d, %d), %d x %d   collée à : %s', $key, $m['x'], $m['y'], $m['w'], $m['h'], $touching ?: 'aucune'));
            }
        }

        $errors = 0;
        foreach ($world->problems() as [$level, $message]) {
            if ($level === 'error') {
                $errors++;
                $this->line("  <fg=red>✗</> {$message}");
            } else {
                $this->line("  <fg=yellow>!</> {$message}");
            }
        }
        if ($errors) {
            $this->error("{$errors} erreur(s).");

            return self::FAILURE;
        }
        $this->info('Tout est bon !');

        return self::SUCCESS;
    }

    /** Les deux cartes ont-elles un bord commun ? (sinon, la voisine est seulement dessinée au loin) */
    private static function touches(array $n): bool
    {
        $overlapX = $n['dx'] < $n['meW'] && $n['dx'] + $n['w'] > 0;
        $overlapY = $n['dy'] < $n['meH'] && $n['dy'] + $n['h'] > 0;

        return ($overlapX && ($n['dy'] + $n['h'] === 0 || $n['dy'] === $n['meH'])) || ($overlapY && ($n['dx'] + $n['w'] === 0 || $n['dx'] === $n['meW']));
    }

    private static function side(array $n): string
    {
        return match (true) {
            $n['dy'] + $n['h'] === 0 => 'au nord',
            $n['dy'] === $n['meH'] => 'au sud',
            $n['dx'] + $n['w'] === 0 => 'à l\'ouest',
            default => 'à l\'est',
        };
    }
}
