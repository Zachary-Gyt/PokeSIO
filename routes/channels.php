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
|   chat.global      canal PRIVÉ du chat global (/all) : tout joueur en jeu.
|   online           canal de PRÉSENCE de tous les joueurs en jeu : connexions et déconnexions.
|   group.{id}       canal de PRÉSENCE d'un groupe : chat /g, membres connectés.
|
|   map.{carte}      canal de PRÉSENCE d'une carte : tous les joueurs sur cette carte.
|                    Chacun y envoie sa position ("whisper" : de navigateur à navigateur,
|                    sans passer par Laravel), et Reverb tient la liste des présents.
|                    Le personnage joué est celui ouvert dans /jouer/{personnage} (en session).
*/

Broadcast::channel('character.{id}', function ($user, int $id) {
    return Character::whereKey($id)->where('user_id', $user->id)->exists();
});

// Chat global : tout joueur qui a un personnage ouvert
Broadcast::channel('chat.global', function ($user) {
    $character = Character::find(session('playing_character_id'));

    return $character && $character->user_id === $user->id;
});

// Joueurs en ligne (présence) : le jeu annonce dans le chat qui se connecte et se déconnecte
Broadcast::channel('online', function ($user) {
    $character = Character::find(session('playing_character_id'));
    if (! $character || $character->user_id !== $user->id) {
        return false;
    }

    return ['id' => $character->id, 'name' => $character->name];
});

// Groupe (présence) : les membres connectés ; le jeu annonce les arrivées et départs
Broadcast::channel('group.{id}', function ($user, int $id) {
    $character = Character::find(session('playing_character_id'));
    if (! $character || $character->user_id !== $user->id || $character->group_id !== $id) {
        return false;
    }

    return ['id' => $character->id, 'name' => $character->name];
});

Broadcast::channel('map.{map}', function ($user, string $map) {
    $character = Character::find(session('playing_character_id'));
    if (! $character || $character->user_id !== $user->id) {
        return false;
    }

    // Ce que les AUTRES joueurs voient de moi
    return ['id' => $character->id, 'name' => $character->name, 'gender' => $character->gender, 'avatar' => $character->avatarKey()];
});
