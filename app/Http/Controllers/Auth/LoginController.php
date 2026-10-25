<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connexion et déconnexion.
 */
class LoginController extends Controller
{
    /** Nombre d'essais ratés autorisés avant blocage temporaire */
    private const MAX_ATTEMPTS = 5;

    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'L\'adresse e-mail est obligatoire.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ]);

        // Anti force brute : 5 essais par couple (e-mail, adresse IP), puis attente
        $throttleKey = Str::lower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => "Trop de tentatives. Réessaie dans {$seconds} secondes.",
            ]);
        }

        // Auth::attempt vérifie le mot de passe haché et connecte l'utilisateur si tout est bon
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                // Message volontairement vague : on ne dit pas si c'est l'e-mail ou le mot de passe qui est faux
                'email' => 'Adresse e-mail ou mot de passe incorrect.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        // intended() renvoie vers la page que l'utilisateur voulait voir avant d'être redirigé vers la connexion
        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
