@extends('wiki.layout')

@section('title', 'Panthéon')

{{-- Étape 27 : le Panthéon (HallOfFameController::wiki) : les équipes qui ont battu la Ligue Pokémon --}}

@push('styles')
<style>
    .hof-entry { border: 3px solid #d8b848; background: #fffbea; padding: 8px 12px; margin: 10px 0; }
    .hof-entry h3 { margin: 0 0 6px; font-size: 24px; display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
    .hof-entry h3 .muted { font-size: 19px; }
    .hof-team { display: flex; flex-wrap: wrap; gap: 6px; }
    .hof-mon { display: flex; flex-direction: column; align-items: center; width: 92px; font-size: 17px; text-align: center; line-height: 1.1; }
    .hof-mon img { width: 72px; height: 72px; object-fit: contain; image-rendering: pixelated; }
    .hof-mon .shiny { color: #d0a000; }
</style>
@endpush

@section('wiki')
    <div class="box wiki-text">
        <h1>Panthéon</h1>
        <p class="lead">Les dresseurs qui ont vaincu le Conseil 4 et le Maître de la Ligue Pokémon. Chaque équipe y est gravée telle qu'elle était le jour de la victoire.</p>

        @if ($champions->isNotEmpty())
            <h2>Les premiers champions</h2>
            <table class="wiki-table">
                <tr><th>#</th><th>Dresseur</th><th>Première victoire</th><th>Victoires</th></tr>
                @foreach ($champions as $i => $c)
                    <tr><td>{{ $i + 1 }}</td><td>{{ $c->trainer_name }}</td><td>{{ \Illuminate\Support\Carbon::parse($c->first_at)->locale('fr')->isoFormat('D MMMM YYYY') }}</td><td>{{ $c->total }}</td></tr>
                @endforeach
            </table>
        @endif

        <h2>Dernières entrées</h2>
        @forelse ($entries as $entry)
            <div class="hof-entry">
                <h3>{{ $entry->trainer_name }} <span class="muted">entrée n° {{ $entry->number }} · {{ $entry->entered_at?->locale('fr')->isoFormat('D MMMM YYYY') }} · {{ intdiv($entry->play_time, 3600) }} h {{ sprintf('%02d', intdiv($entry->play_time % 3600, 60)) }} de jeu</span></h3>
                <div class="hof-team">
                    @foreach ($entry->team as $mon)
                        <a class="hof-mon" href="{{ route('wiki.pokemon', $mon['slug']) }}" title="{{ $mon['speciesName'] }}">
                            <img src="{{ $mon['sprite'] }}" alt="" loading="lazy">
                            <span @class(['shiny' => $mon['shiny']])>{{ $mon['name'] }}@if ($mon['shiny']) ★@endif</span>
                            <span class="muted">N. {{ $mon['level'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="muted">Personne n'est encore entré au Panthéon. Le premier sera peut-être toi !</p>
        @endforelse

        {{ $entries->links() }}
    </div>
@endsection
