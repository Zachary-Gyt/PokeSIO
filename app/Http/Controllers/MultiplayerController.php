<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Trade;
use App\Services\MultiplayerService;
use App\Services\PvpService;
use App\Services\TradeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le multijoueur, appelé par le jeu (public/js/game/net/Multiplayer.js) :
 *   invitations (échange / combat), échanges, combats entre joueurs.
 *
 * Toutes les erreurs de règle (InvalidAction) reviennent en 422 avec un message à afficher.
 */
class MultiplayerController extends Controller
{
    public function __construct(
        private MultiplayerService $multi,
        private TradeService $trades,
        private PvpService $pvp,
    ) {
    }

    // ---------------------------------------------------------------------
    // Invitations
    // ---------------------------------------------------------------------

    public function invite(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'target' => ['required', 'integer', 'exists:characters,id'],
            'kind' => ['required', Rule::in(['trade', 'battle'])],
        ]);

        return $this->attempt(fn () => ['id' => $this->multi->invite($character, Character::findOrFail($data['target']), $data['kind'])]);
    }

    public function answer(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(['trade', 'battle'])],
            'accept' => ['required', 'boolean'],
        ]);

        return $this->attempt(fn () => $this->multi->answer($character, $data['kind'], $data['id'], $data['accept']));
    }

    // ---------------------------------------------------------------------
    // Échanges
    // ---------------------------------------------------------------------

    public function trade(Request $request, Character $character, Trade $trade)
    {
        $this->authorizeCharacter($request, $character);

        return $this->attempt(fn () => $this->trades->view($trade, $character));
    }

    public function tradeOffer(Request $request, Character $character, Trade $trade)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['pokemon_id' => ['required', 'integer']]);

        return $this->attempt(function () use ($trade, $character, $data) {
            $this->trades->offer($trade, $character, $data['pokemon_id']);

            return $this->trades->view($trade->fresh(), $character);
        });
    }

    public function tradeConfirm(Request $request, Character $character, Trade $trade)
    {
        $this->authorizeCharacter($request, $character);

        return $this->attempt(function () use ($trade, $character) {
            $this->trades->confirm($trade, $character);

            return $this->trades->view($trade->fresh(), $character);
        });
    }

    public function tradeCancel(Request $request, Character $character, Trade $trade)
    {
        $this->authorizeCharacter($request, $character);

        return $this->attempt(function () use ($trade, $character) {
            $this->trades->cancel($trade, $character);

            return ['status' => 'cancelled'];
        });
    }

    // ---------------------------------------------------------------------
    // Combats entre joueurs
    // ---------------------------------------------------------------------

    /** ?since=N : seulement les événements que le joueur n'a pas encore vus */
    public function pvpState(Request $request, Character $character, Battle $battle)
    {
        $this->authorizeCharacter($request, $character);
        abort_unless($battle->isPvp(), 404);

        return $this->attempt(fn () => $this->pvp->state($battle, $character, (int) $request->query('since', -1)));
    }

    public function pvpAction(Request $request, Character $character, Battle $battle)
    {
        $this->authorizeCharacter($request, $character);
        abort_unless($battle->isPvp(), 404);
        $input = $request->validate([
            'type' => ['required', Rule::in(['move', 'switch', 'continue'])],
            'slot' => ['nullable', 'integer', 'between:-1,3'],
            'party' => ['nullable', 'integer', 'between:0,5'],
            'since' => ['nullable', 'integer'],
        ]);

        return $this->attempt(function () use ($battle, $character, $input) {
            $this->pvp->act($battle, $character, $input);

            return $this->pvp->state($battle->fresh(), $character, (int) ($input['since'] ?? -1));
        });
    }

    public function pvpForfeit(Request $request, Character $character, Battle $battle)
    {
        $this->authorizeCharacter($request, $character);
        abort_unless($battle->isPvp(), 404);

        return $this->attempt(function () use ($battle, $character, $request) {
            $this->pvp->forfeit($battle, $character);

            return $this->pvp->state($battle->fresh(), $character, (int) $request->input('since', -1));
        });
    }

    public function pvpClaim(Request $request, Character $character, Battle $battle)
    {
        $this->authorizeCharacter($request, $character);
        abort_unless($battle->isPvp(), 404);

        return $this->attempt(function () use ($battle, $character, $request) {
            $this->pvp->claimVictory($battle, $character);

            return $this->pvp->state($battle->fresh(), $character, (int) $request->input('since', -1));
        });
    }

    private function attempt(callable $callback)
    {
        try {
            return response()->json($callback());
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
