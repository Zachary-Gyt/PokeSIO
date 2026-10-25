@extends('layouts.app')

@section('title', 'Mes personnages')

{{--
    SÉLECTION DU PERSONNAGE, sur le modèle de l'écran de chargement de Pokémon Essentials
    (Graphics/UI/Load : bg.png et panels.png) :
      - en haut, un petit panneau par emplacement (3) ;
      - en bas, le grand panneau du personnage sélectionné : équipe, badges, Pokédex, temps, lieu.
    Un clic (ou Entrée) sur un personnage lance directement le jeu. Flèches / ZQSD pour choisir.

    panels.png (408 x 536) : grand panneau 408 x 222 (normal à y = 0, sélectionné à y = 222),
                             petit panneau 408 x 46 (normal à y = 444, sélectionné à y = 490).
--}}

@push('styles')
<style>
    .load-screen .inner { background: url('{{ asset('game/ui/load/bg.png') }}') 0 0 / 100% 100%; }
    .panel {
        position: absolute; left: calc(52 * var(--gp)); width: calc(408 * var(--gp));
        background: url('{{ asset('game/ui/load/panels.png') }}') no-repeat;
        background-size: calc(408 * var(--gp)) calc(536 * var(--gp));
        font-family: 'Power Green', monospace; color: #f8f8f8;
        text-shadow: calc(2 * var(--gp)) calc(2 * var(--gp)) 0 #383040;
        text-decoration: none; cursor: pointer;
    }
    .panel.small { height: calc(46 * var(--gp)); background-position: 0 calc(-444 * var(--gp)); }
    .panel.small.selected { background-position: 0 calc(-490 * var(--gp)); }
    .panel.big { top: calc(160 * var(--gp)); height: calc(222 * var(--gp)); background-position: 0 0; cursor: default; }
    .panel.big.selected { background-position: 0 calc(-222 * var(--gp)); }

    .panel .row { position: absolute; left: calc(18 * var(--gp)); right: calc(18 * var(--gp)); top: calc(6 * var(--gp));
        display: flex; justify-content: space-between; font-size: calc(26 * var(--gp)); line-height: calc(32 * var(--gp)); }
    .panel .slot-num { color: #c8b8e8; }

    .big .title { position: absolute; left: calc(18 * var(--gp)); right: calc(22 * var(--gp)); top: calc(6 * var(--gp)); font-size: calc(26 * var(--gp)); line-height: calc(32 * var(--gp));
        display: flex; justify-content: space-between; gap: calc(12 * var(--gp)); }
    .big .title .place { font-size: calc(20 * var(--gp)); color: #c8b8e8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .big .trainer { position: absolute; right: calc(12 * var(--gp)); top: calc(30 * var(--gp)); width: calc(112 * var(--gp)); height: calc(112 * var(--gp)); }
    .big dl { position: absolute; left: calc(18 * var(--gp)); top: calc(50 * var(--gp)); width: calc(250 * var(--gp)); margin: 0;
        display: grid; grid-template-columns: auto 1fr; gap: 0 calc(12 * var(--gp)); font-size: calc(20 * var(--gp)); line-height: calc(24 * var(--gp)); }
    .big dt { color: #c8b8e8; }
    .big dd { margin: 0; text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .big .party { position: absolute; left: calc(14 * var(--gp)); bottom: calc(10 * var(--gp)); display: flex; gap: calc(2 * var(--gp)); }
    /* Icônes d'équipe d'Essentials : 2 images de 64 x 64 côte à côte, on affiche la 1re (et la 2e en alternance) */
    .big .icon { width: calc(56 * var(--gp)); height: calc(56 * var(--gp)); background-repeat: no-repeat; background-size: calc(112 * var(--gp)) calc(56 * var(--gp));
        animation: icon 0.5s steps(1) infinite; image-rendering: pixelated; }
    @keyframes icon { 50% { background-position: calc(-56 * var(--gp)) 0; } }
    .big .actions { position: absolute; right: calc(16 * var(--gp)); bottom: calc(12 * var(--gp)); display: flex; gap: calc(8 * var(--gp)); align-items: center; }
    .big .play { font-size: calc(24 * var(--gp)); color: #ffe070; animation: blink 1.2s steps(1) infinite; }
    @keyframes blink { 50% { opacity: .35; } }
    .big .delete { font: calc(18 * var(--gp)) 'Power Green Small', monospace; color: #f0a0b0; background: none; border: 0; cursor: pointer;
        text-shadow: calc(1 * var(--gp)) calc(1 * var(--gp)) 0 #383040; text-decoration: underline; padding: 0; }
    .big .empty-text { position: absolute; left: calc(18 * var(--gp)); right: calc(18 * var(--gp)); top: calc(60 * var(--gp));
        font-size: calc(22 * var(--gp)); line-height: calc(30 * var(--gp)); text-align: center; }
    .flash { position: absolute; left: 0; right: 0; top: calc(-2 * var(--gp)); text-align: center; z-index: 3;
        font: calc(18 * var(--gp)) 'Power Green Small', monospace; color: #2e7d32; }
</style>
@endpush

@section('content')
    @php
        $slots = [];
        for ($slot = 1; $slot <= \App\Models\Character::MAX_PER_USER; $slot++) {
            $slots[$slot] = $characters[$slot] ?? null;
        }
        $formatTime = fn ($s) => intdiv($s, 3600) . ':' . str_pad(intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);
    @endphp

    <div class="screen load-screen">
        <div class="inner" id="load-screen">
            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif

            {{-- Les 3 emplacements --}}
            @foreach ($slots as $slot => $character)
                <a class="panel small" data-slot="{{ $slot }}" style="top: calc({{ 8 + ($slot - 1) * 50 }} * var(--gp))"
                   href="{{ $character ? route('game', $character) : route('characters.create', $slot) }}">
                    <div class="row">
                        <span><span class="slot-num">{{ $slot }}.</span> {{ $character?->name ?? 'Nouvelle partie' }}</span>
                        <span>{{ $character ? 'N.' . ($character->party->max('level') ?? '-') : '+' }}</span>
                    </div>
                </a>
            @endforeach

            {{-- Le détail de l'emplacement sélectionné (un grand panneau par emplacement, un seul visible) --}}
            @foreach ($slots as $slot => $character)
                <div class="panel big selected" data-detail="{{ $slot }}" hidden>
                    @if ($character)
                        <div class="title"><span>{{ $character->name }}</span><span class="place">{{ $character->map_name }}</span></div>
                        <img class="trainer px" src="{{ $character->spriteUrl('front') }}" alt="">
                        <dl>
                            <dt>Badges</dt><dd>{{ $character->badges_count }}</dd>
                            <dt>Pokédex</dt><dd>{{ $character->caught_count }}</dd>
                            <dt>Temps</dt><dd>{{ $formatTime($character->play_time) }}</dd>
                            <dt>Argent</dt><dd>{{ number_format($character->money, 0, ',', ' ') }} ₽</dd>
                        </dl>
                        <div class="party">
                            @foreach ($character->party as $pokemon)
                                <div class="icon" title="{{ $pokemon->display_name }} N.{{ $pokemon->level }}"
                                     style="background-image: url('{{ $pokemon->sprite('icon') }}')"></div>
                            @endforeach
                        </div>
                        <div class="actions">
                            <form method="POST" action="{{ route('characters.destroy', $character) }}"
                                  onsubmit="return confirm('Supprimer définitivement {{ addslashes($character->name) }} et tous ses Pokémon ?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="delete">Supprimer</button>
                            </form>
                        </div>
                    @else
                        <div class="title">Emplacement {{ $slot }}</div>
                        <div class="empty-text">Cet emplacement est libre.<br>Commence une nouvelle aventure !</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Choix au clavier (flèches / ZQSD, Entrée) ou à la souris (survol = choisir, clic = jouer)
    (() => {
        const slots = [...document.querySelectorAll('.panel.small')];
        const details = [...document.querySelectorAll('.panel.big')];
        let index = 0;
        const select = (i) => {
            index = (i + slots.length) % slots.length;
            slots.forEach((s, k) => s.classList.toggle('selected', k === index));
            details.forEach((d, k) => (d.hidden = k !== index));
            try { localStorage.setItem('pokemon.slot', String(index)); } catch (e) { /* stockage indisponible */ }
        };
        slots.forEach((s, k) => s.addEventListener('mouseenter', () => select(k)));
        window.addEventListener('keydown', (e) => {
            const key = e.key.toLowerCase();
            if (['arrowdown', 's'].includes(key)) { select(index + 1); e.preventDefault(); }
            if (['arrowup', 'z'].includes(key)) { select(index - 1); e.preventDefault(); }
            if (['enter', ' ', 'e'].includes(key)) { slots[index].click(); e.preventDefault(); }
        });
        let saved = 0;
        try { saved = Number(localStorage.getItem('pokemon.slot') ?? 0) || 0; } catch (e) { /* rien */ }
        select(saved);
    })();
</script>
@endpush
