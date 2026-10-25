<?php

namespace App\Http\Controllers;

use App\Battle\BattlePresenter;
use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\BattleService;
use App\Services\FieldMoveService;
use Illuminate\Http\Request;

/**
 * Capacités utilisées hors combat (étape 11), appelées par le jeu :
 *   GET  /jouer/{id}/terrain           les CS de l'équipe (et si le badge manque), les villes de Vol,
 *                                      et le Pokémon qui suit le joueur
 *   POST /jouer/{id}/terrain           { move: 'cut', map, x, y }   ou   { move: 'fly', map, to }
 */
class FieldMoveController extends Controller
{
    public function __construct(private FieldMoveService $field, private BattleService $battles)
    {
    }

    public function index(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return response()->json([
            'moves' => (object) $this->field->available($character),
            'fly' => $this->field->flyDestinations($character),
            // Le Pokémon qui suit le joueur (il change après un échange de place dans l'équipe, un K.O., une évolution...)
            'follower' => GameController::follower($character),
            // Étape 13 : récompenses du Professeur pas encore annoncées au joueur
            'dexRewards' => app(\App\Services\PokedexService::class)->pending($character),
            'professor' => config('pokedex.professor', 'Professeur Chen'),
            // Étape 14 : le joueur a-t-il une Bicyclette ? (touche B)
            'bike' => $character->items()->where('effect_code', 'ride')->exists(),
            // Étape 15 : les cannes à pêche (touche F : la meilleure), et la partie de Safari en cours
            'rods' => app(\App\Services\FishingService::class)->rods($character),
            'safari' => app(\App\Services\SafariService::class)->publicState(app(\App\Services\SafariService::class)->current($character)),
            // Étape 18 : faut-il envoyer les pas au serveur (Pension occupée, Œuf dans l'équipe) ?
            // Étape 19 : et le Repousse
            'breeding' => app(\App\Services\FieldItemService::class)->stepStatus($character),
            // Étape 23 : l'heure du jeu (teinte du jour et de la nuit)
            'clock' => \App\Services\TimeOfDay::clock(),
            'safariRules' => ['price' => (int) config('safari.price', 500), 'balls' => (int) config('safari.balls', 30), 'steps' => (int) config('safari.steps', 500)],
        ]);
    }

    public function use(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'move' => ['required', 'string', 'max:30'],
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'x' => ['nullable', 'integer', 'min:0'],
            'y' => ['nullable', 'integer', 'min:0'],
            'to' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
        ]);

        try {
            $result = $this->field->use($character, $data['move'], $data);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Éclate-Roc : un Pokémon sauvage est sorti du rocher -> même format que BattleController
        if (! empty($result['battle'])) {
            $battle = $result['battle'];
            $result['battle'] = [
                'events' => BattlePresenter::events($battle->log[0]['events'] ?? []),
                'battle' => $this->battles->view($battle),
            ];
        }

        return response()->json($result);
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
