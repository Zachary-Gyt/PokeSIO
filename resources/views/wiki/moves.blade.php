@extends('wiki.layout')

@section('title', 'Capacités')

{{-- Wiki : toutes les capacités apprises par les Pokémon du jeu (WikiReferenceController::moves) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Capacités</h1>
        <p class="lead">Toutes les capacités que les Pokémon du jeu peuvent apprendre. Clique sur une capacité pour voir qui l'apprend.</p>
        @php $categories = ['physical' => 'Physique', 'special' => 'Spéciale', 'status' => 'Statut']; @endphp

        <div class="wiki-tools">
            <input type="text" id="mv-search" placeholder="Nom..." autocomplete="off">
            <select id="mv-type">
                <option value="">Tous les types</option>
                @foreach ($types as $type)<option value="{{ $type->slug }}">{{ $type->name }}</option>@endforeach
            </select>
            <select id="mv-cat">
                <option value="">Toutes les catégories</option>
                @foreach ($categories as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <span class="muted" id="mv-count">{{ $moves->count() }} capacités</span>
        </div>

        <table class="wiki-table">
            <tr><th>Capacité</th><th>Type</th><th>Catégorie</th><th>Puiss.</th><th>Préc.</th><th>PP</th><th>Effet</th></tr>
            @foreach ($moves as $move)
                <tr class="mv-row" data-search="{{ mb_strtolower($move->name) }} {{ $move->slug }}" data-type="{{ $move->type?->slug }}" data-cat="{{ $move->category }}">
                    <td style="white-space:nowrap"><a href="{{ route('wiki.move', $move->slug) }}">{{ $move->name }}</a></td>
                    <td>@if ($move->type)<span class="type-badge" style="background: {{ $move->type->color }}">{{ $move->type->name }}</span>@endif</td>
                    <td>{{ $categories[$move->category] ?? $move->category }}</td>
                    <td>{{ $move->power ?? '—' }}</td><td>{{ $move->accuracy ?? '—' }}</td><td>{{ $move->pp }}</td>
                    <td style="font-size:18px">{{ \Illuminate\Support\Str::limit(\App\Services\PokemonPresenter::clean($move->description), 110) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endsection

@push('scripts')
<script>
    (() => {
        const search = document.getElementById('mv-search');
        const type = document.getElementById('mv-type');
        const cat = document.getElementById('mv-cat');
        const count = document.getElementById('mv-count');
        const rows = [...document.querySelectorAll('.mv-row')];
        const apply = () => {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            for (const row of rows) {
                const ok = (!q || row.dataset.search.includes(q)) && (!type.value || row.dataset.type === type.value) && (!cat.value || row.dataset.cat === cat.value);
                row.hidden = !ok;
                if (ok) shown++;
            }
            count.textContent = `${shown} capacité${shown > 1 ? 's' : ''}`;
        };
        [search, type, cat].forEach((el) => el.addEventListener(el === search ? 'input' : 'change', apply));
    })();
</script>
@endpush
