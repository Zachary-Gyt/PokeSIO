<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Scenario\EventRunner;
use App\Services\MapInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Étape 24 : les ÉVÉNEMENTS du scénario (fichiers resources/scenario/*.txt).
 *
 *   GET  /jouer/{id}/evenements?map=...        les objets Tiled cachés pour ce joueur (show_if / hide_if)
 *   POST /jouer/{id}/evenements { map, object } lance l'événement de l'objet Tiled n° object
 *   POST /jouer/{id}/evenements/suite { choice } reprend l'événement (réponse, ou après le combat)
 *
 * Voir App\Scenario\EventRunner pour ce qui est renvoyé.
 */
class EventController extends Controller
{
    public function __construct(private EventRunner $runner)
    {
    }

    public function state(Request $request, Character $character)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        $data = $request->validate(['map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/']]);
        $map = MapInfo::load($data['map']);

        // Page rechargée pendant le combat d'un événement : le jeu reprendra l'événement à la fin du combat
        $run = \App\Scenario\StoryState::run($character);
        $battle = ($run['wait'] ?? null) === 'battle'
            && \App\Models\Battle::whereKey($run['battle'] ?? 0)->where('character_id', $character->id)->where('status', 'active')->exists();

        return response()->json(['hidden' => $map ? $this->runner->hiddenObjects($character, $map) : [], 'battle' => $battle]);
    }

    public function start(Request $request, Character $character)
    {
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'object' => ['required', 'integer', 'min:0'],
        ]);

        return $this->locked($request, $character, fn (Character $c) => $this->runner->start($c, $data['map'], (int) $data['object']));
    }

    public function resume(Request $request, Character $character)
    {
        $data = $request->validate(['choice' => ['nullable', 'integer', 'min:0', 'max:20']]);

        return $this->locked($request, $character, fn (Character $c) => $this->runner->resume($c, isset($data['choice']) ? (int) $data['choice'] : null));
    }

    /** Ligne du personnage verrouillée : deux appuis très rapides ne jouent pas deux fois un cadeau */
    private function locked(Request $request, Character $character, callable $action)
    {
        abort_unless($character->user_id === $request->user()->id, 404);
        try {
            return response()->json(DB::transaction(fn () => $action(Character::whereKey($character->id)->lockForUpdate()->first())));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
