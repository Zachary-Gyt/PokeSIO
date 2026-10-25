@extends('wiki.layout')

@section('title', 'Règles du combat')

{{-- Wiki : les règles du combat (WikiGuideController::battle). Les chiffres viennent du moteur et de config/items.php --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Règles du combat</h1>
        <p class="lead">Les combats suivent les règles de la 4e génération (Diamant, Perle, Platine), avec quelques réglages des jeux plus récents.</p>

        <h2 id="tour">Qui attaque en premier ?</h2>
        <ol>
            <li>Changer de Pokémon, utiliser un objet ou fuir passe <strong>toujours avant</strong> les attaques.</li>
            <li>Puis la <strong>priorité</strong> de la capacité : Vive-Attaque (+1) passe avant une capacité normale (0), Riposte (−5) passe après.</li>
            <li>À priorité égale, le plus <strong>rapide</strong> attaque d'abord (Paralysie : Vitesse divisée par 4). En cas d'égalité : au hasard.</li>
        </ol>

        <h2 id="degats">Les dégâts</h2>
        <ul>
            <li><strong>Physique</strong> ou <strong>Spéciale</strong> : depuis la 4e génération, c'est la capacité elle-même qui décide (et non plus son type). Physique : Attaque contre Défense ; Spéciale : Attaque Spé. contre Défense Spé.</li>
            <li><strong>Même type (STAB)</strong> : ×1,5 si le Pokémon a le type de sa capacité (×2 avec le talent Adaptabilité).</li>
            <li><strong>Efficacité</strong> : ×2, ×½ ou ×0 pour chaque type de la cible (donc jusqu'à ×4 ou ×¼). Voir la <a href="{{ route('wiki.types') }}">table des types</a>.</li>
            <li><strong>Coup critique</strong> : ×1,5, il ignore les baisses de stats de l'attaquant et les hausses de défense de la cible. Chance : 1/24, puis 1/8, 1/2 et toujours selon le taux de critique (capacités comme Tranche, objets Lentilscope, Muscle +...).</li>
            <li><strong>Hasard</strong> : les dégâts varient de 85 % à 100 %.</li>
            <li><strong>Brûlure</strong> : les dégâts physiques sont divisés par 2.</li>
            <li><strong>Météo</strong> : Soleil (Feu ×1,5, Eau ×½), Pluie (l'inverse), Tempête de sable et Grêle (dégâts chaque tour, sauf certains types).</li>
        </ul>

        <h2 id="stats">Les niveaux de stats</h2>
        <p>Rugissement, Danse-Lames... changent les stats par <strong>niveaux</strong>, de −6 à +6. Niveau +1 : ×1,5 ; +2 : ×2 ; ... +6 : ×4 (et −1 : ×⅔ ; ... −6 : ×¼). Précision et Esquive vont de ×⅓ à ×3. Tout revient à zéro quand le Pokémon quitte le terrain.</p>

        <h2 id="statuts">Les statuts</h2>
        <table class="wiki-table">
            <tr><th>Statut</th><th>Effet</th></tr>
            <tr><td>Brûlure</td><td>Perd 1/8 de ses PV max. à chaque tour, dégâts physiques ÷ 2. Les Pokémon Feu ne sont jamais brûlés.</td></tr>
            <tr><td>Gel</td><td>Ne peut pas attaquer ; 20 % de chances de dégeler à chaque tour (ou touché par une capacité Feu). Les Pokémon Glace ne gèlent pas.</td></tr>
            <tr><td>Paralysie</td><td>Vitesse ÷ 4 et 25 % de chances de ne pas pouvoir attaquer. Les Pokémon Électrik sont protégés contre Cage-Éclair.</td></tr>
            <tr><td>Poison</td><td>Perd 1/8 de ses PV max. à chaque tour.</td></tr>
            <tr><td>Poison grave (Toxik)</td><td>1/16, puis 2/16, 3/16... de plus en plus fort. Redevient un poison normal après le combat.</td></tr>
            <tr><td>Sommeil</td><td>Ne peut pas attaquer pendant 1 à 3 tours.</td></tr>
            <tr><td>Confusion</td><td>1 à 4 tours ; 1 chance sur 3 de se blesser au lieu d'attaquer. Disparaît si le Pokémon quitte le terrain.</td></tr>
        </table>
        <p>Un seul statut à la fois (sauf la Confusion, qui s'ajoute). Les statuts restent après le combat, sauf la Confusion.</p>

        <h2 id="fuite">Fuir un Pokémon sauvage</h2>
        <p>Si votre Pokémon est plus rapide, la fuite réussit toujours. Sinon, la chance est de (Vitesse × 128 ÷ Vitesse de l'adversaire + 30 × tentatives) sur 256. Impossible de fuir un combat contre un dresseur. Poupée Pokémon et Queue Skitty : fuite assurée.</p>

        <h2 id="capture">Capturer</h2>
        <ul>
            <li>Plus le Pokémon a <strong>peu de PV</strong>, plus il est facile à attraper.</li>
            <li><strong>Statut</strong> : ×2,5 s'il est endormi ou gelé, ×1,5 pour les autres statuts.</li>
            <li><strong>La Ball</strong> : Super Ball ×1,5, Hyper Ball ×2, et les Balls spéciales selon la situation (voir <a href="{{ route('wiki.items') }}">Objets</a>).</li>
            <li>Chaque espèce a son <strong>taux de capture</strong> (dans sa fiche du Pokédex) : 255 pour Chenipan, 3 pour les légendaires.</li>
        </ul>

        <h2 id="experience">Expérience et EV</h2>
        <ul>
            <li>L'expérience dépend du niveau du vaincu <strong>et</strong> de celui du vainqueur : battre un Pokémon de plus haut niveau rapporte plus (formule de la 5e génération).</li>
            <li>×1,5 contre un dresseur ; ×1,5 avec un Œuf Chance.</li>
            <li>Elle est <strong>partagée</strong> entre les Pokémon qui ont participé. Le <strong>Multi Exp</strong> en donne aussi à celui qui le tient.</li>
            <li>Chaque victoire donne aussi des <strong>EV</strong> (points d'effort) : 252 au plus par stat, 510 au total. 4 EV = 1 point de stat au niveau 100.</li>
        </ul>

        <h2 id="obeissance">Obéissance</h2>
        <p>Un Pokémon reçu par <strong>échange</strong> (d'un autre dresseur) n'obéit que jusqu'à un certain niveau, selon vos badges. Au-delà, il peut ignorer vos ordres, faire une autre capacité, ou s'endormir.</p>
        <table class="wiki-table">
            <tr><th>Badges</th>@foreach ($obey as $badges => $cap)<th>{{ $badges }}</th>@endforeach</tr>
            <tr><td>Obéit jusqu'au niveau</td>@foreach ($obey as $cap)<td>{{ $cap >= 100 ? 'Toujours' : $cap }}</td>@endforeach</tr>
        </table>

        <h2 id="bonheur">Le Bonheur</h2>
        <p>Chaque Pokémon a un <strong>Bonheur</strong> de 0 à 255. Un Pokémon sauvage commence à 70 (Copain Ball : {{ $friendship['friend_ball'] ?? 200 }}). Certains évoluent avec 160 au moins (Évoli en Mentali, Nostenfer...), et Retour devient plus fort.</p>
        @php $labels = [
            'walking' => 'Marcher (' . ($friendship['walking_steps'] ?? 128) . ' pas)', 'levelup' => 'Monter d\'un niveau', 'vitamin' => 'Vitamine (PV Plus, Protéine...)',
            'evberry' => 'Baie qui baisse les EV', 'battleitem' => 'Objet de combat (Attaque +...)', 'gym' => 'Combat contre un Champion',
            'faint' => 'Tomber K.O.', 'powder' => 'Poudre Soin, Poudrénergie', 'energy_root' => 'Racinénergie', 'revival_herb' => 'Herbe Rappel',
        ]; @endphp
        <table class="wiki-table">
            <tr><th>Action</th><th>Bonheur &lt; 100</th><th>100 à 199</th><th>200 et +</th></tr>
            @foreach ($friendship['gains'] ?? [] as $key => $gain)
                <tr><td>{{ $labels[$key] ?? $key }}</td>@foreach ($gain as $g)<td>{{ $g > 0 ? '+' . $g : $g }}</td>@endforeach</tr>
            @endforeach
        </table>
        <p class="muted">Grelot Zen : gains ×1,5. Luxe Ball : +1 à chaque gain. Ce sont les règles de la 4e génération.</p>

        <h2 id="ko">Toute l'équipe K.O.</h2>
        <p>Vous perdez de l'argent : <strong>(niveau de votre Pokémon le plus fort) × un montant qui dépend de vos badges</strong>, puis vous revenez au dernier Centre Pokémon.</p>
        <table class="wiki-table">
            <tr><th>Badges</th>@foreach ($whiteout as $badges => $base)<th>{{ $badges }}</th>@endforeach</tr>
            <tr><td>× niveau</td>@foreach ($whiteout as $base)<td>{{ $base }} ₽</td>@endforeach</tr>
        </table>

        <h2 id="joueurs">Entre joueurs</h2>
        <p>C'est un combat <strong>amical</strong> : les équipes sont soignées au départ, et rien ne change sur vos vrais Pokémon à la fin (ni PV, ni PP, ni expérience). Pas d'objets, pas de fuite ; abandonner ou ne plus répondre donne la victoire à l'autre joueur.</p>
    </div>
@endsection
