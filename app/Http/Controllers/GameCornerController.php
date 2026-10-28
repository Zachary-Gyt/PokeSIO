<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\GameCornerService;
use App\Services\MapInfo;
use App\Services\RouletteService;
use App\Services\SlotMachineService;
use Illuminate\Http\Request;

/**
 * Étape 20 : le Casino, appelé par le jeu.
 *   GET  /jouer/{id}/casino?map=...                       jetons, Boîte Jetons, lots du guichet
 *   POST /jouer/{id}/casino/jetons    { map, amount }     acheter des jetons, au nombre près (PNJ action = coins)
 *   POST /jouer/{id}/casino/jetons/vendre { map, amount } revendre des jetons
 *   GET  /jouer/{id}/casino/lots?map=&counter=            un comptoir des lots (PNJ action = prizes)
 *   POST /jouer/{id}/casino/lots      { map, counter, index }
 *   POST /jouer/{id}/casino/machine   { map, difficulty } s'asseoir à une machine à sous
 *   POST /jouer/{id}/casino/machine/jouer { wager }       une partie (le serveur tire le résultat)
 *   POST /jouer/{id}/casino/roulette  { map, wager }      s'asseoir à une roulette
 *   POST /jouer/{id}/casino/roulette/jouer { x, y }       un lancer
 *
 * Chaque guichet, machine ou table doit exister sur la carte (MapInfo), avec ce réglage.
 */
class GameCornerController extends Controller
{
    public function __construct(
        private GameCornerService $corner,
        private SlotMachineService $slots,
        private RouletteService $roulette,
    ) {
    }

    public function show(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return response()->json($this->corner->wallet($character) + [
            'coinCase' => $this->corner->hasCoinCase($character),
            'max' => $this->corner->max(),
            'price' => $this->corner->coinPrice(),
            'sellPrice' => $this->corner->coinSellPrice(),
        ]);
    }

    public function buy(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['map' => $this->mapRule(), 'amount' => ['required', 'integer', 'min:1', 'max:99999']]);
        $this->requireAction($data['map'], 'coins');

        return $this->attempt(fn () => $this->corner->buy($character, $data['amount']));
    }

    public function sell(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['map' => $this->mapRule(), 'amount' => ['required', 'integer', 'min:1', 'max:99999']]);
        $this->requireAction($data['map'], 'coins');

        return $this->attempt(fn () => $this->corner->sell($character, $data['amount']));
    }

    public function prizes(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['map' => $this->mapRule(), 'counter' => ['required', 'string', 'max:40']]);
        $this->requireAction($data['map'], 'prizes', $data['counter']);

        return response()->json([
            'prizes' => $this->corner->prizes($data['counter']),
            'coins' => (int) $character->coins,
            'coinCase' => $this->corner->hasCoinCase($character),
        ]);
    }

    public function exchange(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'map' => $this->mapRule(), 'counter' => ['required', 'string', 'max:40'], 'index' => ['required', 'integer', 'min:0'],
        ]);
        $this->requireAction($data['map'], 'prizes', $data['counter']);

        return $this->attempt(fn () => $this->corner->exchange($character, $data['counter'], $data['index'], $data['map']));
    }

    public function openSlots(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['map' => $this->mapRule(), 'difficulty' => ['required', 'integer', 'between:0,2']]);
        $this->requireAction($data['map'], 'slots', (string) $data['difficulty']);

        return $this->attempt(fn () => $this->slots->open($character, $data['difficulty']));
    }

    public function playSlots(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['wager' => ['required', 'integer', 'between:0,3']]);

        return $this->attempt(fn () => $this->slots->play($character, $data['wager']));
    }

    public function openRoulette(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['map' => $this->mapRule(), 'wager' => ['required', 'integer', 'in:1,3']]);
        $this->requireAction($data['map'], 'roulette', (string) $data['wager']);

        return $this->attempt(fn () => $this->roulette->open($character, $data['wager']));
    }

    public function playRoulette(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['x' => ['required', 'integer', 'between:0,4'], 'y' => ['required', 'integer', 'between:0,3']]);

        return $this->attempt(fn () => $this->roulette->play($character, $data['x'], $data['y']));
    }

    // ---------------------------------------------------------------------

    private function attempt(callable $action)
    {
        try {
            return response()->json($action());
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function mapRule(): array
    {
        return ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'];
    }

    /** La carte a-t-elle ce guichet / cette machine (avec ce réglage) ? */
    private function requireAction(string $mapKey, string $action, ?string $setting = null): void
    {
        $map = MapInfo::load($mapKey);
        abort_unless($map?->hasAction($action), 422, 'Il n\'y a rien de tel ici.');
        if ($setting !== null) {
            abort_unless(in_array($setting, $map->casinoOptions($action), true), 422, 'Il n\'y a rien de tel ici.');
        }
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
