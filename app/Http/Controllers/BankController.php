<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\BankService;
use App\Services\MapInfo;
use Illuminate\Http\Request;

/**
 * La Banque (PNJ banquier ou distributeur : propriété Tiled action = bank).
 *   GET  /jouer/{id}/banque?map=...                   le compte (les intérêts sont ajoutés)
 *   POST /jouer/{id}/banque/deposer  { map, amount }  déposer
 *   POST /jouer/{id}/banque/retirer  { map, amount }  retirer
 */
class BankController extends Controller
{
    public function __construct(private BankService $bank)
    {
    }

    public function show(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $this->checkMap((string) $request->query('map', ''));

        return response()->json($this->bank->status($character));
    }

    public function deposit(Request $request, Character $character)
    {
        return $this->move($request, $character, fn (int $amount) => $this->bank->deposit($character, $amount));
    }

    public function withdraw(Request $request, Character $character)
    {
        return $this->move($request, $character, fn (int $amount) => $this->bank->withdraw($character, $amount));
    }

    private function move(Request $request, Character $character, callable $action)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'amount' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);
        $this->checkMap($data['map']);
        try {
            return response()->json($action((int) $data['amount']));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Il faut être sur une carte qui a une banque (un PNJ ou un panneau action = bank) */
    private function checkMap(string $map): void
    {
        abort_unless(preg_match('/^[a-z0-9_]+$/', $map) && MapInfo::load($map)?->hasAction('bank'), 422, 'Il n\'y a pas de banque ici.');
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
