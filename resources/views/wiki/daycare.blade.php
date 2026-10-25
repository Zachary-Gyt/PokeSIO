@extends('wiki.layout')

@section('title', 'Pension')

{{-- Étape 18 : la Pension et les Œufs (WikiController::daycare). Les chiffres viennent de config/breeding.php. --}}

@push('styles')
<style>
    .daycare h2 { margin: 22px 0 6px; }
    .daycare p, .daycare li { font-size: 21px; }
    .daycare ul { margin: 4px 0 8px; padding-left: 22px; }
    table.wiki-table { width: 100%; border-collapse: collapse; font-size: 20px; margin: 6px 0 10px; }
    .wiki-table th { text-align: left; color: #909098; font-weight: normal; border-bottom: 2px solid #d8d8d0; padding: 2px 6px; }
    .wiki-table td { padding: 2px 6px; border-bottom: 1px solid #ecece4; vertical-align: top; }
    .note { border-left: 4px solid var(--blue); background: #eef3fc; padding: 6px 10px; margin: 8px 0; font-size: 20px; }
    .egg-group { margin: 8px 0 14px; }
    .egg-group h3 { margin: 0 0 4px; font-size: 24px; }
    .egg-list { display: flex; flex-wrap: wrap; gap: 4px; }
    .egg-mon { display: inline-flex; align-items: center; gap: 2px; padding: 0 8px 0 0; border: 2px solid #d8d8d0; background: #fbfbf8;
        text-decoration: none; color: var(--ink) !important; font-size: 18px; }
    .egg-mon:hover { border-color: var(--blue); background: #fff; }
    .egg-mon img { width: 32px; height: 32px; object-fit: cover; object-position: left; image-rendering: pixelated; }
    .egg-mon small { color: #909098; }
    .group-tools { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
    .group-tools input { max-width: 280px; }
    .egg-group[hidden], .egg-mon[hidden] { display: none; }
</style>
@endpush

@section('wiki')
    <div class="box daycare">
        <h1>Pension</h1>
        <p class="lead">La Pension garde jusqu'à deux de vos Pokémon. Ils gagnent de l'expérience pendant que vous marchez, et deux Pokémon qui s'entendent peuvent avoir un Œuf.</p>

        <h2>Confier et reprendre un Pokémon</h2>
        <ul>
            <li>Parlez à la <strong>responsable de la Pension</strong> pour confier un Pokémon de votre équipe. Vous pouvez en laisser <strong>deux</strong> au plus.</li>
            <li>Vous devez garder au moins un Pokémon en état de se battre dans votre équipe. Un Œuf ne peut pas être confié.</li>
            <li>Le Pokémon garde l'objet qu'il tient (c'est utile pour l'hérédité, voir plus bas).</li>
            <li>Chaque <strong>pas</strong> que vous faites donne <strong>1 point d'expérience</strong> à chaque Pokémon confié. En montant de niveau, il apprend ses nouvelles capacités ; s'il en connaît déjà 4, il oublie la plus ancienne. Il <strong>n'évolue pas</strong> à la Pension.</li>
            <li>Pour le reprendre, il faut une place dans l'équipe et payer : <strong>(niveaux gagnés + 1) × {{ $price }} ₽</strong>. Sans niveau gagné : {{ $price }} ₽ ; 3 niveaux gagnés : {{ 4 * $price }} ₽.</li>
        </ul>

        <h2>Avoir un Œuf</h2>
        <p>Tous les <strong>{{ $every }} pas</strong>, si les deux Pokémon confiés sont compatibles, ils peuvent avoir un Œuf. Il est <strong>envoyé directement au PC</strong> (première case libre), et un message dans le chat vous prévient. Si le PC est plein, l'Œuf est perdu.</p>
        <p>Deux Pokémon sont compatibles quand :</p>
        <ul>
            <li>l'un est <strong>mâle</strong> et l'autre <strong>femelle</strong>, et ils ont au moins un <a href="#groupes">groupe d'Œuf</a> en commun ;</li>
            <li>ou l'un des deux est <strong>Métamorph</strong> : il est compatible avec n'importe quel Pokémon qui peut se reproduire, même sans sexe (Magnéti, Voltorbe, Stari, Porygon...). Deux Métamorph ne donnent rien.</li>
        </ul>
        <p>Les Pokémon <strong>légendaires</strong> et ceux du groupe <strong>Inconnu</strong> (Nidorina, Nidoqueen...) ne se reproduisent jamais.</p>

        <table class="wiki-table">
            <tr><th>Les deux Pokémon...</th><th>La responsable dit</th><th>Chance par tentative</th><th>Avec le Charme Ovale</th></tr>
            <tr><td>même espèce, dresseurs d'origine différents</td><td>« Ils ont l'air de très bien s'entendre ! »</td><td>{{ $chances['very_well'] }} %</td><td>{{ $charm['very_well'] }} %</td></tr>
            <tr><td>même espèce et même dresseur, ou espèces différentes de dresseurs différents</td><td>« Ils ont l'air de bien s'entendre. »</td><td>{{ $chances['well'] }} %</td><td>{{ $charm['well'] }} %</td></tr>
            <tr><td>espèces différentes, même dresseur d'origine</td><td>« Ils ne s'entendent pas très bien. »</td><td>{{ $chances['little'] }} %</td><td>{{ $charm['little'] }} %</td></tr>
            <tr><td>incompatibles</td><td>« Ils préfèrent jouer avec d'autres Pokémon. »</td><td>0 %</td><td>0 %</td></tr>
        </table>
        <div class="note">Le <strong>Charme Ovale</strong> est un objet rare : il suffit de l'avoir dans son Sac. Le « dresseur d'origine » est le D.O. affiché dans le Résumé : un Pokémon reçu par échange vient d'un autre dresseur.</div>

        <h2>Ce qui sort de l'Œuf</h2>
        <ul>
            <li><strong>Espèce</strong> : la première forme de l'évolution de la <strong>femelle</strong> (une Florizarre donne un Bulbizarre ; un Pikachu donne un Pichu). Avec Métamorph : celle de l'autre parent. Une Nidoran♀ peut donner un Nidoran♀ ou un Nidoran♂, une Lumivole un Muciole ou une Lumivole.</li>
            <li><strong>Bébés à Encens</strong> : certains bébés ne naissent que si un parent tient un Encens ; sinon l'Œuf donne la forme suivante.
                <table class="wiki-table">
                    <tr><th>Bébé</th><th>Encens à faire tenir</th><th>Sans Encens</th></tr>
                    @foreach ($incense as $row)
                        <tr><td>{{ $row['baby'] }}</td><td>{{ $row['item'] }}</td><td>{{ $row['next'] }}</td></tr>
                    @endforeach
                </table>
            </li>
            <li><strong>Niveau</strong> : le bébé naît au niveau 1. Son sexe est tiré au hasard.</li>
            <li><strong>IV</strong> : {{ $ivs }} de ses 6 IV viennent de l'un ou l'autre parent, les autres sont tirés au hasard. Si un parent tient le <strong>Nœud Destin</strong> : {{ $knotIvs }} IV hérités.</li>
            <li><strong>Nature</strong> : si un parent tient une <strong>Pierre Stase</strong>, le bébé a la même nature que lui ; sinon elle est tirée au hasard.</li>
            <li><strong>Talent</strong> : la mère (ou le parent qui n'est pas Métamorph) transmet son talent dans {{ $slot }} % des cas ; son talent caché dans {{ $hidden }} % des cas.</li>
            <li><strong>Poké Ball</strong> : celle de la mère (sauf Master Ball et Safari Ball : Poké Ball).</li>
            <li><strong>Capacités</strong> (4 au plus) :
                <ul>
                    <li>celles que le bébé connaît au niveau 1 ;</li>
                    <li>celles que <strong>les deux parents</strong> connaissent et que le bébé apprend en montant de niveau ;</li>
                    <li>ses <strong>capacités Œuf</strong> (apprises seulement par reproduction) que l'un ou l'autre parent connaît.</li>
                </ul>
            </li>
            <li><strong>Chromatique</strong> : 1 chance sur {{ $shinyOdds }}, comme un Pokémon sauvage. <strong>Méthode Masuda</strong> : si les deux parents viennent de dresseurs d'origine différents, {{ $masuda }} fois plus de chances (environ 1 sur {{ intdiv($shinyOdds, max(1, $masuda)) }}). Échangez vos Pokémon avec les autres joueurs !</li>
        </ul>

        <h2>L'éclosion</h2>
        <ul>
            <li>Mettez l'Œuf dans votre <strong>équipe</strong> (avec le PC) : seuls les Œufs de l'équipe avancent.</li>
            <li>Il éclot après un nombre de pas qui dépend de l'espèce : <strong>cycles × {{ $cycle }} pas</strong> (Bulbizarre : 20 cycles, soit {{ number_format(20 * $cycle, 0, ',', ' ') }} pas). Le Résumé de l'Œuf dit s'il est encore loin de l'éclosion.</li>
            <li>Un Pokémon de l'équipe avec le talent <strong>Corps Ardent</strong> ou <strong>Armumagma</strong> (Ponyta, Magmar, Magby, Chartor, Volcaropod...) fait éclore les Œufs <strong>2 fois plus vite</strong>.</li>
            <li>Un Œuf ne combat pas, ne tient pas d'objet, ne peut pas être échangé ni relâché.</li>
            <li>À l'éclosion, le bébé est à vous : vous êtes son dresseur d'origine, et il est ajouté au Pokédex.</li>
        </ul>

        <h2 id="groupes">Les groupes d'Œuf</h2>
        <p>Deux Pokémon (mâle et femelle) peuvent avoir un Œuf s'ils partagent au moins un groupe. Le nombre en gris est le nombre de pas avant l'éclosion.</p>
        <div class="group-tools">
            <input type="text" id="group-search" placeholder="Chercher un Pokémon..." autocomplete="off">
        </div>
        @foreach ($groups as $group)
            <div class="egg-group" data-group="{{ $group['key'] }}">
                <h3>{{ $group['name'] }} <small style="color:#909098">({{ $group['species']->count() }})</small></h3>
                <div class="egg-list">
                    @foreach ($group['species'] as $s)
                        <a class="egg-mon" href="{{ route('wiki.pokemon', $s->slug) }}" data-search="{{ mb_strtolower($s->name) }} {{ $s->slug }} {{ $s->pokedex_number }}">
                            @if ($s->sprite('icon'))<img src="{{ $s->sprite('icon') }}" alt="" loading="lazy">@endif
                            {{ $s->name }}
                            @if (! in_array($group['key'], ['no-eggs', 'ditto'], true))
                                <small>&nbsp;{{ number_format($s->hatch_cycles * $cycle, 0, ',', ' ') }}</small>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endsection

@push('scripts')
<script>
    // Recherche : n'affiche que les groupes qui contiennent ce Pokémon
    (() => {
        const input = document.getElementById('group-search');
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            for (const group of document.querySelectorAll('.egg-group')) {
                let shown = 0;
                for (const mon of group.querySelectorAll('.egg-mon')) {
                    const ok = !q || mon.dataset.search.includes(q);
                    mon.hidden = !ok;
                    if (ok) shown++;
                }
                group.hidden = shown === 0;
            }
        });
    })();
</script>
@endpush
