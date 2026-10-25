@extends('wiki.layout')

@section('title', 'Types')

{{-- Wiki : la table des types (table type_efficacies, WikiReferenceController::types) --}}

@push('styles')
<style>
    .type-chart { border-collapse: collapse; font-size: 16px; }
    .type-chart th, .type-chart td { border: 1px solid #e0e0d8; text-align: center; width: 30px; height: 26px; padding: 0; }
    .type-chart th.att { text-align: right; padding-right: 4px; width: auto; white-space: nowrap; }
    .type-chart th.def { writing-mode: vertical-rl; transform: rotate(180deg); height: 90px; font-weight: normal; }
    .type-chart td.x2 { background: #58b058; color: #fff; }
    .type-chart td.x05 { background: #e07050; color: #fff; }
    .type-chart td.x0 { background: #404048; color: #fff; }
    .chart-wrap { overflow-x: auto; }
</style>
@endpush

@section('wiki')
    <div class="box wiki-text">
        <h1>Types</h1>
        <p class="lead">Chaque capacité a un type, et chaque Pokémon un ou deux types. Une capacité fait plus ou moins de dégâts selon le type de la cible.</p>
        <ul>
            <li><strong style="color:#58b058">×2</strong> : « C'est super efficace ! » — <strong style="color:#e07050">×½</strong> : « Ce n'est pas très efficace... » — <strong>×0</strong> : « Ça n'affecte pas... »</li>
            <li>Contre un Pokémon à <strong>deux types</strong>, les multiplicateurs se multiplient : ×4, ×2, ×1, ×½, ×¼ ou ×0.</li>
            <li>Une capacité du <strong>même type que le Pokémon qui l'utilise</strong> fait ×1,5 (×2 avec le talent Adaptabilité).</li>
            <li>Certains <strong>talents</strong> changent la règle : Lévitation (immunité Sol), Absorbe-Volt, Torche, Garde Mystik... (voir <a href="{{ route('wiki.abilities') }}">Talents</a>).</li>
        </ul>

        <h2>La table</h2>
        <p class="muted">Ligne : le type de la capacité. Colonne : le type du Pokémon qui la reçoit.</p>
        <div class="chart-wrap">
            <table class="type-chart">
                <tr><th></th>
                    @foreach ($types as $def)<th class="def"><span class="type-badge" style="background: {{ $def->color }}; min-width:0">{{ $def->name }}</span></th>@endforeach
                </tr>
                @foreach ($types as $att)
                    <tr>
                        <th class="att"><span class="type-badge" style="background: {{ $att->color }}">{{ $att->name }}</span></th>
                        @foreach ($types as $def)
                            @php $m = $chart[$att->id][$def->id] ?? 1.0; @endphp
                            <td class="{{ $m == 0 ? 'x0' : ($m > 1 ? 'x2' : ($m < 1 ? 'x05' : '')) }}">{{ $m == 0 ? '0' : ($m > 1 ? '2' : ($m < 1 ? '½' : '')) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </div>

        <h2>Immunités de statut</h2>
        <ul>
            <li>Les Pokémon <strong>Feu</strong> ne peuvent pas être brûlés ; <strong>Glace</strong>, pas gelés ; <strong>Électrik</strong>, pas paralysés.</li>
            <li>Les Pokémon <strong>Poison</strong> et <strong>Acier</strong> ne peuvent pas être empoisonnés.</li>
            <li>Les Pokémon <strong>Plante</strong> ne sont pas touchés par les poudres (Para-Spore, Poudre Dodo...) ni par Vampigraine.</li>
            <li>Tempête de sable : les Pokémon <strong>Roche, Sol et Acier</strong> ne sont pas blessés, et la Défense Spéciale des Pokémon Roche est ×1,5. Grêle : les Pokémon <strong>Glace</strong> ne sont pas blessés.</li>
        </ul>
    </div>
@endsection
