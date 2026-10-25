@extends('wiki.layout')

@section('title', 'Les Baies')

{{-- Wiki : activité Baies (WikiGuideController::activity('baies')) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Les Baies</h1>
        <p class="lead">Des buissons à Baies poussent un peu partout. Approchez-vous et appuyez sur Action pour cueillir.</p>

        <h2>Comment ça marche</h2>
        <ul>
            <li>Chaque joueur a <strong>ses propres buissons</strong> : ce que vous cueillez ne manque pas aux autres.</li>
            <li>Un buisson donne <strong>{{ $yield[0] }} à {{ $yield[1] }} Baies</strong>. Ensuite il repousse en <strong>{{ $hours }} h</strong> de temps réel, en 5 étapes comme dans Rubis et Saphir : plantée, a germé, grandit, en fleurs, puis de nouvelles Baies.</li>
            <li>Les Baies vont dans la poche <strong>Baies</strong> du Sac. Données à tenir, elles servent toutes seules en combat (PV bas, statut...). Certaines se mangent hors combat pour soigner ou baisser les EV.</li>
        </ul>

        <h2>Les Baies et où les trouver</h2>
        <table class="wiki-table">
            <tr><th></th><th>Baie</th><th>Effet</th><th>Buissons</th></tr>
            @foreach ($berries as $berry)
                <tr>
                    <td><img src="{{ $berry['icon'] }}" alt="" width="24" height="24" loading="lazy"></td>
                    <td>{{ $berry['name'] }}</td>
                    <td>{{ $berry['description'] }}</td>
                    <td>@if ($berry['places']){{ implode(', ', $berry['places']) }}@else<span class="muted">—</span>@endif</td>
                </tr>
            @endforeach
        </table>
    </div>
@endsection
