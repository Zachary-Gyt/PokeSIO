<?php

namespace App\Http\Controllers;

use App\Events\ChatMessage;
use App\Models\Character;
use App\Services\AdminCommandService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le chat textuel : un message est lu par tous les joueurs présents sur la même carte.
 *
 * Le message passe PAR LARAVEL (et non de navigateur à navigateur comme les positions) :
 * on vérifie la longueur, on nettoie le texte, on limite le débit (pas de spam),
 * et on garde les 20 derniers messages de chaque carte pour ceux qui arrivent.
 *
 * Étape 10 :
 *  - un joueur "muet" (/mute) ne peut plus écrire ;
 *  - un texte qui commence par "/" est une COMMANDE d'administrateur (AdminCommandService) :
 *    elle n'est pas envoyée aux autres, la réponse ("reply") ne s'affiche que chez l'admin ;
 *  - les messages des admins portent "admin": true (nom en rouge, avec [Admin]).
 */
class ChatController extends Controller
{
    public const MAX_LENGTH = 120;
    private const HISTORY = 20;

    /** Les derniers messages de la carte (à l'arrivée sur la carte) */
    public function index(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $map = $this->mapKey($request->query('map', ''));

        return response()->json(['messages' => $map ? Cache::get("chat:{$map}", []) : []]);
    }

    public function send(Request $request, Character $character, AdminCommandService $commands)
    {
        $this->authorizeCharacter($request, $character);
        $user = $request->user();
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100'],
            'text' => ['required', 'string', 'max:500'],
        ]);

        // Commandes des administrateurs : jamais diffusées, réponse privée
        if (str_starts_with(ltrim($data['text']), '/')) {
            if (! $user->is_admin) {
                return response()->json(['reply' => ['Les commandes sont réservées aux administrateurs.']]);
            }

            return response()->json(['reply' => $commands->run($user, $character, ltrim($data['text']))]);
        }

        if ($user->isMuted()) {
            return response()->json(['message' => 'Un administrateur vous a retiré la parole jusqu\'à ' . $user->muted_until->format('H:i') . '.'], 403);
        }

        $map = $this->mapKey($data['map']);
        if (! $map) {
            return response()->json(['message' => 'Carte inconnue.'], 422);
        }

        // Anti-spam : 5 messages toutes les 10 secondes au maximum
        $key = "chat:{$character->id}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Doucement ! Attendez un peu avant de réécrire.'], 429);
        }
        RateLimiter::hit($key, 10);

        // Nettoyage : pas de caractères invisibles, pas d'espaces en trop, 120 caractères maximum
        $text = preg_replace('/[\p{C}]+/u', '', $data['text']);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $text = mb_substr($text, 0, self::MAX_LENGTH);
        if ($text === '') {
            return response()->json(['message' => 'Message vide.'], 422);
        }

        $message = [
            'id' => $character->id,
            'name' => $character->name,
            'text' => $text,
            'at' => now()->format('H:i'),
            'admin' => (bool) $user->is_admin,
            'professor' => (bool) $user->is_professor,   // grade Professeur : titre [Professeur]
        ];

        $history = Cache::get("chat:{$map}", []);
        $history[] = $message;
        Cache::put("chat:{$map}", array_slice($history, -self::HISTORY), now()->addHours(6));

        try {
            broadcast(new ChatMessage($map, $message));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Le nom de carte doit correspondre à un vrai fichier de carte (public/game/maps/xxx.tmj) */
    private function mapKey(string $map): ?string
    {
        return preg_match('/^[a-z0-9_]+$/', $map) && File::exists(public_path("game/maps/{$map}.tmj")) ? $map : null;
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
