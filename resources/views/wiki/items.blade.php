@extends('wiki.layout')

@section('title', 'Objets')

{{-- Étape 16 : tous les objets du jeu, poche par poche (WikiController::items) --}}

@push('styles')
<style>
    .item-tools { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
    .item-tools input[type=text] { max-width: 280px; }
    .item-tools label { font-size: 20px; display: flex; gap: 6px; align-items: center; cursor: pointer; }
    .item-tools input[type=checkbox] { width: 18px; height: 18px; }
    .item-count { color: #909098; font-size: 20px; }
    .pocket-tabs { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 14px; }
    .pocket-tabs button {
        font: 20px var(--font); padding: 2px 10px; cursor: pointer; color: var(--ink);
        border: 2px solid #d8d8d0; background: #fbfbf8;
    }
    .pocket-tabs button.active { border-color: var(--blue); background: #fff; color: var(--blue); }
    .pocket h2 { margin: 18px 0 6px; }
    .pocket[hidden], .item-row[hidden] { display: none; }
    .item-row {
        display: grid; grid-template-columns: 56px 1fr 110px; gap: 10px; align-items: start;
        padding: 6px 4px; border-bottom: 2px solid #ecece6;
    }
    .item-row img { width: 48px; height: 48px; image-rendering: pixelated; }
    .item-name { font-size: 24px; }
    .item-desc { font-size: 18px; color: #606068; margin-top: 2px; }
    .item-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
    .item-tag { font-size: 16px; padding: 0 6px; border: 2px solid #d8d8d0; background: #f4f4ee; }
    .item-tag.effect { border-color: #9cc8a0; background: #eef8ee; }
    .item-tag.held { border-color: #c8b0e0; background: #f6f0fc; }
    .item-tag.source { border-color: #a8c0e8; background: #eef3fc; }
    .item-price { text-align: right; font-size: 20px; color: #606068; }
    @media (max-width: 600px) { .item-row { grid-template-columns: 48px 1fr; } .item-price { grid-column: 2; text-align: left; } }
</style>
@endpush

@section('wiki')
    <div class="box">
        <h1>Objets</h1>
        <p class="lead">Tous les objets du jeu, rangés comme dans le Sac. Le prix est celui des boutiques ; « Où le trouver » liste les boutiques, les Poké Balls posées sur les cartes, les cadeaux des PNJ et les récompenses.</p>

        <div class="item-tools">
            <input type="text" id="item-search" placeholder="Nom de l'objet..." autocomplete="off">
            <label><input type="checkbox" id="item-found"> Seulement ceux qu'on peut obtenir</label>
            <span class="item-count" id="item-count">{{ $total }} objets</span>
        </div>

        <div class="pocket-tabs" id="pocket-tabs">
            <button type="button" class="active" data-pocket="">Toutes les poches</button>
            @foreach ($pockets as $pocket)
                <button type="button" data-pocket="{{ $pocket['number'] }}">{{ $pocket['name'] }}</button>
            @endforeach
        </div>

        @foreach ($pockets as $pocket)
            <section class="pocket" data-pocket="{{ $pocket['number'] }}">
                <h2>{{ $pocket['name'] }}</h2>
                @foreach ($pocket['items'] as $item)
                    <div class="item-row" data-search="{{ mb_strtolower($item['name']) }} {{ $item['slug'] }}" data-found="{{ $item['sources'] ? 1 : 0 }}">
                        <img src="{{ $item['icon'] }}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
                        <div>
                            <div class="item-name">{{ $item['name'] }}</div>
                            @if ($item['description'])
                                <div class="item-desc">{{ $item['description'] }}</div>
                            @endif
                            <div class="item-tags">
                                @if ($item['effect'])<span class="item-tag effect">{{ $item['effect'] }}</span>@endif
                                @if ($item['held'])<span class="item-tag held">{{ $item['held'] }}</span>@endif
                                {{-- Étape 23 : les évolutions que l'objet provoque --}}
                                @foreach ($item['evolves'] as $evo)
                                    <span class="item-tag held">Évolution : {{ $evo }}</span>
                                @endforeach
                                @foreach ($item['sources'] as $source)
                                    <span class="item-tag source">{{ $source }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="item-price">{{ $item['price'] > 0 ? number_format($item['price'], 0, ',', ' ') . ' ₽' : '—' }}</div>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>
@endsection

@push('scripts')
<script>
    // Recherche, poche et filtre "obtenable", sans recharger la page
    (() => {
        const search = document.getElementById('item-search');
        const found = document.getElementById('item-found');
        const count = document.getElementById('item-count');
        const tabs = [...document.querySelectorAll('#pocket-tabs button')];
        const pockets = [...document.querySelectorAll('.pocket')];
        let pocket = '';
        const apply = () => {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            for (const section of pockets) {
                let inSection = 0;
                for (const row of section.querySelectorAll('.item-row')) {
                    const ok = (!q || row.dataset.search.includes(q)) && (!found.checked || row.dataset.found === '1');
                    row.hidden = !ok;
                    if (ok) inSection++;
                }
                section.hidden = (pocket && section.dataset.pocket !== pocket) || inSection === 0;
                if (!section.hidden) shown += inSection;
            }
            count.textContent = `${shown} objet${shown > 1 ? 's' : ''}`;
        };
        tabs.forEach((tab) => tab.addEventListener('click', () => {
            pocket = tab.dataset.pocket;
            tabs.forEach((t) => t.classList.toggle('active', t === tab));
            apply();
        }));
        search.addEventListener('input', apply);
        found.addEventListener('change', apply);
    })();
</script>
@endpush
