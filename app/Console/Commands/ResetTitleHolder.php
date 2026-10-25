<?php

namespace App\Console\Commands;

use App\Models\TitleHolder;
use App\Models\Trainer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Étape 16 : renomme le dresseur "titre" (ancien "Tenant du titre") et remet le titre à zéro.
 *
 *   php artisan game:maitre-ort
 *   php artisan game:maitre-ort --nom="Professeur Plantefeve" --classe="Maître de l'ORT"
 *   php artisan game:maitre-ort --garder      (renomme seulement, le joueur qui a le titre le garde)
 *
 * Remettre à zéro : le joueur qui avait le titre le perd ; le dresseur d'origine (avec son équipe
 * de départ, table trainer_pokemon) redevient le Maître. L'historique (tableau d'honneur) est gardé.
 */
class ResetTitleHolder extends Command
{
    protected $signature = 'game:maitre-ort
        {--nom=Professeur Plantefeve : nom du dresseur d\'origine}
        {--classe=Maître de l\'ORT : nom du titre (classe du dresseur)}
        {--garder : ne pas retirer le titre au joueur actuel}';

    protected $description = 'Renomme le Maître de l\'ORT et remet le titre au dresseur d\'origine';

    public function handle(): int
    {
        $trainers = Trainer::where('is_title', true)->get();
        if ($trainers->isEmpty()) {
            $this->error('Aucun dresseur avec is_title = 1 dans la table trainers.');

            return self::FAILURE;
        }

        foreach ($trainers as $trainer) {
            $trainer->forceFill(['name' => $this->option('nom'), 'trainer_class' => $this->option('classe')])->save();
            $this->info("{$trainer->slug} : {$trainer->trainer_class} {$trainer->name}");

            if (! $this->option('garder')) {
                $count = TitleHolder::where('trainer_id', $trainer->id)->whereNull('lost_at')->update(['lost_at' => now()]);
                $this->line($count ? '  Le joueur qui avait le titre l\'a perdu : le dresseur d\'origine redevient le Maître.' : '  Personne n\'avait pris le titre.');
            }
        }

        // L'écran titre garde ses statistiques 60 secondes en cache
        Cache::forget('home:leaderboards');

        return self::SUCCESS;
    }
}
