<?php

namespace App\Services;

use App\Events\PlayerNotice;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * UNE SEULE PARTIE À LA FOIS PAR COMPTE (pas de double connexion).
 *
 * Chaque ouverture du jeu (GameController::show) reçoit un jeton, gardé dans le cache : c'est la seule partie
 * « active » du compte. Le jeu renvoie ce jeton avec chaque requête (en-tête X-Play-Token, ajouté par
 * game.blade.php). Si un AUTRE onglet ou un autre appareil ouvre le jeu sur le même compte, l'ancienne partie
 * est prévenue tout de suite (notification « elsewhere »), et ses requêtes sont refusées (409,
 * middleware SinglePlaySession) : elle se met en pause et propose « Jouer ici ».
 * La dernière partie ouverte gagne, comme dans la plupart des jeux en ligne.
 */
class PlaySession
{
    private const TTL_DAYS = 7;

    private static function key(int $userId): string
    {
        return "play-session:{$userId}";
    }

    /** Une partie s'ouvre : elle devient la seule active du compte. Renvoie son jeton. */
    public static function start(User $user, Character $character): string
    {
        $previous = Cache::get(self::key($user->id));
        $token = Str::random(40);
        Cache::put(self::key($user->id), ['token' => $token, 'character' => $character->id], now()->addDays(self::TTL_DAYS));

        // L'ancienne partie (autre onglet, autre appareil) est prévenue tout de suite
        if (is_array($previous) && ! empty($previous['character'])) {
            try {
                PlayerNotice::send((int) $previous['character'], 'elsewhere', ['token' => $previous['token']]);
            } catch (\Throwable $e) {
                // sans Reverb : elle s'en rendra compte à sa prochaine requête
            }
        }

        return $token;
    }

    /** Ce jeton est-il celui de la partie active du compte ? (Pas de partie connue : on l'accepte.) */
    public static function isCurrent(User $user, string $token): bool
    {
        $current = Cache::get(self::key($user->id));
        if (! is_array($current)) {
            return true;
        }

        return hash_equals((string) $current['token'], $token);
    }
}
