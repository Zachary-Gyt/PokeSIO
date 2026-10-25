<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\HallOfFameEntry;
use Illuminate\Http\Request;

/**
 * Étape 27 : le PANTHÉON.
 *   GET /jouer/{id}/pantheon   les entrées du personnage (le "PC du Panthéon" du jeu)
 *   GET /wiki/pantheon         tous les dresseurs entrés au Panthéon (page publique du Wiki)
 */
class HallOfFameController extends Controller
{
    public function index(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);

        return response()->json([
            'entries' => HallOfFameEntry::where('character_id', $character->id)->orderByDesc('number')->get()->map->present()->values(),
        ]);
    }

    public function wiki()
    {
        $entries = HallOfFameEntry::orderByDesc('entered_at')->orderByDesc('id')->paginate(20);
        $champions = HallOfFameEntry::selectRaw('character_id, trainer_name, count(*) as total, min(entered_at) as first_at')
            ->groupBy('character_id', 'trainer_name')->orderBy('first_at')->limit(10)->get();

        return view('wiki.halloffame', ['entries' => $entries, 'champions' => $champions]);
    }
}
