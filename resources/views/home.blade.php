@extends('layouts.app')

@section('title', 'Accueil')

{{--
    L'ÉCRAN TITRE, dessiné comme le jeu : un "écran" de 512 x 384 pixels de jeu (agrandi),
    le décor de combat d'Essentials, les trois starters sur leur socle, et un menu
    avec le cadre et la flèche du jeu. Clavier (flèches / ZQSD + Entrée) ou souris.
--}}

@push('styles')
<style>
    .title-screen .bg { position: absolute; left: 0; top: 0; width: calc(512 * var(--gp)); height: calc(288 * var(--gp)); }
    .title-screen .bottom { position: absolute; left: 0; top: calc(288 * var(--gp)); width: 100%; height: calc(96 * var(--gp)); background: #20283a; }
    .title-screen .base { position: absolute; left: calc(128 * var(--gp)); top: calc(150 * var(--gp)); width: calc(256 * var(--gp)); height: calc(128 * var(--gp)); }

    .title-screen .logo {
        position: absolute; left: 0; right: 0; top: calc(14 * var(--gp)); text-align: center;
        font-family: 'Power Green', monospace; font-size: calc(64 * var(--gp)); line-height: 1;
        color: #ffcb05; letter-spacing: calc(2 * var(--gp));
        text-shadow:
            calc(3 * var(--gp)) 0 0 #2a4a8c, calc(-3 * var(--gp)) 0 0 #2a4a8c,
            0 calc(3 * var(--gp)) 0 #2a4a8c, 0 calc(-3 * var(--gp)) 0 #2a4a8c,
            calc(5 * var(--gp)) calc(5 * var(--gp)) 0 #102040;
    }
    .title-screen .subtitle {
        position: absolute; left: 0; right: 0; top: calc(84 * var(--gp)); text-align: center;
        font-family: 'Power Green', monospace; font-size: calc(24 * var(--gp));
        color: #f8f8f8; text-shadow: calc(2 * var(--gp)) calc(2 * var(--gp)) 0 #384868;
    }

    /* Les starters : sprites d'Essentials (192 x 192), réduits de moitié, qui respirent */
    .title-screen .mon { position: absolute; width: calc(144 * var(--gp)); height: calc(144 * var(--gp)); animation: breathe 1.8s ease-in-out infinite; }
    .title-screen .mon:nth-of-type(2) { animation-delay: .3s; }
    .title-screen .mon:nth-of-type(3) { animation-delay: .6s; }
    @keyframes breathe { 50% { transform: translateY(calc(-3 * var(--gp))); } }

    /* Le menu : cadre "choice" du jeu (coins de 16 px de jeu) et flèche de sélection */
    .title-menu {
        position: absolute; left: calc(64 * var(--gp)); top: calc(292 * var(--gp)); width: calc(384 * var(--gp));
        border-style: solid; border-width: calc(16 * var(--gp));
        border-image: url('{{ asset('game/ui/choice.png') }}') 16 fill / calc(16 * var(--gp)) stretch;
        padding: 0 calc(4 * var(--gp));
        display: grid; grid-template-columns: 1fr 1fr; gap: 0 calc(8 * var(--gp));
    }
    /* Un seul choix (joueur connecté : "Jouer") : un petit cadre centré */
    .title-menu:has(> a:only-child) { left: calc(176 * var(--gp)); width: calc(160 * var(--gp)); grid-template-columns: 1fr; }
    .title-menu a {
        position: relative; display: block; padding-left: calc(20 * var(--gp));
        font-family: 'Power Green', monospace; font-size: calc(24 * var(--gp)); line-height: calc(30 * var(--gp));
        color: #505058; text-shadow: calc(2 * var(--gp)) calc(2 * var(--gp)) 0 #d0d0c8; text-decoration: none; white-space: nowrap;
    }
    .title-menu a.selected::before {
        content: ''; position: absolute; left: 0; top: calc(6 * var(--gp));
        width: calc(16 * var(--gp)); height: calc(16 * var(--gp));
        background: url('{{ asset('game/ui/sel_arrow.png') }}') no-repeat 0 0 / 100% 100%;
        image-rendering: pixelated;
    }
    .press {
        position: absolute; left: 0; right: 0; bottom: calc(4 * var(--gp)); text-align: center;
        font-family: 'Power Green Small', monospace; font-size: calc(16 * var(--gp)); color: #a0b0d0;
        animation: blink 1.2s steps(1) infinite;
    }
    @keyframes blink { 50% { opacity: 0; } }

    /* ---- Mise en page : l'écran titre, et à droite les statistiques ---- */
    .title-layout { display: flex; gap: 20px; align-items: flex-start; justify-content: center; width: 100%; flex-wrap: wrap; }
    @media (min-width: 960px) {
        .title-layout .screen { width: min(100vw - 32px - 360px, 1024px, (100vh - 120px) * 4 / 3); }
    }

    /* Les panneaux de l'écran de chargement d'Essentials (ui/load/panels.png, 408 de large).
       --pp = un pixel du panneau : l'image est réduite à la largeur de la colonne. */
    .side { width: 340px; max-width: 100%; container-type: inline-size; display: flex; flex-direction: column; gap: 6px; }
    .lp {
        --pp: calc(100cqw / 408);
        position: relative; width: 100%;
        background: url('{{ asset('game/ui/load/panels.png') }}') no-repeat;
        background-size: calc(408 * var(--pp)) calc(536 * var(--pp));
        image-rendering: pixelated;
        color: #f8f8f8; text-shadow: 2px 2px 0 #383040; font-size: calc(22 * var(--pp));
    }
    .lp .dim { color: #c8b8e8; }
    .lp.big { height: calc(222 * var(--pp)); background-position: 0 calc(-222 * var(--pp)); }
    .lp.small { height: calc(46 * var(--pp)); background-position: 0 calc(-444 * var(--pp));
        display: flex; justify-content: space-between; align-items: center; padding: 0 calc(30 * var(--pp)) calc(4 * var(--pp)) calc(26 * var(--pp)); }
    .lp.small.first, .lp.small.tabs { background-position: 0 calc(-490 * var(--pp)); }

    .lp-head { position: absolute; left: calc(18 * var(--pp)); right: calc(22 * var(--pp)); top: calc(10 * var(--pp));
        display: flex; justify-content: space-between; font-size: calc(24 * var(--pp)); }
    .lp-head .dim { font-size: calc(18 * var(--pp)); align-self: center; }
    .lp-trainer { position: absolute; right: calc(14 * var(--pp)); top: calc(44 * var(--pp)); width: calc(112 * var(--pp)); height: calc(112 * var(--pp)); }
    .lp-name { position: absolute; left: calc(18 * var(--pp)); top: calc(62 * var(--pp)); font-size: calc(34 * var(--pp)); color: #ffe070; }
    .lp-line { position: absolute; left: calc(18 * var(--pp)); top: calc(108 * var(--pp)); width: calc(250 * var(--pp)); font-size: calc(20 * var(--pp)); color: #c8b8e8; }
    .lp-team { position: absolute; left: calc(14 * var(--pp)); bottom: calc(10 * var(--pp)); display: flex; gap: calc(4 * var(--pp)); }
    .lp-team .icon { width: calc(56 * var(--pp)); height: calc(56 * var(--pp)); background-repeat: no-repeat;
        background-size: calc(112 * var(--pp)) calc(56 * var(--pp)); animation: icon .5s steps(1) infinite; }
    @keyframes icon { 50% { background-position: calc(-56 * var(--pp)) 0; } }

    .tabs .arrow { background: none; border: 0; padding: 0 calc(6 * var(--pp)); cursor: pointer; color: #ffe070; font-size: calc(20 * var(--pp)); text-shadow: inherit; }
    .tabs .arrow:hover { color: #fff; }
    .row { font-size: calc(22 * var(--pp)); }
    .row .rank { color: #c8b8e8; }
    .row.first .rank { color: #ffe070; }
    .board-list { display: flex; flex-direction: column; gap: 6px; }
    .board-list[hidden] { display: none; }
</style>
@endpush

@section('content')
<div class="title-layout">
    <div class="screen title-screen">
        <div class="inner">
            <img class="bg px" src="{{ asset('game/battle/backgrounds/field_bg.png') }}" alt="">
            <div class="bottom"></div>
            <img class="base px" src="{{ asset('game/battle/backgrounds/field_base1.png') }}" alt="">

            @php $positions = [[96, 118], [184, 104], [272, 118]]; @endphp
            @foreach ($starters->take(3)->values() as $i => $starter)
                @if ($starter->sprite('front'))
                    <img class="mon px" src="{{ $starter->sprite('front') }}" alt="{{ $starter->name }}" title="{{ $starter->name }}"
                         style="left: calc({{ $positions[$i][0] }} * var(--gp)); top: calc({{ $positions[$i][1] }} * var(--gp)); z-index: {{ $i === 1 ? 2 : 1 }}">
                @endif
            @endforeach

            <div class="logo">PokéSIO</div>
            <div class="subtitle">Version Laravel</div>

            <nav class="title-menu" id="title-menu">
                @auth
                    <a href="{{ route('dashboard') }}" class="selected">Jouer</a>
                @else
                    <a href="{{ route('login') }}" class="selected">Connexion</a>
                    <a href="{{ route('register') }}">Inscription</a>
                @endauth
            </nav>
            <div class="press">Flèches et Entrée, ou la souris</div>
        </div>
    </div>

    {{-- À côté de l'écran : le Tenant du titre et le classement, dans les panneaux de l'écran de chargement --}}
    <aside class="side">
        @foreach ($titles as $title)
            <section class="lp big">
                <div class="lp-head"><span>{{ $title['class'] }}</span>@if ($title['since'])<span class="dim">depuis le {{ $title['since'] }}</span>@endif</div>
                <img class="lp-trainer px" src="{{ $title['sprite'] }}" alt="">
                <div class="lp-name">{{ $title['name'] }}</div>
                <div class="lp-line">
                    @if ($title['isPlayer'])
                        {{ $title['defenses'] }} défense{{ $title['defenses'] > 1 ? 's' : '' }} réussie{{ $title['defenses'] > 1 ? 's' : '' }}
                    @else
                        Personne ne l'a encore battu !
                    @endif
                </div>
                <div class="lp-team">
                    @foreach ($title['team'] as $mon)
                        <div class="icon" title="{{ $mon['label'] }}" style="background-image: url('{{ $mon['icon'] }}')"></div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <section class="board" id="board">
            <div class="lp small tabs">
                <button type="button" class="arrow" data-step="-1" aria-label="Classement précédent">◀</button>
                <span id="board-title">Classement</span>
                <button type="button" class="arrow" data-step="1" aria-label="Classement suivant">▶</button>
            </div>
            @foreach ($leaderboards as $key => $board)
                <div class="board-list" data-board="{{ $key }}" data-label="Classement : {{ $board['label'] }}" @if (! $loop->first) hidden @endif>
                    @forelse ($board['rows'] as $i => $row)
                        <div class="lp small row {{ $i === 0 ? 'first' : '' }}">
                            <span><span class="rank">{{ $i + 1 }}.</span> {{ $row['name'] }}</span>
                            <span>{{ $row['value'] }}</span>
                        </div>
                    @empty
                        <div class="lp small row"><span class="dim">Personne pour l'instant</span></div>
                    @endforelse
                </div>
            @endforeach
        </section>
    </aside>
</div>
@endsection

@push('scripts')
<script>
    // Menu au clavier, comme dans le jeu : flèches ou ZQSD pour choisir, Entrée / Espace / E pour valider
    (() => {
        const items = [...document.querySelectorAll('#title-menu a')];
        let index = 0;
        const select = (i) => {
            index = (i + items.length) % items.length;
            items.forEach((a, k) => a.classList.toggle('selected', k === index));
        };
        items.forEach((a, k) => a.addEventListener('mouseenter', () => select(k)));
        window.addEventListener('keydown', (e) => {
            const key = e.key.toLowerCase();
            if (['arrowright', 'arrowdown', 'd', 's'].includes(key)) { select(index + 1); e.preventDefault(); }
            if (['arrowleft', 'arrowup', 'q', 'z'].includes(key)) { select(index - 1); e.preventDefault(); }
            if (['enter', ' ', 'e'].includes(key)) { items[index].click(); e.preventDefault(); }
        });
    })();
    // Classement : les flèches passent d'un classement à l'autre (temps de jeu, Pokédex, badges)
    (() => {
        const lists = [...document.querySelectorAll('.board-list')];
        const title = document.getElementById('board-title');
        if (!lists.length) return;
        let index = 0;
        const show = (i) => {
            index = (i + lists.length) % lists.length;
            lists.forEach((l, k) => (l.hidden = k !== index));
            title.textContent = lists[index].dataset.label;
        };
        document.querySelectorAll('#board .arrow').forEach((b) => b.addEventListener('click', () => show(index + Number(b.dataset.step))));
        show(0);
    })();
</script>
@endpush
