@extends('wiki.layout')

@section('title', 'Le Parc Safari')

{{-- Wiki : activité Parc Safari (WikiGuideController::activity('safari')) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Le Parc Safari</h1>
        <p class="lead">Un parc plein de Pokémon rares. Ici, pas de combat : on les attrape à la main... ou plutôt à la Ball.</p>

        <h2>L'entrée</h2>
        <ul>
            <li>L'entrée coûte <strong>{{ number_format($safari['price'] ?? 500, 0, ',', ' ') }} ₽</strong>. Vous recevez <strong>{{ $safari['balls'] ?? 30 }} Safari Balls</strong> (bonus ×{{ ($safari['ball_bonus'] ?? 15) / 10 }}).</li>
            <li>Vous avez <strong>{{ $safari['steps'] ?? 500 }} pas</strong>. Quand ils sont finis, ou quand il n'y a plus de Balls, le haut-parleur vous ramène à l'entrée.</li>
            <li>Vos Pokémon ne combattent pas dans le Parc.</li>
        </ul>

        <h2>Face à un Pokémon</h2>
        <table class="wiki-table">
            <tr><th>Action</th><th>Effet</th></tr>
            <tr><td>Ball</td><td>Lance une Safari Ball.</td></tr>
            <tr><td>Appât</td><td>Le Pokémon mange 1 à 5 tours : il s'enfuit <strong>4 fois moins</strong>, mais il est 2 fois plus dur à attraper.</td></tr>
            <tr><td>Caillou</td><td>Le Pokémon se fâche 1 à 5 tours : il est <strong>2 fois plus facile</strong> à attraper, mais il s'enfuit 2 fois plus.</td></tr>
            <tr><td>Fuite</td><td>Vous quittez la rencontre.</td></tr>
        </table>
        <p>Après chacune de vos actions, le Pokémon peut s'enfuir : plus il est <strong>rapide</strong>, plus il risque de partir ({{ str_replace('.', ',', $safari['flee_per_speed'] ?? 0.4) }} % par point de Vitesse, 90 % au plus). Ce sont les règles de Rouge Feu et Vert Feuille.</p>
        <p class="muted">Les Pokémon du Parc sont listés dans la fiche de chaque Pokémon (lieu « Parc Safari »).</p>
    </div>
@endsection
