@extends('layouts.app')

@section('title', $character->name)

@php
    $statLabels = ['hp' => 'PV', 'attack' => 'Attaque', 'defense' => 'Défense', 'sp_attack' => 'Att. Spé', 'sp_defense' => 'Déf. Spé', 'speed' => 'Vitesse'];
    $genderSymbols = ['male' => '♂', 'female' => '♀', 'genderless' => ''];
    $categoryLabels = ['physical' => 'Physique', 'special' => 'Spéciale', 'status' => 'Statut'];
    $pocketLabels = [
        'medicine' => 'Soins', 'balls' => 'Poké Balls', 'key_items' => 'Objets rares',
        'held_items' => 'Objets tenus', 'berries' => 'Baies', 'tms' => 'CT', 'misc' => 'Objets',
    ];
@endphp

@push('styles')
<style>
    .trainer-card {
        display: flex; flex-wrap: wrap; gap: 6px 22px; align-items: baseline;
        padding: 12px 14px; margin-bottom: 18px;
        background: #f1f4fa; border-radius: 0; font-size: 22px;
    }
    .trainer-card strong { font-family: var(--font); font-size: 18px; color: var(--muted); margin-right: 6px; }

    h2.section { font-family: var(--font); font-size: 20px; color: var(--blue); margin: 24px 0 12px; }

    .party { display: grid; gap: 14px; }
    .mon {
        display: grid; gap: 14px 20px;
        grid-template-columns: 120px 1fr;
        grid-template-areas: "art head" "art body";
        background: #fff; border: 3px solid #dfe3ea; border-radius: 0; padding: 14px;
    }
    .mon .art { grid-area: art; display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .mon .art img { width: 112px; height: 112px; }
    .mon .head { grid-area: head; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
    .mon .head .nm { font-family: var(--font); font-size: 20px; }
    .mon .head .lv { font-family: var(--font); font-size: 18px; color: var(--muted); }
    .mon .head .male { color: #2f6fdb;  }
    .mon .head .female { color: #e0457b;  }
    .shiny { color: #e6a700; font-size: 24px; }
    .mon .body { grid-area: body; display: grid; gap: 14px 22px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }

    .type { display: inline-block; padding: 2px 8px; border-radius: 0; color: #fff; font-size: 20px;  text-shadow: 0 1px 1px rgba(0,0,0,.35); }

    .hp { width: 100%; }
    .hp .bar { height: 8px; border-radius: 0; background: #e5e8ee; overflow: hidden; margin-top: 3px; }
    .hp .bar span { display: block; height: 100%; background: #3cb043; }
    .hp small, .xp small { font-size: 20px; color: var(--muted); }
    .xp .bar { height: 5px; border-radius: 0; background: #e5e8ee; overflow: hidden; margin-top: 3px; }
    .xp .bar span { display: block; height: 100%; background: #3d8bfd; }

    dl.info { display: grid; grid-template-columns: auto 1fr; gap: 3px 10px; margin: 0; font-size: 20px; }
    dl.info dt { color: var(--muted); }
    dl.info dd { margin: 0;  }

    table.stats { border-collapse: collapse; font-size: 20px; width: 100%; }
    table.stats th { text-align: left;  color: var(--muted); padding: 2px 8px 2px 0; }
    table.stats td { padding: 2px 6px; text-align: right; font-variant-numeric: tabular-nums; }
    table.stats td.val {  }
    table.stats td.iv { color: var(--muted); font-size: 20px; }
    .up { color: #d33c32; }
    .down { color: #2f6fdb; }

    .moves { display: grid; gap: 6px; grid-column: 1 / -1; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
    .move { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 0; background: #f6f7fa; font-size: 20px; }
    .move .mn {  }
    .move small { color: var(--muted); }

    .bag { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
    .pocket h3 { font-size: 20px; margin: 0 0 6px; }
    .pocket ul { list-style: none; padding: 0; margin: 0; font-size: 20px; }
    .pocket li { display: flex; align-items: center; gap: 6px; padding: 2px 0; }
    .pocket li img { width: 24px; height: 24px; }
    .pocket li .qty { margin-left: auto;  }

    .actions-bar { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; margin-top: 26px; }

    @media (max-width: 560px) {
        .mon { grid-template-columns: 1fr; grid-template-areas: "art" "head" "body"; }
    }
</style>
@endpush

@section('content')
    <div class="box wide" style="max-width: 900px">
        <h1>{{ $character->name }}</h1>

        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        <div class="trainer-card">
            <span><strong>ID</strong>{{ $character->trainer_id }}</span>
            <span><strong>ARGENT</strong>{{ number_format($character->money, 0, ',', ' ') }} ₽</span>
            <span><strong>BADGES</strong>{{ $character->badges()->count() }}</span>
            <span><strong>POKÉDEX</strong>{{ $character->pokedexEntries()->where('caught', true)->count() }}</span>
        </div>

        <h2 class="section">Équipe</h2>
        <div class="party">
            @foreach ($character->party as $pokemon)
                <article class="mon">
                    <div class="art">
                        <img class="px" src="{{ $pokemon->sprite('front') }}" alt="{{ $pokemon->display_name }}">
                        @if ($pokemon->caughtBall?->spriteUrl())
                            <img class="px" src="{{ $pokemon->caughtBall->spriteUrl() }}" alt="" title="{{ $pokemon->caughtBall->name }}" style="width:24px;height:24px">
                        @endif
                    </div>

                    <div class="head">
                        <span class="nm">{{ $pokemon->display_name }}</span>
                        <span class="lv">N.{{ $pokemon->level }}</span>
                        @if ($genderSymbols[$pokemon->gender])
                            <span class="{{ $pokemon->gender }}">{{ $genderSymbols[$pokemon->gender] }}</span>
                        @endif
                        @if ($pokemon->is_shiny) <span class="shiny" title="Chromatique">★</span> @endif
                        @foreach ($pokemon->species->types() as $type)
                            <span class="type" style="background: {{ $type->color }}">{{ $type->name }}</span>
                        @endforeach

                        <div class="hp">
                            <small>PV {{ $pokemon->current_hp }} / {{ $pokemon->max_hp }}</small>
                            <div class="bar"><span style="width: {{ $pokemon->hp_percent }}%"></span></div>
                        </div>
                        <div class="xp" style="width:100%">
                            <small>{{ number_format($pokemon->experience, 0, ',', ' ') }} XP · encore {{ number_format($pokemon->experienceToNextLevel(), 0, ',', ' ') }} pour le niveau suivant</small>
                            <div class="bar"><span style="width: {{ $pokemon->experiencePercent() }}%"></span></div>
                        </div>
                    </div>

                    <div class="body">
                        <dl class="info">
                            <dt>Espèce</dt><dd>{{ $pokemon->species->name }}</dd>
                            <dt>Nature</dt>
                            <dd>
                                {{ $pokemon->nature->name }}
                                @unless ($pokemon->nature->isNeutral())
                                    <small class="up">+{{ $statLabels[$pokemon->nature->increased_stat] }}</small>
                                    <small class="down">−{{ $statLabels[$pokemon->nature->decreased_stat] }}</small>
                                @endunless
                            </dd>
                            <dt>Talent</dt><dd title="{{ $pokemon->ability->description }}">{{ $pokemon->ability->name }}</dd>
                            <dt>Dresseur</dt><dd>{{ $pokemon->original_trainer_name }} ({{ $pokemon->original_trainer_id }})</dd>
                            <dt>Rencontré</dt><dd>{{ $pokemon->met_location ?? '—' }}, N.{{ $pokemon->met_level }}</dd>
                        </dl>

                        <table class="stats">
                            <thead>
                            <tr><th></th><td class="iv">valeur</td><td class="iv">IV</td></tr>
                            </thead>
                            <tbody>
                            @foreach ($pokemon->stats() as $stat => $value)
                                @php
                                    $mod = $stat === 'hp' ? 1.0 : $pokemon->nature->multiplierFor($stat);
                                @endphp
                                <tr>
                                    <th class="{{ $mod > 1 ? 'up' : ($mod < 1 ? 'down' : '') }}">{{ $statLabels[$stat] }}</th>
                                    <td class="val">{{ $value }}</td>
                                    <td class="iv">{{ $pokemon->{'iv_' . $stat} }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        <div class="moves">
                            @forelse ($pokemon->moves as $known)
                                <div class="move" title="{{ $known->move->description }}">
                                    <span>
                                        <span class="mn">{{ $known->move->name }}</span><br>
                                        <small>{{ $categoryLabels[$known->move->category] }}{{ $known->move->power ? ' · Puiss. ' . $known->move->power : '' }}</small>
                                    </span>
                                    <span style="text-align:right">
                                        <span class="type" style="background: {{ $known->move->type->color }}">{{ $known->move->type->name }}</span><br>
                                        <small>PP {{ $known->current_pp }}/{{ $known->max_pp }}</small>
                                    </span>
                                </div>
                            @empty
                                <small class="error">Aucune attaque</small>
                            @endforelse
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <h2 class="section">Sac</h2>
        <div class="bag">
            @forelse ($character->inventory->groupBy('item.pocket') as $pocket => $lines)
                <div class="pocket">
                    <h3>{{ $pocketLabels[$pocket] ?? $pocket }}</h3>
                    <ul>
                        @foreach ($lines as $line)
                            <li>
                                @if ($line->item->spriteUrl())
                                    <img class="px" src="{{ $line->item->spriteUrl() }}" alt="">
                                @endif
                                {{ $line->item->name }}
                                <span class="qty">×{{ $line->quantity }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="lead">Le sac est vide.</p>
            @endforelse
        </div>

        <div class="actions-bar">
            <a class="btn secondary" href="{{ route('dashboard') }}">Emplacements</a>
            <a class="btn" href="{{ route('game', $character) }}">Jouer</a>
        </div>
    </div>
@endsection
