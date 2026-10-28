<?php

namespace App\Console\Commands;

use App\Models\Move;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Remet de vraies descriptions aux capacités dont le texte dit « Cette capacité ne peut pas être utilisée.
 * Il est recommandé de l'oublier... ». Ce texte vient de Pokémon Épée / Bouclier, où ces capacités avaient été
 * retirées ; PokéAPI donne toujours le texte du jeu le plus récent.
 *
 *   php artisan game:descriptions-capacites           (seulement les descriptions « à oublier »)
 *   php artisan game:descriptions-capacites --toutes  (toutes les descriptions)
 *
 * Les textes viennent de resources/data/move_descriptions.json : pour chaque capacité, le texte français
 * le plus récent qui n'est pas ce message (tiré des données de PokéAPI).
 */
class UpdateMoveDescriptions extends Command
{
    protected $signature = 'game:descriptions-capacites {--toutes : remplacer toutes les descriptions, pas seulement les « à oublier »}';

    protected $description = 'Corrige les descriptions de capacités « Il est recommandé de l\'oublier »';

    /** Le message d'Épée / Bouclier pour les capacités retirées */
    public const OBSOLETE = '/recommandé de l.oublier/iu';

    public function handle(): int
    {
        $texts = json_decode(File::get(resource_path('data/move_descriptions.json')), true) ?: [];
        $changed = 0;
        $missing = [];

        foreach (Move::all() as $move) {
            $obsolete = $move->description === null || preg_match(self::OBSOLETE, $move->description);
            if (! $obsolete && ! $this->option('toutes')) {
                continue;
            }
            $text = $texts[$move->slug] ?? null;
            if (! $text) {
                $obsolete && $missing[] = $move->slug;
                continue;
            }
            if ($text !== $move->description) {
                $move->update(['description' => $text]);
                $changed++;
            }
        }

        $this->info("{$changed} description(s) mise(s) à jour.");
        if ($missing) {
            $this->warn('Sans texte de remplacement : ' . implode(', ', $missing));
        }

        return self::SUCCESS;
    }
}
