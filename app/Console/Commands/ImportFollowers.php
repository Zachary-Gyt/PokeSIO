<?php

namespace App\Console\Commands;

use App\Models\PokemonSpecies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Les sprites "qui suivent" (marche sur la carte, normal + chromatique) des Pokémon de la base,
 * SANS recopier les sprites de combat (contrairement à pokemon:essentials-sprites).
 *
 *   php artisan pokemon:followers "C:\...\Generation 9 Pack v3.3.8\Graphics\Characters\pokemon_overworld"
 *   php artisan pokemon:followers "..." --generations=2,3,4      (par défaut : 2, 3 et 4)
 *   php artisan pokemon:followers "..." --generations=all --remplacer
 *
 * Le dossier doit contenir "Followers" et "Followers shiny" (fichiers BULBASAUR.png, CHIKORITA.png...).
 * Sans --remplacer, une espèce qui a déjà son sprite (fichier présent) est laissée telle quelle.
 */
class ImportFollowers extends Command
{
    protected $signature = 'pokemon:followers
        {dossier : dossier contenant "Followers" et "Followers shiny"}
        {--generations=2,3,4 : générations à traiter (ex. 2,3,4 ou all)}
        {--remplacer : recopier même les sprites déjà présents}';

    protected $description = 'Copie les sprites de carte (Pokémon qui suit) des générations choisies dans public/game';

    private const FOLDERS = [
        'Followers' => ['overworld', 'sprite_overworld'],
        'Followers shiny' => ['overworld_shiny', 'sprite_overworld_shiny'],
    ];

    public function handle(): int
    {
        $dir = rtrim($this->argument('dossier'), '\\/');
        if (! File::isDirectory("{$dir}/Followers") && File::isDirectory("{$dir}/pokemon_overworld/Followers")) {
            $dir .= '/pokemon_overworld';   // on a donné le dossier Characters
        }
        if (! File::isDirectory("{$dir}/Followers")) {
            $this->error("Pas de dossier \"Followers\" dans {$dir}.");

            return self::FAILURE;
        }

        $generations = $this->option('generations') === 'all'
            ? null
            : array_filter(array_map('intval', explode(',', (string) $this->option('generations'))));
        $species = PokemonSpecies::query()
            ->when($generations, fn ($q) => $q->whereIn('generation', $generations))
            ->orderBy('pokedex_number')->get();

        $copied = 0;
        $kept = 0;
        $missing = [];
        foreach ($species as $sp) {
            $key = ImportEssentialsSprites::essentialsKey($sp->slug);
            $updates = [];
            foreach (self::FOLDERS as $source => [$target, $column]) {
                $relative = "game/sprites/pokemon/{$target}/{$key}.png";
                if (! $this->option('remplacer') && File::exists(public_path($relative))) {
                    $updates[$column] = $relative;   // déjà là : on s'assure seulement que la base le connaît
                    $kept++;

                    continue;
                }
                $file = "{$dir}/{$source}/{$key}.png";
                if (! File::exists($file)) {
                    $missing[] = "{$source}/{$key}.png ({$sp->name})";

                    continue;
                }
                File::ensureDirectoryExists(public_path("game/sprites/pokemon/{$target}"));
                File::copy($file, public_path($relative));
                $updates[$column] = $relative;
                $copied++;
            }
            if ($updates) {
                $sp->forceFill($updates)->save();
            }
        }

        $this->info("{$species->count()} espèces : {$copied} sprite(s) copié(s), {$kept} déjà présent(s).");
        if ($missing) {
            $this->warn(count($missing) . ' sprite(s) introuvable(s) :');
            foreach (array_slice($missing, 0, 40) as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }
}
