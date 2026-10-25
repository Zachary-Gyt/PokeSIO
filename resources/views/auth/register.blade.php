@extends('layouts.app')

@section('title', 'Inscription')

@section('content')
    <div class="box">
        <h1>Nouvelle partie</h1>
        <p class="lead">Crée ton compte pour commencer l'aventure.</p>

        <form method="POST" action="{{ route('register') }}">
            @csrf {{-- Jeton anti-CSRF : obligatoire dans tout formulaire POST de Laravel --}}

            <label for="name">Pseudo</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="nickname">
            @error('name') <div class="error">{{ $message }}</div> @enderror

            <label for="email">Adresse e-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            @error('email') <div class="error">{{ $message }}</div> @enderror

            <label for="password">Mot de passe</label>
            <input id="password" type="password" name="password" required autocomplete="new-password">
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label for="password_confirmation">Confirme le mot de passe</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

            <button type="submit" class="btn block">Créer mon compte</button>
        </form>

        <div class="below">
            Déjà un compte ? <a href="{{ route('login') }}">Se connecter</a>
        </div>
    </div>
@endsection
