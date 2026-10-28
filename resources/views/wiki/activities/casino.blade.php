@extends('wiki.layout')

@section('title', 'Le Casino')

{{-- Wiki : activité Casino (WikiGuideController::activity('casino')) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Le Casino</h1>
        <p class="lead">Machines à sous, roulettes, et des lots rares à gagner avec les jetons.</p>

        <h2>Les jetons</h2>
        <ul>
            <li>Il faut une <strong>Boîte Jetons</strong> pour jouer. Elle contient {{ number_format($max, 0, ',', ' ') }} jetons au plus.</li>
            <li>Au guichet, achetez exactement le nombre de jetons voulu : <strong>{{ number_format($price, 0, ',', ' ') }} ₽ le jeton</strong>.</li>
            @if ($sellPrice > 0)
                <li>Le guichet rachète aussi vos jetons : <strong>{{ number_format($sellPrice, 0, ',', ' ') }} ₽ le jeton</strong>.</li>
            @else
                <li>Les jetons ne se revendent pas : on les échange contre des lots aux comptoirs.</li>
            @endif
        </ul>

        <h2>Les machines à sous</h2>
        <p>Misez 1, 2 ou 3 jetons (1, 3 ou 5 lignes jouées), puis arrêtez les trois rouleaux. Certaines machines sont plus généreuses que d'autres...</p>
        @php $symbols = ['cherry' => '1 Cerise', 'two_cherries' => '2 Cerises', 'magnemite' => '3 Magnéti', 'shellder' => '3 Kokiyas', 'pikachu' => '3 Pikachu',
            'psyduck' => '3 Psykokwak', 'mixed_seven' => '3 « 7 » de deux couleurs', 'seven' => '3 « 7 » rouges ou 3 bleus']; @endphp
        <table class="wiki-table">
            <tr><th>Ligne</th><th>Gain (jetons)</th></tr>
            @foreach ($symbols as $key => $label)
                @isset($payouts[$key])<tr><td>{{ $label }}</td><td>{{ $payouts[$key] }}</td></tr>@endisset
            @endforeach
            <tr><td>3 REPLAY</td><td>Une partie gratuite</td></tr>
        </table>

        <h2>La roulette</h2>
        <p>Le plateau a 12 cases : 4 Pokémon × 3 couleurs. Pariez sur une case, un Pokémon (une colonne) ou une couleur (une ligne). Le gain dépend du nombre de cases <strong>encore libres</strong> dans votre choix : ×12 pour une case, ×4 pour un Pokémon au début... Une balle ne retombe jamais sur une case déjà sortie ; le plateau est vidé après {{ $rounds }} balles.</p>

        <h2>Les lots</h2>
        @foreach ($prizes as $counter)
            <h3>Comptoir {{ $counter['name'] }}</h3>
            <table class="wiki-table">
                <tr><th>Lot</th><th>Prix (jetons)</th></tr>
                @foreach ($counter['list'] as $prize)
                    <tr>
                        <td>
                            @if ($prize['kind'] === 'pokemon')
                                <img class="mon-icon" src="{{ $prize['icon'] }}" alt="" loading="lazy">{{ $prize['name'] }} <span class="muted">N. {{ $prize['level'] }}</span>
                            @else
                                <img src="{{ asset('game/items/' . $prize['icon'] . '.png') }}" alt="" width="24" height="24" loading="lazy"> {{ $prize['name'] }}
                            @endif
                        </td>
                        <td>{{ number_format($prize['price'], 0, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    </div>
@endsection
