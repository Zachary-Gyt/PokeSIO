<?php

namespace App\Console\Commands;

use App\Services\AudioLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copie la musique et les sons d'Essentials (dossier Audio) dans public/game/audio :
 *   Audio\BGM -> bgm (musiques)   Audio\ME -> me (jingles)   Audio\SE -> se (effets)
 *
 *   php artisan game:essentials-audio "C:\...\Pokemon Essentials v21.1 2023-07-30"
 *
 * Seuls les formats lus par les navigateurs sont copiés (.ogg, .mp3, .wav, .m4a).
 * Les .mid (MIDI) ne sont PAS lus par les navigateurs : la commande les signale.
 * À la fin, elle dit quels moments du jeu (config/audio.php) ont trouvé leur fichier.
 */
class ImportEssentialsAudio extends Command
{
    protected $signature = 'game:essentials-audio {essentials : Dossier de Pokémon Essentials (celui qui contient Audio)}';

    protected $description = 'Copie la musique et les sons de Pokémon Essentials dans public/game/audio';

    public function handle(AudioLibrary $library): int
    {
        $root = rtrim($this->argument('essentials'), '\\/');
        $audio = File::isDirectory("{$root}/Audio") ? "{$root}/Audio" : $root;
        if (! File::isDirectory("{$audio}/BGM") && ! File::isDirectory("{$audio}/SE")) {
            $this->error("Introuvable : {$audio}\\BGM. Indique le dossier d'Essentials (celui qui contient Audio).");

            return self::FAILURE;
        }

        $copied = 0;
        $midi = [];
        foreach (['BGM' => 'bgm', 'ME' => 'me', 'SE' => 'se'] as $from => $to) {
            if (! File::isDirectory("{$audio}/{$from}")) {
                $this->warn("Dossier absent : Audio\\{$from}");
                continue;
            }
            File::ensureDirectoryExists(public_path("game/audio/{$to}"));
            foreach (File::files("{$audio}/{$from}") as $file) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['mid', 'midi'], true)) {
                    $midi[] = "{$from}\\{$file->getFilename()}";
                    continue;
                }
                if (! in_array($ext, AudioLibrary::EXTENSIONS, true)) {
                    continue;
                }
                File::copy($file->getPathname(), public_path("game/audio/{$to}/{$file->getFilename()}"));
                $copied++;
            }
        }
        $this->info("{$copied} fichiers copiés dans public/game/audio.");
        if ($midi) {
            $this->warn(count($midi) . ' fichier(s) MIDI ignoré(s) (les navigateurs ne les lisent pas). Convertis-les en .ogg pour les utiliser :');
            foreach (array_slice($midi, 0, 15) as $name) {
                $this->line("  {$name}");
            }
        }

        // Ce qui a été trouvé pour chaque moment du jeu
        $events = $library->forClient()['events'];
        $rows = [];
        foreach (AudioLibrary::FOLDERS as $folder) {
            foreach (array_keys(config("audio.{$folder}", [])) as $event) {
                $url = $events[$folder]->{$event} ?? null;
                $rows[] = [$folder, $event, $url ? rawurldecode(basename($url)) : '— (silencieux)'];
            }
        }
        $this->table(['Dossier', 'Moment', 'Fichier'], $rows);

        return self::SUCCESS;
    }
}
