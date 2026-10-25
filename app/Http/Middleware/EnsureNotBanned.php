<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte banni est déconnecté à sa prochaine requête, et renvoyé vers la page de connexion
 * avec le motif et la durée. Les appels du jeu (JSON) reçoivent une erreur 403.
 */
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBanned()) {
            $message = 'Ce compte est banni ' . $user->banEndText() . '.' . ($user->ban_reason ? ' Motif : ' . $user->ban_reason : '');

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'banned' => true], 403);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
