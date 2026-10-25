@extends('wiki.layout')

@section('title', $move->name)

{{-- Wiki : une capacité et les Pokémon qui l'apprennent (WikiReferenceController::move) --}}

@section('wiki')
    @php
        $categories = ['physical' => 'Physique', 'special' => 'Spéciale', 'status' => 'Statut'];
        $methods = ['level_up' => 'Par niveau', 'tm' => 'Par CT / CS', 'egg' => 'Capacité Œuf (Pension)', 'tutor' => 'Par un Maître des capacités'];
    @endphp
    <div style="margin-bottom:8px"><a class="btn secondary" href="{{ route('wiki.moves') }}">← Capacités</a></div>
    <div class="box wiki-text">
        <h1>{{ $move->name }}</h1>
        <p>
            @if ($move->type)<span class="type-badge" style="background: {{ $move->type->color }}">{{ $move->type->name }}</span>@endif
            {{ $categories[$move->category] ?? $move->category }}
            · Puissance {{ $move->power ?? '—' }} · Précision {{ $move->accuracy ?? '—' }} · PP {{ $move->pp }}
            @if ($move->priority) · Priorité {{ $move->priority > 0 ? '+' : '' }}{{ $move->priority }} @endif
            @if ($move->makes_contact) · Contact @endif
        </p>
        @if ($move->description)<p>{{ \App\Services\PokemonPresenter::clean($move->description) }}</p>@endif

        @forelse ($learners as $method => $list)
            <h2>{{ $methods[$method] ?? $method }}</h2>
            <p>
                @foreach ($list as $s)
                    <a href="{{ route('wiki.pokemon', $s->slug) }}" title="{{ $s->name }}" style="white-space:nowrap; margin-right:8px">
                        <img class="mon-icon" src="{{ $s->sprite('icon') }}" alt="" loading="lazy">{{ $s->name }}@if ($method === 'level_up') <span class="muted">(N. {{ $s->pivot->level ?: 'Évo.' }})</span>@endif</a>
                @endforeach
            </p>
        @empty
            <p>Aucun Pokémon du jeu n'apprend cette capacité pour l'instant.</p>
        @endforelse
    </div>
@endsection
