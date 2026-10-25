@extends('layouts.app')

@section('title', 'Nouveau mot de passe')

@section('content')
    <div class="box">
        <h1>Nouveau mot de passe</h1>

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">Adresse e-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">
            @error('email') <div class="error">{{ $message }}</div> @enderror

            <label for="password">Nouveau mot de passe</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="new-password">
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label for="password_confirmation">Confirme le mot de passe</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

            <button type="submit" class="btn block">Enregistrer</button>
        </form>
    </div>
@endsection
