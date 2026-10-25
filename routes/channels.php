<?php

use App\Models\Character;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canaux de diffusion (Laravel Reverb)
|--------------------------------------------------------------------------
| Le navigateur demande à Laravel s'il a le droit d'écouter un canal (route /broadcasting/auth).
|
|   character.{id}   canal PRIVÉ d'un personnage : invitations, échanges, combats, titre perdu.
|                    Seul le propriétaire du personnage peut l'écouter.
|
|   map.{carte}      canal de PRÉSENCE d'une carte : tous les joueurs sur cette carte.
|                    Chacun y envoie sa position ("whisper" : de navigateur à navigateur,
|                    sans passer par Laravel), et Reverb tient la liste des présents.
|                    Le personnage joué est celui ouvert dans /jouer/{personnage} (en session).
*/

Broadcast::channel('character.{id}', function ($user, int $id) {
    return Character::whereKey($id)->where('user_id', $user->id)->exists();
});

Broadcast::channel('map.{map}', function ($user, string $map) {
    $character = Character::find(session('playing_character_id'));
    if (! $character || $character->user_id !== $user->id) {
        return false;
    }

    // Ce que les AUTRES joueurs voient de moi
    return ['id' => $character->id, 'name' => $character->name, 'gender' => $character->gender, 'avatar' => $character->avatarKey()];
});
