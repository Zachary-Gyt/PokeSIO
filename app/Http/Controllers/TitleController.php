<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Trainer;
use App\Services\TitleService;
use Illuminate\Http\Request;

/**
 * Le Tenant du titre : qui l'est, depuis quand, son équipe et l'historique.
 * Appelé quand le joueur parle au PNJ (les infos changent quand un autre joueur prend le titre).
 */
class TitleController extends Controller
{
    public function __invoke(Request $request, Character $character, string $slug, TitleService $titles)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $trainer = Trainer::where('slug', $slug)->where('is_title', true)->firstOrFail();

        return response()->json($titles->info($trainer, $character));
    }
}
