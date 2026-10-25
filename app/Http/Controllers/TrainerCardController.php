<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * La Carte Dresseur (menu du jeu) : nom, ID, argent, Pokédex, temps de jeu, badges.
 */
class TrainerCardController extends Controller
{
    public function __invoke(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);

        $owned = $character->badges()->get()->keyBy('id');

        return response()->json([
            'name' => $character->name,
            'gender' => $character->gender,
            'avatar' => $character->avatarKey(),   // étape 17
            'trainerId' => sprintf('%05d', $character->trainer_id),
            'money' => $character->money,
            'seen' => $character->pokedexEntries()->count(),
            'caught' => $character->pokedexEntries()->where('caught', true)->count(),
            'playTime' => (int) $character->play_time,       // en secondes (sauvegardé toutes les 30 s)
            'started' => $character->created_at?->format('d/m/Y'),
            // Les 8 badges : obtenus ou non (l'icône n'est affichée que s'il est obtenu)
            'badges' => Badge::orderBy('order')->get()->map(fn (Badge $b) => [
                'order' => $b->order,
                'name' => $b->name,
                'icon' => (int) $b->sprite,
                'owned' => $owned->has($b->id),
                'obtainedAt' => $owned->has($b->id) ? Carbon::parse($owned[$b->id]->pivot->obtained_at)->format('d/m/Y') : null,
            ])->values()->all(),
        ]);
    }
}
