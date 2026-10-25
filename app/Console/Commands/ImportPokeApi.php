<?php

namespace App\Console\Commands;

use App\Services\PokeApi\PokeApiClient;
use App\Services\PokeApi\PokeApiImporter;
use Illuminate\Console\Command;

/**
 * php artisan pokeapi:import                       -> les 151 de Kanto
 * php artisan pokeapi:import --to=251              -> ajoute la Gen 2 plus tard
 * php artisan pokeapi:import --download-sprites    -> copie les sprites dans storage/app/public
 */
class ImportPokeApi extends Command
{
    protected $signature = 'pokeapi:import
        {--from=1 : Premier numéro de Pokédex}
        {--to=151 : Dernier numéro de Pokédex}
        {--download-sprites : Télécharge les sprites au lieu de stocker les URL}
        {--skip-items : Ne pas importer les objets}';

    protected $description = 'Importe types, natures, objets, Pokémon, talents, attaques et évolutions depuis PokéAPI';

    public function handle(): int
    {
        $importer = new PokeApiImporter(new PokeApiClient(), (bool) $this->option('download-sprites'));
        $importer->setLogger(fn (string $msg) => $this->warn("  {$msg}"));

        $from = (int) $this->option('from');
        $to = (int) $this->option('to');

        $this->info('Types…');
        $this->line('  ' . $importer->importTypes() . ' types');

        $this->info('Natures…');
        $this->line('  ' . $importer->importNatures() . ' natures');

        if (! $this->option('skip-items')) {
            $this->info('Objets…');
            $slugs = $importer->itemSlugs();
            $this->withProgressBar($slugs, fn (string $slug) => $importer->importItem($slug));
            $this->newLine();
        }

        $this->info("Pokémon #{$from} à #{$to} (talents, attaques et learnsets inclus)…");
        $this->withProgressBar(range($from, $to), fn (int $dex) => $importer->importSpecies($dex));
        $this->newLine();

        $this->info('Évolutions…');
        $this->line('  ' . $importer->importEvolutions() . ' évolutions');

        $this->newLine();
        $this->info('Import terminé.');
        $this->line('Pense à lier le stockage public si tu as téléchargé les sprites : php artisan storage:link');

        return self::SUCCESS;
    }
}
