@extends('wiki.layout')

@section('title', 'Tour de Combat')

{{-- Étape 28 : la Tour de Combat (WikiGuideController::tower). Les règles viennent de config/battle_tower.php. --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Tour de Combat</h1>
        <p class="lead">Enchaîne les victoires contre des dresseurs de plus en plus forts, comme dans Pokémon Platine. Jusqu'où ira ta série ?</p>

        <h2>Les règles</h2>
        <ul>
            <li>Choisis <strong>{{ $config['team_size'] }} Pokémon</strong> de ton équipe, d'espèces différentes, sans deux fois le même objet tenu.</li>
            @php $first = reset($levels); $last = end($levels); @endphp
            @if ($first === $last)
                <li>Tous les combats sont au <strong>niveau {{ $first }}</strong> : tes Pokémon plus forts y sont ramenés.</li>
            @else
                <li>Les combats commencent au <strong>niveau {{ $first }}</strong>, puis le niveau <strong>monte à chaque série</strong>, jusqu'au niveau {{ $last }}. Les adversaires sont à ce niveau, et tes Pokémon plus forts y sont ramenés.</li>
            @endif
            <li>Ils ne gagnent pas d'expérience, et rien ne change sur tes vrais Pokémon.</li>
            <li>Les Pokémon légendaires et fabuleux sont interdits.</li>
            <li>Les combats vont par <strong>séries de {{ $config['set_length'] }}</strong>. Ton équipe est soignée avant chaque combat. Les objets du Sac sont interdits.</li>
            <li>Entre deux combats, tu peux <strong>faire une pause</strong> : ta série est gardée. Une défaite, ou l'abandon, l'arrête.</li>
            <li>Chaque victoire rapporte <strong>{{ number_format($config['money']['base'], 0, ',', ' ') }} ₽</strong>, plus {{ $config['money']['per_win'] }} ₽ par victoire de la série en cours. Le dernier combat d'une série vaut double.</li>
            <li>Au <strong>{{ $config['tycoon']['silver_at'] }}e combat</strong>, puis au <strong>{{ $config['tycoon']['gold_at'] }}e</strong>, le Maître de la Tour, {{ $config['tycoon']['name'] }}, vient en personne.</li>
        </ul>

        @if (count($levels) > 1)
            <h2>Le niveau des combats</h2>
            <table class="wiki-table">
                <tr><th>À partir du combat</th><th>Niveau</th></tr>
                @foreach ($levels as $from => $level)
                    <tr><td>n° {{ $from }}</td><td>{{ $level }}</td></tr>
                @endforeach
            </table>
        @endif

        <h2>Les meilleures séries</h2>
        @if ($records->isEmpty())
            <p class="muted">Personne n'a encore gagné de combat à la Tour.</p>
        @else
            <table class="wiki-table">
                <tr><th>#</th><th>Dresseur</th><th>Série</th><th>Équipe</th><th>Le</th></tr>
                @foreach ($records as $i => $r)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $r->character?->name }}</td>
                        <td><strong>{{ $r->best_streak }}</strong></td>
                        <td>
                            @foreach ($r->best_team ?? [] as $mon)
                                <a href="{{ route('wiki.pokemon', $mon['slug']) }}" title="{{ $mon['speciesName'] }}"><img class="mon-icon" src="{{ $mon['icon'] }}" alt="" loading="lazy">{{ $mon['name'] }}</a>
                            @endforeach
                        </td>
                        <td>{{ $r->best_at?->locale('fr')->isoFormat('D MMM YYYY') }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
