@extends('wiki.layout')

@section('title', 'Guide du joueur')

{{-- Wiki : le guide du joueur (WikiGuideController::guide) --}}

@section('wiki')
    <div class="box wiki-text">
        <h1>Guide du joueur</h1>
        <p class="lead">Tout ce qu'il faut pour bien commencer : les touches, les menus, et la vie avec les autres joueurs.</p>

        <h2>Les touches</h2>
        <table class="wiki-table">
            <tr><th>Action</th><th>Clavier</th><th>Souris</th><th>Manette tactile</th></tr>
            <tr><td>Se déplacer</td><td>Flèches ou Z Q S D</td><td>—</td><td>Croix (le pouce peut glisser)</td></tr>
            <tr><td>Courir</td><td>Maj (en marchant)</td><td>—</td><td>B maintenu</td></tr>
            <tr><td>Parler, ramasser, valider</td><td>Espace, Entrée ou E</td><td>Clic gauche</td><td>A, ou toucher l'écran</td></tr>
            <tr><td>Retour, ouvrir le menu</td><td>Échap, X ou M</td><td>Clic droit</td><td>B (Retour), MENU</td></tr>
            <tr><td>Monter sur la Bicyclette / en descendre</td><td>B</td><td>—</td><td>VÉLO</td></tr>
            <tr><td>Pêcher avec la meilleure canne (face à l'eau)</td><td>F</td><td>—</td><td>PÊCHE</td></tr>
            <tr><td>Ouvrir le chat</td><td>T (Échap pour fermer)</td><td>—</td><td>CHAT (A envoie, B ferme)</td></tr>
            <tr><td>Faire défiler une liste</td><td>Flèches</td><td>Molette</td><td>Croix maintenue</td></tr>
        </table>
        <p>
            <strong>Sur téléphone ou tablette</strong>, une manette apparaît : la croix à gauche, A et B à droite
            (sous l'écran en portrait, de chaque côté en paysage). Le bouton <strong>Manette</strong>, en haut, l'affiche
            ou la cache (même sur ordinateur), et <strong>Plein écran</strong> cache la barre du navigateur.
            Pour un surnom, le clavier du téléphone s'ouvre : A valide, B annule.
        </p>

        <h2>Le menu</h2>
        <ul>
            <li><strong>Pokédex</strong> : les Pokémon vus et capturés, et où les trouver. Le Professeur offre des récompenses quand vous en capturez beaucoup.</li>
            <li><strong>Pokémon</strong> : votre équipe (6 au plus). Résumé, surnom, capacités de terrain (Coupe, Vol...), changer l'ordre, donner ou prendre un objet.</li>
            <li><strong>Sac</strong> : 8 poches, comme dans les jeux. Utiliser, donner à tenir ou jeter un objet.</li>
            <li><strong>Votre nom</strong> : la Carte Dresseur (badges, argent, temps de jeu).</li>
            <li><strong>Sauvegarder</strong> : le jeu sauvegarde aussi <strong>tout seul</strong>, régulièrement et à chaque changement de carte.</li>
            <li><strong>Options</strong> : le volume de la musique et des effets.</li>
        </ul>

        <h2>Soigner, ranger, acheter</h2>
        <ul>
            <li><strong>Centre Pokémon</strong> : parlez à l'infirmière pour soigner toute l'équipe. Si toute votre équipe est K.O. en combat, vous revenez au dernier Centre où vous vous êtes soigné (et perdez un peu d'argent).</li>
            <li><strong>PC</strong> : 8 boîtes de 30 Pokémon. Une capture avec une équipe pleine va directement au PC.</li>
            <li><strong>Boutique</strong> : le choix grandit avec le nombre de badges. Une <strong>Honor Ball</strong> offerte pour 10 Balls achetées d'un coup. Voir <a href="{{ route('wiki.items') }}">Objets</a>.</li>
            <li><strong>Changer de capacités</strong> : Menu &gt; Pokémon &gt; Résumé &gt; page Capacités. Choisissez une capacité, puis celle qui la remplace : toutes celles que le Pokémon apprend par niveau jusqu'à son niveau (et ses formes précédentes), et toutes celles qu'il a déjà apprises une fois (CT, CS...). C'est gratuit, hors combat.</li>
        </ul>

        <h2>Les capacités de terrain (CS)</h2>
        <p>Un Pokémon qui connaît ces capacités peut les utiliser hors combat (menu Pokémon, ou Action devant l'obstacle)@if ($fieldBadges), à condition d'avoir le badge d'arène :@else.@endif</p>
        @if ($fieldBadges)
            <table class="wiki-table">
                <tr><th>Capacité</th><th>Badge nécessaire</th><th>Utilité</th></tr>
                @php $uses = ['cut' => 'Couper les petits arbres', 'fly' => 'Voler vers une ville déjà visitée', 'surf' => 'Se déplacer sur l\'eau',
                    'strength' => 'Pousser les gros rochers', 'flash' => 'Éclairer les grottes sombres', 'rock-smash' => 'Briser les rochers fissurés (un Pokémon peut s\'en échapper)']; @endphp
                @foreach ($fieldBadges as $slug => $badge)
                    <tr><td>{{ $moves[$slug] ?? $slug }}</td><td>Badge n° {{ $badge }}</td><td>{{ $uses[$slug] ?? '' }}</td></tr>
                @endforeach
            </table>
        @endif
        <p>Une CS ne s'oublie pas en apprenant une nouvelle capacité : changez-la depuis le Résumé (page Capacités), elle restera disponible pour plus tard.</p>

        <h2>Avec les autres joueurs</h2>
        <ul>
            <li><strong>Le chat</strong> (touche T), quatre canaux :
                <ul>
                    <li><strong>[Local]</strong> : un message tapé tel quel est lu par les joueurs de la même carte ;</li>
                    <li><strong>[Global]</strong> : <code>/all Message</code> (ou <code>/monde</code>) est lu par tous les joueurs connectés ;</li>
                    <li><strong>[MP]</strong> : <code>/msg Pseudo Message</code> n'est lu que par ce joueur (pseudo avec des espaces : entre guillemets) ; <code>/r Message</code> répond au dernier qui vous a écrit ;</li>
                    <li><strong>[Groupe]</strong> : <code>/g Message</code> est lu par votre groupe.</li>
                </ul>
                <code>/aide</code> donne la liste. Les messages des administrateurs sont en rouge, marqués <strong>[Admin]</strong>. Ceux des <strong>[Professeur]</strong> sont en vert (un titre d'honneur, sans pouvoir particulier).</li>
            <li><strong>Les groupes</strong> ({{ config('chat.group_size', 4) }} joueurs au plus) : invitez un joueur avec <code>/inviter Pseudo</code>, avec Action devant lui (« Inviter dans le groupe »), ou depuis Menu &gt; <strong>Groupe</strong>. Le menu Groupe montre les membres, le chef (★) et la carte où chacun se trouve. Le chef peut exclure un membre (<code>/exclure Pseudo</code>) ou donner sa place (<code>/chef Pseudo</code>) ; <code>/quitter</code> pour partir. Le chat du groupe annonce les arrivées, les départs et les connexions des membres. Le groupe reste d'une partie à l'autre.</li>
            <li><strong>Une seule partie à la fois</strong> par compte : si vous ouvrez le jeu dans un autre onglet ou sur un autre appareil, l'ancienne fenêtre se met en pause (bouton « Jouer ici » pour la reprendre).</li>
            <li><strong>Échanger</strong> : allez voir un autre joueur et appuyez sur Action, puis « Échanger ». Chacun propose un Pokémon, puis confirme. Certains Pokémon évoluent pendant l'échange (voir <a href="{{ route('wiki.evolutions') }}">Évolutions</a>).</li>
            <li><strong>Combattre</strong> : même chose avec « Combattre ». Les objets du Sac sont interdits dans les combats entre joueurs.</li>
            <li>Un Pokémon reçu par échange vient d'un <strong>autre dresseur</strong> : il garde son nom, et il peut <strong>désobéir</strong> s'il est trop fort pour vos badges (voir <a href="{{ route('wiki.battle') }}#obeissance">Règles du combat</a>). En revanche, il aide pour la <a href="{{ route('wiki.daycare') }}">méthode Masuda</a> !</li>
        </ul>

        <h2>Le Tenant du titre</h2>
        <p>Un dresseur spécial que tout le monde peut défier, avec un niveau maximum pour votre équipe. Celui qui le bat <strong>prend sa place</strong> : les autres joueurs affrontent alors une copie de l'équipe qu'il avait au moment de sa victoire, jusqu'à ce que quelqu'un le batte à son tour. Le panneau à côté indique le tenant actuel et ses défenses réussies.</p>
    </div>
@endsection
