<?php

namespace App\Http\Controllers;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Le jeu signale ici les images qu'il n'a pas pu charger (public/js/game/net/ImageWatch.js).
 * Elles sont notées dans storage/logs/laravel-*.log : « Image non chargée ».
 */
class DiagnosticController extends Controller
{
    public function image(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'key' => ['nullable', 'string', 'max:200'],
            'url' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'integer'],
            'scene' => ['nullable', 'string', 'max:40'],
            'memory' => ['nullable', 'integer'],
        ]);
        Log::warning('Image non chargée', $data + [
            'personnage' => $character->name,
            'navigateur' => substr((string) $request->userAgent(), 0, 200),
        ]);

        return response()->noContent();
    }
}
