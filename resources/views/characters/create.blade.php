@extends('layouts.app')

@section('title', 'Nouvelle partie')

@push('styles')
<style>
    .step { margin-top: 26px; }
    .step:first-of-type { margin-top: 6px; }
    .step h2 { font-family: var(--font); font-size: 20px; margin: 0 0 12px; color: var(--blue); }

    /* Cartes à choisir (personnage et starter) : le vrai bouton radio est caché, la carte entière est cliquable */
    .choices { display: grid; gap: 12px; }
    .choices.two { grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 380px; }
    .choices.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .choices.four { grid-template-columns: repeat(4, minmax(0, 1fr)); }   /* étape 17 : les 4 personnages */
    /* Les personnages : 3 colonnes de 2 (Red / Leaf, Brendan / May, Ethan / Lyra) : on remplit colonne par colonne */
    .choices.trainers { grid-template-columns: repeat(3, minmax(0, 1fr)); grid-template-rows: repeat(2, auto); grid-auto-flow: column; }
    /* Un panneau de starters caché (onglet d'une autre région) : "display: grid" ci-dessus l'emportait sur l'attribut hidden */
    .choices[hidden] { display: none; }
    .trainer-choice img { width: 96px; height: 96px; object-fit: contain; }
    .choice { position: relative; }
    .choice input { position: absolute; opacity: 0; pointer-events: none; }
    .choice label {
        display: flex; flex-direction: column; align-items: center; gap: 6px;
        margin: 0; padding: 14px 8px 12px;
        border: 3px solid #dfe3ea; border-radius: 0; background: #fff;
        cursor: pointer; text-align: center; 
        transition: border-color .12s, transform .12s, box-shadow .12s;
    }
    .choice label:hover { border-color: #7aa0e8; }
    .choice input:checked + label {
        border-color: var(--red);
        box-shadow: 0 0 0 3px #ffd3c7;
        transform: translateY(-2px);
    }
    .choice input:focus-visible + label { outline: 3px solid #7aa0e8; outline-offset: 2px; }

    .avatar { position: relative; width: 72px; height: 72px; display: grid; place-items: center; }
    .avatar .symbol { font-size: 40px; line-height: 1; }
    .avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
    .male .symbol { color: #2f6fdb; }
    .female .symbol { color: #e0457b; }

    .starter img { width: 96px; height: 96px; }
    .starter .types { display: flex; gap: 4px; justify-content: center; flex-wrap: wrap; }
    .type { display: inline-block; padding: 0 6px 2px; border-radius: 0; color: #fff; font-size: 18px;  text-shadow: 0 1px 1px rgba(0,0,0,.35); }
    .starter-name { font-size: 22px; }

    .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .tab {
        font-family: var(--font); font-size: 18px;
        padding: 8px 10px; border-radius: 0; border: 2px solid #dfe3ea;
        background: #fff; cursor: pointer;
    }
    .tab[aria-selected="true"] { background: var(--blue); color: #fff; border-color: var(--blue); }

    .dex-entry {
        margin-top: 14px; padding: 12px 14px; border-radius: 0;
        background: #f1f4fa; font-size: 22px; min-height: 48px;
    }
    .dex-entry strong { display: block; margin-bottom: 2px; }
    .dex-entry .placeholder { color: var(--muted); }

    .hint { font-size: 20px; color: var(--muted); margin-top: 6px; }

    @media (max-width: 520px) {
        .choices.three { grid-template-columns: 1fr; }
        .choices.four { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        /* Sur un petit écran : 2 colonnes, un couple par ligne */
        .choices.trainers { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-rows: none; grid-auto-flow: row; }
    }
</style>
@endpush

@section('content')
    <div class="box wide">
        <h1>Nouvelle partie</h1>
        <p class="lead">Emplacement {{ $slot }} · Bienvenue dans le monde des Pokémon !</p>

        <form method="POST" action="{{ route('characters.store') }}">
            @csrf
            <input type="hidden" name="slot" value="{{ $slot }}">
            @error('slot') <div class="error">{{ $message }}</div> @enderror

            {{-- 1. Nom --}}
            <section class="step">
                <h2>1. Quel est ton nom ?</h2>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus
                       maxlength="{{ config('game.name_max_length') }}" autocomplete="off" style="max-width: 320px">
                <div class="hint">{{ config('game.name_max_length') }} caractères maximum.</div>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </section>

            {{-- 2. Personnage (étape 17) : un des personnages de config/avatars.php --}}
            <section class="step">
                <h2>2. Choisis ton personnage</h2>
                <div class="choices trainers">
                    @foreach (config('avatars') as $key => $avatar)
                        <div class="choice trainer-choice">
                            <input type="radio" name="avatar" id="avatar-{{ $key }}" value="{{ $key }}"
                                   @checked(old('avatar') === $key) required>
                            <label for="avatar-{{ $key }}">
                                <img class="px" src="{{ asset("game/sprites/trainers/battle/player_{$key}.png") }}" alt="">
                                <span class="starter-name">{{ $avatar['name'] }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
                @error('avatar') <div class="error">{{ $message }}</div> @enderror
            </section>

            {{-- 3. Starter --}}
            <section class="step">
                <h2>3. Choisis ton premier Pokémon</h2>

                @if ($startersByGeneration->isEmpty())
                    <div class="error">Aucun starter disponible. Lance l'import PokéAPI.</div>
                @endif

                {{-- Onglets par région : affichés seulement si plusieurs générations sont jouables --}}
                @if ($startersByGeneration->count() > 1)
                    <div class="tabs" role="tablist">
                        @foreach ($startersByGeneration as $generation => $starters)
                            <button type="button" class="tab" role="tab" data-generation="{{ $generation }}"
                                    aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                {{ $regions[$generation] ?? "Gen {$generation}" }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @foreach ($startersByGeneration as $generation => $starters)
                    <div class="choices three starter-panel" data-generation="{{ $generation }}" @if (! $loop->first) hidden @endif>
                        @foreach ($starters as $starter)
                            <div class="choice starter">
                                <input type="radio" name="starter_species_id" id="starter-{{ $starter->id }}" value="{{ $starter->id }}"
                                       @checked((int) old('starter_species_id') === $starter->id)
                                       data-name="{{ $starter->name }}"
                                       data-category="{{ $starter->category }}"
                                       data-entry="{{ $starter->pokedex_entry }}">
                                <label for="starter-{{ $starter->id }}">
                                    <img class="px" src="{{ $starter->sprite('front') }}" alt="">
                                    <span class="starter-name">{{ $starter->name }}</span>
                                    <span class="types">
                                        @foreach ($starter->types() as $type)
                                            <span class="type" style="background: {{ $type->color }}">{{ $type->name }}</span>
                                        @endforeach
                                    </span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                <div class="dex-entry" id="dex-entry">
                    <span class="placeholder">Clique sur un Pokémon pour en savoir plus.</span>
                </div>
                @error('starter_species_id') <div class="error">{{ $message }}</div> @enderror
            </section>

            <button type="submit" class="btn block">Commencer l'aventure</button>
        </form>

        <div class="below"><a href="{{ route('dashboard') }}">Annuler</a></div>
    </div>

    <script>
        // Onglets par région : seuls les 3 starters de la région choisie sont affichés
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.setAttribute('aria-selected', t === tab ? 'true' : 'false'));
                document.querySelectorAll('.starter-panel').forEach(panel => {
                    panel.hidden = panel.dataset.generation !== tab.dataset.generation;
                });
            });
        });

        // Description Pokédex du starter sélectionné
        const entry = document.getElementById('dex-entry');
        function showEntry(input) {
            entry.replaceChildren();
            const title = document.createElement('strong');
            title.textContent = input.dataset.name + (input.dataset.category ? ' — ' + input.dataset.category : '');
            entry.append(title, document.createTextNode(input.dataset.entry || ''));
        }
        document.querySelectorAll('input[name="starter_species_id"]').forEach(input => {
            input.addEventListener('change', () => showEntry(input));
            if (input.checked) {
                showEntry(input);
                // Après une erreur de validation, rouvrir l'onglet du starter déjà choisi
                const panel = input.closest('.starter-panel');
                document.querySelector(`.tab[data-generation="${panel.dataset.generation}"]`)?.click();
            }
        });
    </script>
@endsection
