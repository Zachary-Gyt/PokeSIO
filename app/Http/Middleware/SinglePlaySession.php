<?php

namespace App\Http\Middleware;

use App\Services\PlaySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pas de double connexion : une requête du jeu qui vient d'une partie remplacée (le compte a ouvert le jeu
 * ailleurs) est refusée avec le code 409. Seules les requêtes du jeu portent l'en-tête X-Play-Token ;
 * les pages normales (Wiki, accueil...) ne sont pas concernées.
 */
class SinglePlaySession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Play-Token');
        if ($token && $request->user() && ! PlaySession::isCurrent($request->user(), $token)) {
            return response()->json([
                'message' => 'Ce compte joue déjà dans une autre fenêtre ou sur un autre appareil.',
                'elsewhere' => true,
            ], 409);
        }

        return $next($request);
    }
}
