@extends('wiki.layout')

@section('title', 'Pokédex')

@push('styles')
<style>
    .dex-tools { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .dex-tools input { max-width: 280px; }
    .dex-tools select { max-width: 200px; }
    .dex-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
    .dex-card {
        display: block; text-align: center; text-decoration: none; color: var(--ink) !important; padding: 4px 4px 8px;
        border: 2px solid #d8d8d0; background: #fbfbf8;
    }
    .dex-card:hover { border-color: var(--blue); background: #fff; }
    .dex-card[hidden] { display: none; }
    .dex-card .num { color: #909098; font-size: 18px; text-align: left; }
    .dex-card img { width: 96px; height: 96px; display: block; margin: -6px auto 0; }
    .dex-card .name { font-size: 22px; }
    .dex-card .types { display: flex; gap: 4px; justify-content: center; flex-wrap: wrap; margin-top: 2px; }
    .dex-card .types .type-badge { min-width: 0; font-size: 16px; }
    .dex-count { color: #909098; font-size: 20px; align-self: center; }
</style>
@endpush

@section('wiki')
    <div class="box">
        <h1>Pokédex</h1>
        <p class="lead">Les Pokémon disponibles dans le jeu ({{ $regions->join(', ') }}). Clique sur un Pokémon pour voir sa fiche.</p>

        <div class="dex-tools">
            <input type="text" id="dex-search" placeholder="Nom ou numéro..." autocomplete="off">
            {{-- Étape 23 : par région --}}
            <select id="dex-region">
                <option value="">Toutes les régions</option>
                @foreach ($regions as $generation => $region)
                    <option value="{{ $generation }}">{{ $region }}</option>
                @endforeach
            </select>
            <select id="dex-type">
                <option value="">Tous les types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->slug }}">{{ $type->name }}</option>
                @endforeach
            </select>
            <span class="dex-count" id="dex-count">{{ $species->count() }} Pokémon</span>
        </div>

        <div class="dex-grid">
            @foreach ($species as $s)
                <a class="dex-card" href="{{ route('wiki.pokemon', $s->slug) }}"
                   data-search="{{ mb_strtolower($s->name) }} {{ $s->pokedex_number }} {{ $s->slug }}"
                   data-types="{{ $s->primaryType?->slug }} {{ $s->secondaryType?->slug }}" data-gen="{{ $s->generation }}">
                    <div class="num">N° {{ str_pad($s->pokedex_number, 3, '0', STR_PAD_LEFT) }}</div>
                    @if ($s->sprite('front'))
                        <img class="px" loading="lazy" src="{{ $s->sprite('front') }}" alt="{{ $s->name }}">
                    @endif
                    <div class="name">{{ $s->name }}</div>
                    <div class="types">
                        @foreach ([$s->primaryType, $s->secondaryType] as $type)
                            @if ($type)
                                <span class="type-badge" style="background: {{ $type->color }}">{{ $type->name }}</span>
                            @endif
                        @endforeach
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Recherche et filtre par type, sans recharger la page
    (() => {
        const search = document.getElementById('dex-search');
        const type = document.getElementById('dex-type');
        const region = document.getElementById('dex-region');
        const count = document.getElementById('dex-count');
        const cards = [...document.querySelectorAll('.dex-card')];
        const apply = () => {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            for (const card of cards) {
                const ok = (!q || card.dataset.search.includes(q)) && (!type.value || card.dataset.types.split(' ').includes(type.value))
                    && (!region.value || card.dataset.gen === region.value);
                card.hidden = !ok;
                if (ok) shown++;
            }
            count.textContent = `${shown} Pokémon`;
        };
        search.addEventListener('input', apply);
        type.addEventListener('change', apply);
        region.addEventListener('change', apply);
    })();
</script>
@endpush
