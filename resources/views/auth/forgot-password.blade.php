@extends('layouts.app')

@section('title', 'Mot de passe oublié')

@section('content')
    <div class="box">
        <h1>Mot de passe oublié</h1>
        <p class="lead">Indique ton adresse e-mail : on t'envoie un lien pour choisir un nouveau mot de passe.</p>

        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <label for="email">Adresse e-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            @error('email') <div class="error">{{ $message }}</div> @enderror

            <button type="submit" class="btn block">Envoyer le lien</button>
        </form>

        <div class="below">
            <a href="{{ route('login') }}">Retour à la connexion</a>
        </div>
    </div>
@endsection
