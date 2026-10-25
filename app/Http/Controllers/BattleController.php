<?php

namespace App\Http\Controllers;

use App\Battle\BattlePresenter;
use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Trainer;
use App\Services\BattleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Les combats, appelés par le jeu en JavaScript (BattleScene.js).
 * Toutes les réponses sont en JSON : { events: [...], battle: {...} }
 */
class BattleController extends Controller
{
    public function __construct(private BattleService $battles)
    {
    }

    /** Le combat en cours (rechargement de page en plein combat, ou combat lancé par /spawn) */
    public function current(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        $battle = $this->battles->activeBattle($character);
        if (! $battle) {
            return response()->noContent();
        }

        // Combat pas encore commencé (lancé par un administrateur avec /spawn) : on joue son ouverture
        return $this->started($battle);
    }

    /** Le joueur a fait un pas dans les hautes herbes et le jeu a tiré une rencontre */
    public function wild(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
        ]);

        $battle = $this->battles->startWild($character, $data['map'], $data['x'], $data['y']);

        // Pas de Pokémon ici (case sans herbe, zone sans table de rencontres...) : le jeu continue
        if (! $battle) {
            return response()->noContent();
        }

        return $this->started($battle);
    }

    /** Un dresseur a défié le joueur */
    public function trainer(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        $data = $request->validate(['trainer' => ['required', 'string', Rule::exists('trainers', 'slug')]]);
        $trainer = Trainer::where('slug', $data['trainer'])->firstOrFail();

        try {
            $battle = $this->battles->startTrainer($character, $trainer);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->started($battle);
    }

    /** Une action du joueur : attaque, changement, objet, fuite... */
    public function action(Request $request, Character $character, Battle $battle)
    {
        $this->authorizeCharacter($request, $character);
        abort_unless($battle->character_id === $character->id, 404);

        $input = $request->validate([
            'type' => ['required', Rule::in(['move', 'switch', 'item', 'run', 'continue', 'learn', 'safari_ball', 'bait', 'rock'])],
            'slot' => ['nullable', 'integer', 'between:-1,3'],
            'party' => ['nullable', 'integer', 'between:0,5'],
            'item_id' => ['nullable', 'integer'],
            'target' => ['nullable', 'integer', 'between:0,5'],
            'forget' => ['nullable', 'integer', 'between:0,3'],
            'move' => ['nullable', 'integer', 'between:0,3'],   // étape 22 : capacité choisie pour une Huile
        ]);

        try {
            return response()->json($this->battles->act($battle, $input));
        } catch (InvalidAction $e) {
            // Action refusée (plus de PP, objet sans effet...) : message affiché, le tour n'est pas joué
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function started(Battle $battle)
    {
        // Les événements d'ouverture ("Un Rattata sauvage apparaît !") sont le premier élément du journal
        $intro = $battle->turn === 0 ? ($battle->log[0]['events'] ?? []) : [];

        return response()->json([
            'events' => BattlePresenter::events($intro),
            'battle' => $this->battles->view($battle),
        ]);
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
