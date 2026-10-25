<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Création de compte.
 */
class RegisterController extends Controller
{
    /** Affiche le formulaire d'inscription */
    public function create()
    {
        return view('auth.register');
    }

    /** Traite le formulaire d'inscription */
    public function store(Request $request)
    {
        // validate() renvoie UNIQUEMENT les champs validés : impossible d'injecter autre chose
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'], // "confirmed" = doit correspondre à password_confirmation
        ], [
            'name.required' => 'Choisis un pseudo.',
            'name.max' => 'Ton pseudo ne doit pas dépasser 50 caractères.',
            'email.required' => 'L\'adresse e-mail est obligatoire.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
            'email.lowercase' => 'L\'adresse e-mail doit être en minuscules.',
            'email.unique' => 'Un compte existe déjà avec cette adresse e-mail.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ]);

        // Le mot de passe est haché automatiquement grâce au cast "hashed" du modèle User
        $user = User::create($data);

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate(); // nouvel identifiant de session : protège contre la fixation de session

        return redirect()->route('dashboard');
    }
}
