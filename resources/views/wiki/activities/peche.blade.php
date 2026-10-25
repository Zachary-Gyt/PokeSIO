@extends('wiki.layout')

@section('title', 'La pêche')

{{-- Wiki : activité Pêche (WikiGuideController::activity('peche')) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>La pêche</h1>
        <p class="lead">Avec une canne, on attrape des Pokémon qu'on ne voit jamais dans les hautes herbes.</p>

        <h2>Comment pêcher</h2>
        <ol>
            <li>Placez-vous <strong>face à l'eau</strong>.</li>
            <li>Utilisez une canne depuis le Sac, ou appuyez sur <strong>F</strong> (la meilleure canne que vous avez).</li>
            <li>Attendez ({{ $wait[0] / 1000 }} à {{ $wait[1] / 1000 }} secondes)... Quand « Oh ! Ça mord ! » s'affiche, appuyez sur <strong>Action</strong> en moins de {{ $reaction / 1000 }} s !</li>
            <li>Trop tôt ou trop tard : le Pokémon s'en va. Recommencez !</li>
        </ol>
        <p>L'Appât Ball (×5) et la Scuba Ball (×3,5) sont faites pour les Pokémon pêchés.</p>

        <h2>Les cannes</h2>
        @foreach ($rods as $rod)
            <h3>{{ $rod['name'] }} <span class="muted">— ça mord {{ $rod['bite'] }} % du temps</span></h3>
            @if ($rod['species']->isNotEmpty())
                <p>
                    @foreach ($rod['species'] as $species)
                        <a href="{{ route('wiki.pokemon', $species->slug) }}" title="{{ $species->name }}"><img class="mon-icon" src="{{ $species->sprite('icon') }}" alt="" loading="lazy">{{ $species->name }}</a>@if (! $loop->last), @endif
                    @endforeach
                </p>
            @else
                <p class="muted">Pas encore de Pokémon pour cette canne dans le monde.</p>
            @endif
        @endforeach
        <p class="muted">Le détail par lieu (niveaux, taux) est dans la fiche de chaque Pokémon.</p>
    </div>
@endsection
