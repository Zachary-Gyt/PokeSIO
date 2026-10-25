@extends('wiki.layout')

@section('title', 'Jour et nuit')

{{-- Wiki : activité Jour et nuit (WikiGuideController::activity('jour-nuit')) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Jour et nuit</h1>
        <p class="lead">Le jeu suit l'heure réelle (heure de Paris). Les couleurs du monde, les Pokémon sauvages et certaines évolutions en dépendent.</p>

        @php $names = ['morning' => 'Matin', 'day' => 'Jour', 'night' => 'Nuit']; @endphp
        <p>En ce moment : <strong>{{ sprintf('%02d h %02d', $clock['hour'], $clock['minute']) }}</strong>, c'est le <strong>{{ strtolower($names[$clock['period']] ?? $clock['period']) }}</strong>.</p>

        <h2>Les moments de la journée</h2>
        <table class="wiki-table">
            <tr><th>Moment</th><th>Heures</th></tr>
            <tr><td>Matin</td><td>{{ $times['morning'] }} h à {{ $times['day'] }} h</td></tr>
            <tr><td>Jour</td><td>{{ $times['day'] }} h à {{ $times['night'] }} h</td></tr>
            <tr><td>Nuit</td><td>{{ $times['night'] }} h à {{ $times['morning'] }} h</td></tr>
        </table>
        <p>Comme dans Diamant, Perle et Platine. Pour les évolutions, le matin compte comme le jour. La Sombre Ball est ×3 la nuit (et dans les grottes).</p>

        <h2>Les Pokémon selon l'heure</h2>
        @forelse ($timed as $period => $list)
            <h3>{{ $names[$period] ?? $period }}</h3>
            <p>
                @foreach ($list as $species)
                    <a href="{{ route('wiki.pokemon', $species->slug) }}"><img class="mon-icon" src="{{ $species->sprite('icon') }}" alt="" loading="lazy">{{ $species->name }}</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        @empty
            <p class="muted">Pour l'instant, tous les Pokémon sauvages sortent à toute heure.</p>
        @endforelse
        <p class="muted">Les lieux et heures exactes sont dans la fiche de chaque Pokémon (colonne « Quand »).</p>

        <h2>Les évolutions selon l'heure</h2>
        <table class="wiki-table">
            <tr><th>Pokémon</th><th>Devient</th><th>Condition</th></tr>
            @foreach ($evolutions as $e)
                <tr>
                    <td><a href="{{ route('wiki.pokemon', $e->from->slug) }}"><img class="mon-icon" src="{{ $e->from->sprite('icon') }}" alt="" loading="lazy">{{ $e->from->name }}</a></td>
                    <td><a href="{{ route('wiki.pokemon', $e->to->slug) }}"><img class="mon-icon" src="{{ $e->to->sprite('icon') }}" alt="" loading="lazy">{{ $e->to->name }}</a></td>
                    <td>{{ ucfirst($e->conditionLabel()) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endsection
