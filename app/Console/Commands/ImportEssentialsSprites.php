<?php

namespace App\Console\Commands;

use App\Models\PokemonSpecies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copie les sprites de Pokémon Essentials dans public/game, UNIQUEMENT pour les espèces
 * présentes dans ta base (les 151 de Kanto), et met à jour les colonnes sprite_* .
 *
 *   php artisan pokemon:essentials-sprites "C:\...\Pokemon Essentials v21.1" --overworld="C:\...\pokemon_overworld"
 *
 * Le premier chemin peut être le dossier d'Essentials, son dossier Graphics ou Graphics\Pokemon.
 * Les cris (Audio\SE\Cries) sont copiés aussi s'ils sont trouvés (ou avec --cries="...").
 */
class ImportEssentialsSprites extends Command
{
    protected $signature = 'pokemon:essentials-sprites
        {essentials : dossier de Pokémon Essentials (ou son dossier Graphics, ou Graphics\Pokemon)}
        {--overworld= : dossier contenant "Followers" et "Followers shiny"}
        {--cries= : dossier des cris (Audio\SE\Cries)}';

    protected $description = 'Copie les sprites Essentials des espèces de la base dans public/game et met à jour la base';

    /** Dossier Essentials => [dossier de destination, colonne en base] */
    private const FOLDERS = [
        'Front' => ['front', 'sprite_front'],
        'Back' => ['back', 'sprite_back'],
        'Front shiny' => ['front_shiny', 'sprite_front_shiny'],
        'Back shiny' => ['back_shiny', 'sprite_back_shiny'],
        'Icons' => ['icons', 'sprite_icon'],
        'Footprints' => ['footprints', 'sprite_footprint'],
    ];

    private const OVERWORLD_FOLDERS = [
        'Followers' => ['overworld', 'sprite_overworld'],
        'Followers shiny' => ['overworld_shiny', 'sprite_overworld_shiny'],
    ];

    public function handle(): int
    {
        $pokemonDir = $this->findPokemonDir($this->argument('essentials'));
        if (! $pokemonDir) {
            $this->error('Dossier introuvable : il doit contenir Graphics\Pokemon\Front (ou être ce dossier Pokemon).');

            return self::FAILURE;
        }
        $this->info("Sprites de combat : {$pokemonDir}");

        $overworldDir = $this->option('overworld');
        if ($overworldDir && ! File::isDirectory($overworldDir . DIRECTORY_SEPARATOR . 'Followers')) {
            $this->warn("Pas de dossier \"Followers\" dans {$overworldDir} : sprites de carte ignorés.");
            $overworldDir = null;
        }

        $criesDir = $this->option('cries') ?? $this->guessCriesDir($this->argument('essentials'));
        if ($criesDir && ! File::isDirectory($criesDir)) {
            $this->warn("Dossier des cris introuvable : {$criesDir}");
            $criesDir = null;
        }

        $species = PokemonSpecies::orderBy('pokedex_number')->get();
        $missing = [];
        $bar = $this->output->createProgressBar($species->count());

        foreach ($species as $sp) {
            $key = self::essentialsKey($sp->slug);
            $updates = [];

            foreach (self::FOLDERS as $source => [$target, $column]) {
                $updates[$column] = $this->copySprite("{$pokemonDir}/{$source}", $target, $key, $missing);
            }

            // Variantes femelles (même nom + "_female") pour les sprites de combat
            $hasFemale = false;
            foreach (['Front' => 'front', 'Back' => 'back', 'Front shiny' => 'front_shiny', 'Back shiny' => 'back_shiny'] as $source => $target) {
                $file = "{$pokemonDir}/{$source}/{$key}_female.png";
                if (File::exists($file)) {
                    $this->copy($file, public_path("game/sprites/pokemon/{$target}/{$key}_female.png"));
                    $hasFemale = true;
                }
            }
            $updates['has_female_sprites'] = $hasFemale;

            if ($overworldDir) {
                foreach (self::OVERWORLD_FOLDERS as $source => [$target, $column]) {
                    $updates[$column] = $this->copySprite("{$overworldDir}/{$source}", $target, $key, $missing);
                }
            }

            if ($criesDir) {
                $cry = collect(['ogg', 'wav', 'mp3'])->map(fn ($ext) => "{$criesDir}/{$key}.{$ext}")->first(fn ($f) => File::exists($f));
                if ($cry) {
                    $name = $key . '.' . pathinfo($cry, PATHINFO_EXTENSION);
                    $this->copy($cry, public_path("game/audio/cries/{$name}"));
                    $updates['cry'] = "game/audio/cries/{$name}";
                } else {
                    $missing[] = "Cri {$key}";
                }
            }

            // On ne remplace pas un sprite existant par "rien" si le fichier manque chez Essentials
            $sp->forceFill(array_filter($updates, fn ($v) => $v !== null))->save();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("{$species->count()} espèces traitées.");

        if ($missing) {
            $this->warn(count($missing) . ' fichier(s) introuvable(s) :');
            foreach (array_slice($missing, 0, 30) as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Nom de fichier Essentials d'après le slug PokéAPI :
     * "bulbasaur" -> BULBASAUR, "mr-mime" -> MRMIME, "nidoran-f" -> NIDORANfE, "nidoran-m" -> NIDORANmA
     */
    public static function essentialsKey(string $slug): string
    {
        return match ($slug) {
            'nidoran-f' => 'NIDORANfE',
            'nidoran-m' => 'NIDORANmA',
            default => strtoupper(str_replace(['-', "'", '.', ' '], '', $slug)),
        };
    }

    /** Copie un sprite ; renvoie le chemin à enregistrer en base, ou null s'il manque */
    private function copySprite(string $sourceDir, string $target, string $key, array &$missing): ?string
    {
        $file = "{$sourceDir}/{$key}.png";
        if (! File::exists($file)) {
            $missing[] = basename($sourceDir) . "/{$key}.png";

            return null;
        }

        $relative = "game/sprites/pokemon/{$target}/{$key}.png";
        $this->copy($file, public_path($relative));

        return $relative;
    }

    private function copy(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to));
        File::copy($from, $to);
    }

    private function findPokemonDir(string $path): ?string
    {
        $path = rtrim($path, '\\/');
        foreach (["{$path}/Graphics/Pokemon", "{$path}/Pokemon", $path] as $candidate) {
            if (File::isDirectory("{$candidate}/Front")) {
                return $candidate;
            }
        }

        return null;
    }

    private function guessCriesDir(string $path): ?string
    {
        $path = rtrim($path, '\\/');
        foreach (["{$path}/Audio/SE/Cries", "{$path}/../Audio/SE/Cries", "{$path}/../../Audio/SE/Cries"] as $candidate) {
            if (File::isDirectory($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
