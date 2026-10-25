@extends('wiki.layout')

@section('title', 'Évolutions')

{{-- Wiki : toutes les évolutions des Pokémon du jeu, par méthode (WikiReferenceController::evolutions) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Évolutions</h1>
        <p class="lead">Un Pokémon évolue quand il remplit une condition : un niveau, une pierre, son Bonheur, l'heure, un objet tenu, un lieu...
            L'écran d'évolution s'ouvre tout seul ; appuyez sur Retour pendant l'animation pour l'annuler. Un Pokémon qui tient une <strong>Pierre Stase</strong> n'évolue pas.</p>
        <ul>
            <li><strong>Montée de niveau</strong> : la plupart des conditions (Bonheur, jour / nuit, objet tenu, capacité, lieu, Pokémon dans l'équipe) sont vérifiées quand le Pokémon <strong>gagne un niveau</strong> (combat ou Super Bonbon).</li>
            <li><strong>Bonheur</strong> : il faut au moins 160 (220 pour certains bébés). Voir <a href="{{ route('wiki.battle') }}#bonheur">le Bonheur</a>.</li>
            <li><strong>Jour / nuit</strong> : le matin compte comme le jour. Voir <a href="{{ route('wiki.activity', 'jour-nuit') }}">Jour et nuit</a>.</li>
            <li><strong>Objet tenu</strong> : l'objet est consommé par l'évolution.</li>
            <li><strong>Lieux spéciaux</strong> : {{ collect($areas)->map(fn ($text, $key) => $text)->join(' ; ') }} (dans les cartes concernées).</li>
            <li><strong>Échange</strong> : l'évolution a lieu dès la fin de l'échange avec un autre joueur.</li>
        </ul>

        @foreach ($sections as $title => $list)
            <h2>{{ $title }}</h2>
            <table class="wiki-table">
                <tr><th>Pokémon</th><th></th><th>Évolution</th><th>Condition</th></tr>
                @foreach ($list as $e)
                    <tr>
                        <td><a href="{{ route('wiki.pokemon', $e->from->slug) }}"><img class="mon-icon" src="{{ $e->from->sprite('icon') }}" alt="" loading="lazy">{{ $e->from->name }}</a></td>
                        <td class="muted">→</td>
                        <td><a href="{{ route('wiki.pokemon', $e->to->slug) }}"><img class="mon-icon" src="{{ $e->to->sprite('icon') }}" alt="" loading="lazy">{{ $e->to->name }}</a></td>
                        <td>{{ \App\Http\Controllers\WikiController::evolutionLabel($e) }}</td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    </div>
@endsection
