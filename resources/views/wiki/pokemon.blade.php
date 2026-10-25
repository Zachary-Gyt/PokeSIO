@extends('wiki.layout')

@section('title', $species->name)

@php
    $statLabels = ['hp' => 'PV', 'attack' => 'Attaque', 'defense' => 'Défense', 'sp_attack' => 'Atq. Spé.', 'sp_defense' => 'Déf. Spé.', 'speed' => 'Vitesse'];
    $growth = ['fast' => 'Rapide', 'medium_fast' => 'Moyenne', 'medium_slow' => 'Parabolique', 'slow' => 'Lente', 'erratic' => 'Erratique', 'fluctuating' => 'Fluctuante'];
    $categories = ['physical' => 'Physique', 'special' => 'Spéciale', 'status' => 'Statut'];
    $methods = ['grass' => 'Hautes herbes', 'cave' => 'Grotte', 'surf' => 'Surf', 'old_rod' => 'Canne', 'good_rod' => 'Super Canne', 'super_rod' => 'Méga Canne', 'headbutt' => 'Coup d\'Boule', 'rock_smash' => 'Éclate-Roc'];
    $gender = $species->gender_rate < 0 ? 'Asexué' : str_replace('.', ',', (string) (100 - $species->gender_rate * 12.5)) . ' % mâle, ' . str_replace('.', ',', (string) ($species->gender_rate * 12.5)) . ' % femelle';
    $types = array_filter([$species->primaryType, $species->secondaryType]);
@endphp

@push('styles')
<style>
    .mon-head { display: grid; grid-template-columns: 200px 1fr; gap: 16px; align-items: start; }
    .mon-head img { width: 192px; height: 192px; background: #f0f0e8; border: 2px solid #d8d8d0; }
    .mon-head h1 { margin: 0; }
    .mon-head .num { color: #909098; }
    .facts { display: grid; grid-template-columns: auto 1fr; gap: 0 14px; margin-top: 8px; }
    .facts dt { color: #909098; }
    .facts dd { margin: 0; }
    .entry { margin-top: 8px; font-family: var(--font-small); font-size: 20px; }
    .stats { display: grid; grid-template-columns: 110px 46px 1fr; gap: 2px 10px; align-items: center; }
    .bar { height: 12px; background: #e0e0d8; border: 2px solid #c0c0b8; }
    .bar span { display: block; height: 100%; }
    table.wiki-table { width: 100%; border-collapse: collapse; font-size: 20px; }
    .wiki-table th { text-align: left; color: #909098; font-weight: normal; border-bottom: 2px solid #d8d8d0; padding: 2px 6px; }
    .wiki-table td { padding: 2px 6px; border-bottom: 1px solid #ecece4; }
    .evo { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; }
    .evo a { display: flex; flex-direction: column; align-items: center; color: var(--ink) !important; text-decoration: none; }
    .evo img { width: 96px; height: 96px; }
    .evo .arrow { color: #909098; text-align: center; font-size: 20px; }
    .nav-dex { display: flex; justify-content: space-between; margin-bottom: 8px; }
    .evo-family { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
    .evo-stage { display: grid; gap: 6px; }
    .evo-stage.many { grid-template-columns: repeat(2, auto); }   /* Évoli : ses évolutions sur deux colonnes */
    .evo-cell { display: flex; align-items: center; gap: 6px; }
    .evo-cell .arrow { width: 150px; }
    .evo-cell a { display: flex; flex-direction: column; align-items: center; color: var(--ink) !important; text-decoration: none; min-width: 96px; }
    .evo-cell a.current { font-weight: bold; }
    .evo-cell img { width: 96px; height: 96px; }
    .evo-cell .arrow { color: #909098; font-size: 18px; max-width: 150px; text-align: center; }
    @media (max-width: 620px) { .mon-head { grid-template-columns: 1fr; } }
</style>
@endpush

@section('wiki')
    <div class="nav-dex">
        <span>@if ($previous) <a class="btn secondary" href="{{ route('wiki.pokemon', $previous->slug) }}">← {{ $previous->name }}</a> @endif</span>
        <a class="btn secondary" href="{{ route('wiki.pokedex') }}">Pokédex</a>
        <span>@if ($next) <a class="btn secondary" href="{{ route('wiki.pokemon', $next->slug) }}">{{ $next->name }} →</a> @endif</span>
    </div>

    <div class="box">
        <div class="mon-head">
            @if ($species->sprite('front'))
                <img class="px" src="{{ $species->sprite('front') }}" alt="{{ $species->name }}">
            @endif
            <div>
                <span class="num">N° {{ str_pad($species->pokedex_number, 3, '0', STR_PAD_LEFT) }}</span>
                <h1>{{ $species->name }}</h1>
                <div>
                    @foreach ($types as $type)
                        <span class="type-badge" style="background: {{ $type->color }}">{{ $type->name }}</span>
                    @endforeach
                </div>
                <dl class="facts">
                    @if ($species->category) <dt>Catégorie</dt><dd>{{ $species->category }}</dd> @endif
                    <dt>Taille</dt><dd>{{ number_format($species->height / 10, 1, ',', ' ') }} m</dd>
                    <dt>Poids</dt><dd>{{ number_format($species->weight / 10, 1, ',', ' ') }} kg</dd>
                    <dt>Sexe</dt><dd>{{ $gender }}</dd>
                    <dt>Taux de capture</dt><dd>{{ $species->capture_rate }}</dd>
                    <dt>Croissance</dt><dd>{{ $growth[$species->growth_rate] ?? $species->growth_rate }}</dd>
                    {{-- Étape 18 : la Pension --}}
                    @if ($species->egg_groups)
                        <dt>Groupes d'Œuf</dt><dd><a href="{{ route('wiki.daycare') }}#groupes">{{ collect($species->eggGroups())->map(fn ($g) => config("breeding.groups.{$g}", $g))->join(', ') }}</a></dd>
                        @unless (in_array('no-eggs', $species->eggGroups(), true) || in_array('ditto', $species->eggGroups(), true))
                            <dt>Éclosion</dt><dd>{{ number_format($species->hatch_cycles * config('breeding.steps_per_cycle', 256), 0, ',', ' ') }} pas</dd>
                        @endunless
                    @endif
                </dl>
                @if ($species->pokedex_entry) <p class="entry">{{ $species->pokedex_entry }}</p> @endif
            </div>
        </div>

        {{-- Étape 23 : les talents agissent en combat ; leur description --}}
        <h2>Talents</h2>
        <table class="wiki-table">
            @foreach ($species->abilities as $ability)
                <tr><td style="white-space:nowrap"><strong>{{ $ability->name }}</strong>@if ($ability->pivot->is_hidden) <small style="color:#909098">(caché)</small>@endif</td>
                    <td>{{ \App\Services\PokemonPresenter::clean($ability->description) }}</td></tr>
            @endforeach
        </table>

        <h2>Statistiques de base</h2>
        <div class="stats">
            @php $total = 0; @endphp
            @foreach ($statLabels as $key => $label)
                @php $value = $species->{'base_' . $key}; $total += $value; @endphp
                <span>{{ $label }}</span><span style="text-align:right">{{ $value }}</span>
                <div class="bar"><span style="width: {{ min(100, $value / 1.8) }}%; background: {{ $value >= 100 ? '#40a848' : ($value >= 60 ? '#f8c030' : '#e05838') }}"></span></div>
            @endforeach
            <span>Total</span><span style="text-align:right">{{ $total }}</span><span></span>
        </div>

        {{-- Étape 23 : toute la famille d'évolution (bébés, évolutions à plusieurs branches comme Évoli) --}}
        @if ($family)
            <h2>Évolutions</h2>
            <div class="evo-family">
                @foreach ($family as $i => $stage)
                    <div class="evo-stage {{ count($stage) > 3 ? 'many' : '' }}">
                        @foreach ($stage as $cell)
                            <div class="evo-cell">
                                @if ($cell['evolution'])
                                    <span class="arrow">→ {{ \App\Http\Controllers\WikiController::evolutionLabel($cell['evolution']) }}</span>
                                @endif
                                <a href="{{ route('wiki.pokemon', $cell['species']->slug) }}" class="{{ $cell['species']->id === $species->id ? 'current' : '' }}">
                                    <img class="px" src="{{ $cell['species']->sprite('front') }}" alt="" loading="lazy">{{ $cell['species']->name }}
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        <h2>Où le trouver</h2>
        @if ($locations->isEmpty())
            <p style="margin:0">Introuvable à l'état sauvage pour l'instant (évolution, échange ou Pokémon de départ).</p>
        @else
            <table class="wiki-table">
                <tr><th>Lieu</th><th>Niveaux</th><th>Comment</th><th>Quand</th></tr>
                @foreach ($locations as $location)
                    <tr><td>{{ $location['name'] }}</td><td>{{ $location['levels'] }}</td>
                        <td>{{ collect($location['methods'])->map(fn ($m) => $methods[$m] ?? $m)->join(', ') }}</td>
                        <td>{{ $location['times'] }}</td></tr>
                @endforeach
            </table>
        @endif

        <h2>Capacités apprises par niveau</h2>
        <table class="wiki-table">
            <tr><th>N.</th><th>Capacité</th><th>Type</th><th>Catégorie</th><th>Puiss.</th><th>Préc.</th><th>PP</th></tr>
            @foreach ($levelMoves as $move)
                <tr>
                    <td>{{ $move->pivot->level ?: 'Évo.' }}</td><td>{{ $move->name }}</td>
                    <td>@if ($move->type)<span class="type-badge" style="background: {{ $move->type->color }}">{{ $move->type->name }}</span>@endif</td>
                    <td>{{ $categories[$move->category] ?? $move->category }}</td>
                    <td>{{ $move->power ?? '—' }}</td><td>{{ $move->accuracy ?? '—' }}</td><td>{{ $move->pp }}</td>
                </tr>
            @endforeach
        </table>

        {{-- Étape 23 : capacités Œuf (transmises par les parents à la Pension) --}}
        @if ($eggMoves->isNotEmpty())
            <h2>Capacités Œuf</h2>
            <p style="margin:0 0 4px">Le bébé les connaît si un parent les connaît (voir la <a href="{{ route('wiki.daycare') }}">Pension</a>).</p>
            <table class="wiki-table">
                <tr><th>Capacité</th><th>Type</th><th>Catégorie</th><th>Puiss.</th><th>Préc.</th><th>PP</th></tr>
                @foreach ($eggMoves as $move)
                    <tr>
                        <td>{{ $move->name }}</td>
                        <td>@if ($move->type)<span class="type-badge" style="background: {{ $move->type->color }}">{{ $move->type->name }}</span>@endif</td>
                        <td>{{ $categories[$move->category] ?? $move->category }}</td>
                        <td>{{ $move->power ?? '—' }}</td><td>{{ $move->accuracy ?? '—' }}</td><td>{{ $move->pp }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($tmMoves->isNotEmpty())
            <h2>Capacités apprises par CT / CS</h2>
            <table class="wiki-table">
                <tr><th>Capacité</th><th>Type</th><th>Catégorie</th><th>Puiss.</th><th>Préc.</th><th>PP</th></tr>
                @foreach ($tmMoves as $move)
                    <tr>
                        <td>{{ $move->name }}</td>
                        <td>@if ($move->type)<span class="type-badge" style="background: {{ $move->type->color }}">{{ $move->type->name }}</span>@endif</td>
                        <td>{{ $categories[$move->category] ?? $move->category }}</td>
                        <td>{{ $move->power ?? '—' }}</td><td>{{ $move->accuracy ?? '—' }}</td><td>{{ $move->pp }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
