<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\FieldMoveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sauvegarde automatique de la partie, appelée par le jeu (SaveManager.js) :
 *   POST /jouer/{character}/sauvegarde   { map, x, y, facing }
 *
 * Le navigateur n'envoie QUE la position. Tout le reste (temps de jeu, date) est calculé ici :
 * on ne fait jamais confiance au navigateur pour des valeurs qu'un joueur aurait intérêt à truquer.
 */
class GameSaveController extends Controller
{
    /**
     * Temps maximum compté entre deux sauvegardes (le jeu sauvegarde toutes les 30 s).
     * Au-delà, le joueur était absent (onglet en veille, ordinateur éteint) : ce temps ne compte pas.
     */
    private const MAX_SECONDS_BETWEEN_SAVES = 90;

    // "__invoke" : un contrôleur à une seule action, appelé directement par la route
    public function __invoke(Request $request, Character $character, FieldMoveService $field)
    {
        abort_unless($character->user_id === $request->user()->id, 404);

        $data = $request->validate([
            // Nom de fichier de carte : minuscules, chiffres et "_" seulement.
            // Indispensable : on s'en sert pour lire un fichier, il ne doit pas pouvoir contenir "../"
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'facing' => ['required', Rule::in(['up', 'down', 'left', 'right'])],
            // Étape 19 : la sortie empruntée juste avant (entrée d'une grotte, pour la Corde Sortie)
            'from' => ['nullable', 'array'],
            'from.map' => ['required_with:from', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'from.x' => ['required_with:from', 'integer', 'min:0'],
            'from.y' => ['required_with:from', 'integer', 'min:0'],
            'from.facing' => ['nullable', Rule::in(['up', 'down', 'left', 'right'])],
        ]);

        // La carte doit exister, et la position doit être DANS la carte
        $size = $this->mapSize($data['map']);

        if ($size === null) {
            throw ValidationException::withMessages(['map' => 'Carte inconnue.']);
        }
        if ($data['x'] >= $size['width'] || $data['y'] >= $size['height']) {
            throw ValidationException::withMessages(['x' => 'Position hors de la carte.']);
        }

        // Temps de jeu : le temps écoulé depuis la dernière sauvegarde (ou l'ouverture du jeu), plafonné
        $elapsed = $character->last_seen_at
            ? (int) abs(now()->diffInSeconds($character->last_seen_at))
            : 0;

        $character->forceFill([
            'current_map' => $data['map'],
            'position_x' => $data['x'],
            'position_y' => $data['y'],
            'facing' => $data['facing'],
            'play_time' => $character->play_time + min($elapsed, self::MAX_SECONDS_BETWEEN_SAVES),
            'last_seen_at' => now(),
        ])->save();

        // Étape 19 : entrée dans une grotte par une sortie : la Corde Sortie y ramènera
        app(\App\Services\FieldItemService::class)->rememberCaveEntrance($character, $data['map'], $data['from'] ?? null);

        // Étape 27 : sorti de la Ligue en pleine tentative : il faudra tout recommencer
        app(\App\Services\LeagueService::class)->rememberMap($character, $data['map']);

        // Étape 11 : une ville visitée (propriété de carte "fly") devient une destination de Vol
        $field->rememberVisit($character, $data['map']);

        // Étape 15 : sauvegarde hors du Parc Safari (Vol, téléportation...) : la partie de Safari est finie.
        // Sur la carte du gardien, on attend : le joueur vient peut-être juste de payer.
        $safari = app(\App\Services\SafariService::class);
        $current = $safari->current($character);
        if ($current && ! \App\Services\MapInfo::load($data['map'])?->property('safari') && $data['map'] !== ($current['exit']['map'] ?? null)) {
            $safari->end($character);
        }

        return response()->json([
            'saved_at' => now()->toIso8601String(),
            'play_time' => $character->play_time,
        ]);
    }

    /**
     * Largeur et hauteur (en cases) d'une carte Tiled, ou null si elle n'existe pas.
     * Mis en cache : on ne relit le fichier .tmj que s'il a été modifié (sa date fait partie de la clé).
     */
    private function mapSize(string $mapKey): ?array
    {
        $path = public_path("game/maps/{$mapKey}.tmj");

        if (! File::exists($path)) {
            return null;
        }

        return Cache::remember("map-size:{$mapKey}:" . File::lastModified($path), now()->addDay(), function () use ($path) {
            $map = json_decode(File::get($path), true);

            return [
                'width' => (int) ($map['width'] ?? 0),
                'height' => (int) ($map['height'] ?? 0),
            ];
        });
    }
}
