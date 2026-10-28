<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Services\GroupService;
use Illuminate\Http\Request;

/**
 * Le GROUPE, depuis le jeu (menu > Groupe, Action devant un joueur) :
 *   GET  /jouer/{id}/groupe                 mon groupe (membres, chef, cartes) et les joueurs que je peux inviter
 *   POST /jouer/{id}/groupe/inviter         { target }
 *   POST /jouer/{id}/groupe/repondre        { id, accept }
 *   POST /jouer/{id}/groupe/quitter
 *   POST /jouer/{id}/groupe/exclure         { target }   (chef)
 *   POST /jouer/{id}/groupe/chef            { target }   (chef)
 * Les mêmes actions existent en commandes de chat (/inviter, /quitter...). Erreurs de règle : 422 + message.
 */
class GroupController extends Controller
{
    public function __construct(private GroupService $groups)
    {
    }

    public function show(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $character->refresh();

        return response()->json([
            'group' => $this->groups->view($character),
            'candidates' => ! $character->group_id || $character->group?->leader_id === $character->id ? $this->groups->candidates($character) : [],
            'max' => $this->groups->maxSize(),
        ]);
    }

    public function invite(Request $request, Character $character)
    {
        $target = $this->target($request, $character);

        return $this->attempt(function () use ($character, $target) {
            $this->groups->invite($character->fresh(), $target);

            return ['message' => "Invitation envoyée à {$target->name}."];
        });
    }

    public function answer(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['id' => ['required', 'integer'], 'accept' => ['required', 'boolean']]);

        return $this->attempt(fn () => ['groupId' => $this->groups->answer($character, $data['id'], $data['accept'])?->id]);
    }

    public function leave(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        return $this->attempt(function () use ($character) {
            $this->groups->leave($character);

            return ['ok' => true];
        });
    }

    public function kick(Request $request, Character $character)
    {
        $target = $this->target($request, $character);

        return $this->attempt(function () use ($character, $target) {
            $this->groups->kick($character->fresh(), $target);

            return ['ok' => true];
        });
    }

    public function promote(Request $request, Character $character)
    {
        $target = $this->target($request, $character);

        return $this->attempt(function () use ($character, $target) {
            $this->groups->promote($character->fresh(), $target);

            return ['ok' => true];
        });
    }

    private function target(Request $request, Character $character): Character
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['target' => ['required', 'integer', 'exists:characters,id']]);

        return Character::findOrFail($data['target']);
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
