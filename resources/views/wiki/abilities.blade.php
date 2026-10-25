@extends('wiki.layout')

@section('title', 'Talents')

{{-- Wiki : les talents (WikiReferenceController::abilities). Effet = description du jeu ; en plus, ce qu'ils font hors combat. --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Talents</h1>
        <p class="lead">Chaque Pokémon a un talent. Il agit tout seul en combat (et parfois en dehors), sans utiliser de tour.
            Le talent d'un Pokémon est tiré au hasard parmi ceux de son espèce (sauf le talent caché).</p>
        <div class="note">Le jeu annonce un talent quand il agit : « Intimidation du Léviator sauvage ! ».
            Les talents de la 3e et de la 4e génération agissent tous ; ceux qui ont besoin d'un système absent du jeu sont signalés.</div>

        <div class="wiki-tools">
            <input type="text" id="ab-search" placeholder="Talent ou Pokémon..." autocomplete="off">
            <label><input type="checkbox" id="ab-field"> Seulement ceux qui agissent hors combat</label>
            <span class="muted" id="ab-count">{{ $abilities->count() }} talents</span>
        </div>

        <table class="wiki-table" id="ab-table">
            <tr><th>Talent</th><th>Effet</th><th>Pokémon</th></tr>
            @foreach ($abilities as $ability)
                @php
                    $field = \App\Http\Controllers\WikiReferenceController::FIELD_EFFECTS[$ability->slug] ?? null;
                    $none = \App\Http\Controllers\WikiReferenceController::NOT_HERE[$ability->slug] ?? null;
                @endphp
                <tr class="ab-row" data-field="{{ $field ? 1 : 0 }}"
                    data-search="{{ mb_strtolower($ability->name) }} {{ $ability->slug }} {{ mb_strtolower($ability->species->pluck('name')->join(' ')) }}">
                    <td style="white-space:nowrap"><strong>{{ $ability->name }}</strong></td>
                    <td>
                        {{ \App\Services\PokemonPresenter::clean($ability->description) }}
                        @if ($field)<br><span style="color:var(--blue)">{{ $field }}</span>@endif
                        @if ($none)<br><span class="muted">Sans effet ici pour l'instant ({{ $none }}).</span>@endif
                    </td>
                    <td>
                        @foreach ($ability->species as $s)
                            <a href="{{ route('wiki.pokemon', $s->slug) }}" title="{{ $s->name }}{{ $s->pivot->is_hidden ? ' (talent caché)' : '' }}"><img class="mon-icon" src="{{ $s->sprite('icon') }}" alt="{{ $s->name }}" loading="lazy" style="{{ $s->pivot->is_hidden ? 'opacity:.55' : '' }}"></a>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </table>
        <p class="muted">Icône pâle : talent caché de ce Pokémon.</p>
    </div>
@endsection

@push('scripts')
<script>
    (() => {
        const search = document.getElementById('ab-search');
        const field = document.getElementById('ab-field');
        const count = document.getElementById('ab-count');
        const rows = [...document.querySelectorAll('.ab-row')];
        const apply = () => {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            for (const row of rows) {
                const ok = (!q || row.dataset.search.includes(q)) && (!field.checked || row.dataset.field === '1');
                row.hidden = !ok;
                if (ok) shown++;
            }
            count.textContent = `${shown} talent${shown > 1 ? 's' : ''}`;
        };
        search.addEventListener('input', apply);
        field.addEventListener('change', apply);
    })();
</script>
@endpush
