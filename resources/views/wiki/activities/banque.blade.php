@extends('wiki.layout')

@section('title', 'Banque')

{{-- Wiki : la Banque (WikiGuideController::activity('banque')) ; réglages dans config/bank.php --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Banque</h1>
        <p class="lead">Mettez votre argent à l'abri et laissez-le grossir : l'argent déposé à la Banque Pokémon rapporte <strong>{{ $percent }} % par jour</strong>.</p>

        <h2>Où la trouver ?</h2>
        <p>Parlez à un <strong>banquier</strong>, ou utilisez un <strong>distributeur</strong>. Il y en a un dans le hall de la Ligue Pokémon, par exemple.</p>
        <ul>
            <li><strong>Déposer</strong> : l'argent passe de votre porte-monnaie à votre compte.</li>
            <li><strong>Retirer</strong> : l'argent revient dans votre porte-monnaie (qui ne peut pas dépasser {{ number_format($maxMoney, 0, ',', ' ') }} ₽).</li>
            <li>Pour la somme, choisissez <strong>Tout</strong>, ou <strong>Autre somme</strong> : gauche / droite pour changer de chiffre, haut / bas pour le modifier.</li>
        </ul>

        <h2>Les intérêts</h2>
        <ul>
            <li>Le compte rapporte <strong>{{ $percent }} % par jour</strong>, compté en continu : même quelques heures rapportent un peu.</li>
            <li>Les intérêts sont <strong>composés</strong> : ils rapportent eux aussi des intérêts.</li>
            <li>Le temps compte <strong>même quand vous n'êtes pas connecté</strong>. À votre prochaine visite, le banquier vous dit combien vous avez gagné.</li>
            <li>Un compte ne peut pas dépasser <strong>{{ number_format($maxBalance, 0, ',', ' ') }} ₽</strong>. Une fois plein, il ne rapporte plus rien.</li>
        </ul>

        <h2>Exemple : 10 000 ₽ déposés</h2>
        <table class="wiki-table">
            <tr><th>Après</th><th>Solde</th></tr>
            @foreach ($examples as $days => $amount)
                <tr><td>{{ $days }} jour{{ $days > 1 ? 's' : '' }}</td><td>{{ number_format($amount, 0, ',', ' ') }} ₽</td></tr>
            @endforeach
        </table>
    </div>
@endsection
