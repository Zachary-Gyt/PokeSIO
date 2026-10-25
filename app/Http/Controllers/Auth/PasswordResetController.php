<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Mot de passe oublié : envoi d'un lien par e-mail, puis choix d'un nouveau mot de passe.
 *
 * En développement (MAIL_MAILER=log dans .env), l'e-mail n'est pas envoyé :
 * il est écrit dans storage/logs/laravel.log, d'où tu peux copier le lien.
 */
class PasswordResetController extends Controller
{
    /** Formulaire "j'ai oublié mon mot de passe" */
    public function create()
    {
        return view('auth.forgot-password');
    }

    /** Envoie le lien de réinitialisation */
    public function store(Request $request)
    {
        $request->validate(['email' => ['required', 'email']], [
            'email.required' => 'L\'adresse e-mail est obligatoire.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
        ]);

        Password::sendResetLink($request->only('email'));

        // Même message que le compte existe ou non : on ne révèle pas quelles adresses sont inscrites
        return back()->with('status', 'Si un compte existe avec cette adresse, un lien de réinitialisation vient d\'être envoyé.');
    }

    /** Formulaire du nouveau mot de passe (ouvert depuis le lien de l'e-mail) */
    public function edit(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /** Enregistre le nouveau mot de passe */
    public function update(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.required' => 'L\'adresse e-mail est obligatoire.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60), // déconnecte les sessions "se souvenir de moi"
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Ton mot de passe a été modifié. Tu peux te connecter.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Ce lien est invalide ou a expiré. Refais une demande.']);
    }
}
