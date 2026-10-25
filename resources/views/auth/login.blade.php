@extends('layouts.app')

@section('title', 'Connexion')

@section('content')
    <div class="box">
        <h1>Continuer</h1>
        <p class="lead">Content de te revoir, Dresseur !</p>

        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <label for="email">Adresse e-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            @error('email') <div class="error">{{ $message }}</div> @enderror

            <label for="password">Mot de passe</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label class="checkbox">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                Se souvenir de moi
            </label>

            <button type="submit" class="btn block">Se connecter</button>
        </form>

        <div class="below">
            <a href="{{ route('password.request') }}">Mot de passe oublié ?</a><br><br>
            Pas encore de compte ? <a href="{{ route('register') }}">Nouvelle partie</a>
        </div>
    </div>
@endsection
