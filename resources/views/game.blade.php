<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Étape 29 : sur téléphone, le jeu peut être ajouté à l'écran d'accueil et s'ouvrir sans barre du navigateur --}}
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#14161c">
    {{-- Jeton anti-CSRF, lu par SaveManager.js (l'équivalent de @csrf pour les requêtes JavaScript) --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $character->name }} — PokéSIO</title>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    {{-- Étape 29 : les commandes tactiles --}}
    <link href="{{ asset('css/touch.css') }}?v={{ $version }}" rel="stylesheet">
    <style>
        :root { --bar-h: 44px; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: #14161c; color: #e8ebf2; overflow: hidden; }
        body { font-family: 'Press Start 2P', monospace; }

        .bar {
            height: var(--bar-h);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 14px; font: 20px 'Power Green', monospace;
            background: #1d2029; border-bottom: 2px solid #2b3040; color: #e8ebf2;
        }
        .bar a, .bar button { color: #e8ebf2; text-decoration: none; background: none; border: 0; padding: 0; font: inherit; cursor: pointer; }
        .bar a:hover, .bar button:hover { color: #ffcb05; }
        .bar .brand { color: #ffcb05; text-shadow: 2px 2px 0 #2a4a8c; }
        .bar .who { color: #8890a0; margin-left: 10px; }
        .bar nav { display: flex; gap: 20px; align-items: center; }
        .bar form { margin: 0; }

        /* Zone de jeu au format 4:3, la plus grande possible */
        .stage {
            height: calc(100% - var(--bar-h));
            display: flex; align-items: center; justify-content: center;
            padding: 12px;
        }
        .screen {
            position: relative;
            aspect-ratio: 4 / 3;  /* 512 x 384, comme Pokémon Essentials */
            width: min(100%, (100vh - var(--bar-h) - 24px) * 4 / 3);
            border: 4px solid #000;
            box-shadow: 0 0 0 4px #2b3040;
            background: #000;
        }
        #game { position: absolute; inset: 0; }

        .session-expired {
            position: absolute; top: 8px; left: 50%; transform: translateX(-50%);
            z-index: 5;
            background: #c62828; color: #fff;
            padding: 8px 14px; border-radius: 6px;
            font: 13px system-ui, sans-serif;
        }
        .session-expired[hidden] { display: none; }
        .session-expired a { color: #fff; }
        #game canvas { display: block; }

        /* ---- Interface façon Pokémon Essentials -------------------------------- */

        /* Polices d'Essentials (fichiers dans public/game/fonts) */
        @font-face {
            font-family: 'Power Green';
            src: url('{{ asset('game') }}/fonts/power_green.ttf') format('truetype');
            font-display: block;
        }
        @font-face {
            font-family: 'Power Green Small';
            src: url('{{ asset('game') }}/fonts/power_green_small.ttf') format('truetype');
            font-display: block;
        }
        @font-face {
            font-family: 'Power Green Narrow';
            src: url('{{ asset('game') }}/fonts/power_green_narrow.ttf') format('truetype');
            font-display: block;
        }

        /* L'écran de jeu devient un "conteneur" : 100cqw = sa largeur exacte */
        .screen { container-type: inline-size; }

        /* Calque transparent par-dessus le jeu. --gp = UN pixel du jeu (le jeu fait 512 px de large).
           Toutes les tailles ci-dessous sont en pixels du jeu, comme dans Essentials. */
        #game-ui {
            --gp: calc(100cqw / 512);
            position: absolute; inset: 0;
            pointer-events: none; overflow: hidden;
            font-family: 'Power Green', monospace;
        }
        #game-ui [hidden] { display: none !important; }

        /* Cadre "9-slice" : l'image est découpée en 9 morceaux (bords de 18 px).
           Les 4 coins restent intacts, les bords et le centre s'étirent : le cadre s'adapte à toute taille. */
        .dialog, .dialog-name {
            position: absolute;
            box-sizing: border-box;
            border-style: solid;
            border-width: calc(18 * var(--gp));
            border-image: url('{{ asset('game') }}/ui/dialog.png') 18 fill / calc(18 * var(--gp)) stretch;
            image-rendering: pixelated;          /* pixels nets, sans flou */
            color: #505058;                      /* couleurs du texte sombre d'Essentials */
            text-shadow:
                calc(2 * var(--gp)) 0 0 #a0a0a8,
                0 calc(2 * var(--gp)) 0 #a0a0a8,
                calc(2 * var(--gp)) calc(2 * var(--gp)) 0 #a0a0a8;
        }

        /* Boîte de dialogue : 512 x 96, en bas de l'écran (exactement comme Essentials) */
        .dialog {
            left: 0; bottom: 0;
            width: calc(512 * var(--gp));
            height: calc(96 * var(--gp));
            padding: calc(1 * var(--gp)) calc(10 * var(--gp)) 0;
            font-size: calc(27 * var(--gp));
            line-height: calc(32 * var(--gp));   /* 2 lignes de 32 px */
        }
        .dialog p {
            margin: 0;
            white-space: pre-line;
            height: calc(64 * var(--gp));          /* 2 lignes : sert aussi à mesurer le découpage en pages */
            position: relative; top: calc(-1 * var(--gp));
        }

        /* Petite fenêtre du nom, au-dessus de la boîte */
        .dialog-name {
            left: 0; bottom: calc(90 * var(--gp));
            display: flex; align-items: center; justify-content: center; /* nom centré dans la fenêtre */
            padding: calc(2 * var(--gp)) calc(8 * var(--gp)) 0;   /* étape 16 : nom centré (police corrigée) */
            line-height: calc(32 * var(--gp));
            min-width: calc(112 * var(--gp));
            height: calc(58 * var(--gp));
            font-size: calc(27 * var(--gp));
            white-space: nowrap;
        }

        /* Message d'information (multijoueur) : le cadre des dialogues, mais en HAUT, et sans attendre de touche */
        .dialog.game-toast { top: 0; bottom: auto; }

        /* Chat (touche T) : journal en bas à gauche, zone de saisie avec le cadre des dialogues */
        .chat-log {
            position: absolute; left: calc(6 * var(--gp)); bottom: calc(102 * var(--gp));
            max-width: calc(330 * var(--gp));
            padding: calc(4 * var(--gp)) calc(8 * var(--gp));
            background: rgba(24, 24, 32, 0.55); border-radius: calc(4 * var(--gp));
            font-size: calc(20 * var(--gp)); line-height: calc(20 * var(--gp));
            color: #f8f8f8; text-shadow: calc(1 * var(--gp)) calc(1 * var(--gp)) 0 #282830;
        }
        .chat-line { overflow-wrap: anywhere; }
        .chat-name { color: #f8d030; }
        .chat-name.chat-me { color: #78c8f8; }
        .chat-system { color: #f0a0a0; }
        .chat-name.chat-admin { color: #ff6060; }   /* étape 10 : les administrateurs */
        .chat-name.chat-professor { color: #78d878; }   /* le grade Professeur */

        /* Une seule partie à la fois : cette fenêtre a été remplacée par une autre */
        .elsewhere {
            position: absolute; inset: 0; z-index: 20;
            display: flex; align-items: center; justify-content: center;
            background: rgba(10, 12, 18, .85);
            font: 20px 'Power Green', monospace; color: #e8ebf2; text-align: center;
        }
        .elsewhere-box { max-width: 80%; background: #1d2029; border: 3px solid #2b3040; border-radius: 8px; padding: 18px 22px; }
        .elsewhere-box p { margin: 0 0 10px; }
        .elsewhere-small { font-size: 16px; color: #8890a0; }
        .elsewhere-actions { display: flex; gap: 18px; justify-content: center; align-items: center; margin-top: 8px; }
        .elsewhere-actions button { font: inherit; background: #ffcb05; color: #1d2029; border: 0; border-radius: 6px; padding: 6px 14px; cursor: pointer; }
        .elsewhere-actions a { color: #8890a0; }
        .chat-input {
            position: absolute; left: 0; bottom: 0; margin: 0;
            width: calc(512 * var(--gp)); height: calc(80 * var(--gp));
            box-sizing: border-box; border-style: solid; border-width: calc(18 * var(--gp));
            border-image: url('{{ asset('game') }}/ui/dialog.png') 18 fill / calc(18 * var(--gp)) stretch;
            image-rendering: pixelated; pointer-events: auto;
        }
        /* Le vrai champ de saisie est invisible (les navigateurs rognent les grands jambages de la police) :
           le texte tapé est recopié dans .chat-text, dessiné comme les boîtes de dialogue */
        .chat-input input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; border: 0; }
        .chat-text {
            font-family: 'Power Green', monospace; font-size: calc(24 * var(--gp)); color: #505058;
            white-space: pre; overflow: hidden; line-height: calc(32 * var(--gp));
            position: relative; top: calc(-1 * var(--gp)); padding: 0 calc(6 * var(--gp));
        }
        .chat-text .chat-placeholder { color: #a0a0a8; }
        .chat-text .chat-caret { animation: chat-caret 1s steps(1, end) infinite; }
        @keyframes chat-caret { 50% { opacity: 0; } }

        /* Flèche "appuie pour continuer" d'Essentials : 4 images de 16 x 16 rangées en 2 x 2 */
        .dialog-next {
            position: absolute;
            right: calc(6 * var(--gp)); bottom: calc(4 * var(--gp));
            width: calc(16 * var(--gp)); height: calc(16 * var(--gp));
            background: url('{{ asset('game') }}/ui/pause_arrow.png') 0 0 / calc(32 * var(--gp)) calc(32 * var(--gp));
            image-rendering: pixelated;
            animation: pause-arrow .64s infinite;
            animation-timing-function: steps(1, end); /* change d'image d'un coup, sans glissement */
        }
        @keyframes pause-arrow {
            0%   { background-position: 0 0; }
            25%  { background-position: calc(-16 * var(--gp)) 0; }
            50%  { background-position: 0 calc(-16 * var(--gp)); }
            75%  { background-position: calc(-16 * var(--gp)) calc(-16 * var(--gp)); }
        }

        /* ---- Étape 21 : l'écran de chargement (par-dessus le jeu jusqu'à l'arrivée sur la carte) ---- */
        .loading {
            position: absolute; inset: 0; z-index: 20;
            background: #f8f8f8 url('{{ asset('game/ui/load/bg.png') }}') center / 100% 100% no-repeat;
            image-rendering: pixelated;
            display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
            padding-bottom: 6%;
            transition: opacity .4s;
            font-family: 'Power Green', monospace;
        }
        .loading.done { opacity: 0; pointer-events: none; }
        .loading-title {
            position: absolute; top: 10%; right: 7%;
            font-size: clamp(28px, 7.5vmin, 64px); color: #ffcb05;
            text-shadow: 3px 3px 0 #2a4a8c, -1px -1px 0 #2a4a8c, 1px -1px 0 #2a4a8c, -1px 1px 0 #2a4a8c;
        }
        .loading-hero {
            position: absolute; top: 33%; right: 18%;
            width: 64px; height: 96px; background-repeat: no-repeat; image-rendering: pixelated;
            transform: scale(3); transform-origin: top center;
        }
        .loading-box {
            width: 78%; background: #f8f8f8; border: 4px solid #505058; border-radius: 10px;
            box-shadow: 0 0 0 3px #f8f8f8, 0 4px 0 3px rgba(0,0,0,.25);
            padding: 10px 16px 12px; color: #505058; text-shadow: 2px 2px 0 #c8c8d0;
        }
        .loading-text { font-size: clamp(14px, 3.4vmin, 26px); display: flex; justify-content: space-between; }
        .loading-bar {
            height: 14px; margin: 8px 0 6px; background: #384048; border: 2px solid #505058; border-radius: 4px; overflow: hidden;
        }
        .loading-fill { height: 100%; width: 0; background: linear-gradient(#70f8a8 0 50%, #58d080 50% 100%); transition: width .25s; }
        .loading-tip { font-size: clamp(12px, 2.8vmin, 21px); color: #707078; min-height: 1.2em; }
    </style>
</head>
<body>
    {{-- En-tête : Wiki (nouvel onglet, pour ne pas quitter la partie), Changer de personnage, Déconnexion --}}
    <div class="bar">
        <span><span class="brand">PokéSIO</span><span class="who">{{ $character->name }}</span></span>
        <nav>
            <a href="{{ route('wiki') }}" target="_blank" rel="noopener">Wiki</a>
            @if (auth()->user()->is_admin)
                <a href="{{ route('admin.index') }}" target="_blank" rel="noopener" style="color:#f08080">Admin</a>
            @endif
            <a href="{{ route('dashboard') }}">Changer</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Déconnexion</button>
            </form>
        </nav>
    </div>

    <div class="stage">
        <div class="screen">
            <div id="game"></div>
            <div id="game-ui"></div>

            {{-- Étape 21 : écran de chargement, affiché tout de suite (avant même le JavaScript du jeu) --}}
            <div id="loading" class="loading">
                <div class="loading-title">PokéSIO</div>
                <div class="loading-hero" id="loading-hero"></div>
                <div class="loading-box">
                    <div class="loading-text"><span id="loading-label">Chargement...</span><span id="loading-percent">0 %</span></div>
                    <div class="loading-bar"><div class="loading-fill" id="loading-fill"></div></div>
                    <div class="loading-tip" id="loading-tip"></div>
                </div>
            </div>

            {{-- Affiché si la sauvegarde échoue parce que la session a expiré --}}
            <div id="session-expired" class="session-expired" hidden>
                Session expirée : <a href="{{ route('game', $character) }}">recharge la page</a> pour continuer à sauvegarder.
            </div>
        </div>
    </div>

    {{-- 1. Les données envoyées par le contrôleur, converties en objet JavaScript --}}
    <script>
        window.GAME_CONFIG = @json($gameConfig);

        // Une seule partie à la fois par compte : chaque requête du jeu porte le jeton de CETTE partie.
        // Si le compte ouvre le jeu ailleurs, le serveur répond 409 : cette fenêtre se met en pause.
        (() => {
            const nativeFetch = window.fetch.bind(window);
            window.fetch = (input, init = {}) => {
                const url = new URL(typeof input === 'string' ? input : input.url, location.href);
                if (url.origin !== location.origin) return nativeFetch(input, init);
                const headers = new Headers(init.headers ?? (typeof input === 'string' ? undefined : input.headers));
                headers.set('X-Play-Token', window.GAME_CONFIG.playToken);
                return nativeFetch(input, { ...init, headers }).then((response) => {
                    if (response.status === 409) {
                        response.clone().json().then((data) => data?.elsewhere && window.playElsewhere()).catch(() => {});
                    }
                    return response;
                });
            };
            window.playElsewhere = () => {
                if (document.getElementById('elsewhere')) return;
                try {
                    window.game?.registry.get('save')?.attach?.(null);   // plus de sauvegarde depuis cette fenêtre
                    window.game?.registry.get('audio')?.playMusic?.(null);
                    window.Echo?.disconnect?.();
                    if (window.game) {
                        window.game.input.keyboard.enabled = false;
                        window.game.loop.sleep();
                    }
                } catch (error) {
                    // la pause est quand même affichée
                }
                const box = document.createElement('div');
                box.id = 'elsewhere';
                box.className = 'elsewhere';
                box.innerHTML = '<div class="elsewhere-box"><p>Ce compte joue déjà dans une autre fenêtre ou sur un autre appareil.</p>'
                    + '<p class="elsewhere-small">Une seule partie à la fois par compte : cette fenêtre est en pause.</p>'
                    + '<div class="elsewhere-actions"><button type="button" id="elsewhere-here">Jouer ici</button>'
                    + `<a href="${window.GAME_CONFIG.dashboardUrl}">Retour au menu</a></div></div>`;
                document.querySelector('.screen').append(box);
                document.getElementById('elsewhere-here').addEventListener('click', () => location.reload());
            };
        })();

        // Étape 21 : l'écran de chargement. Le jeu l'avance avec gameLoading.set(0..1, "texte"), puis gameLoading.done().
        window.gameLoading = (() => {
            const $ = (id) => document.getElementById(id);
            const tips = [
                'Astuce : Maj fait courir, B monte sur la Bicyclette.',
                'Astuce : F lance la meilleure canne à pêche face à l\'eau.',
                'Astuce : T ouvre le chat, Échap ou M le menu.',
                'Astuce : un Repousse éloigne les Pokémon sauvages faibles.',
                'Astuce : la Pension garde deux Pokémon. Un Œuf ? Marchez !',
                'Astuce : Résumé > Capacités pour changer les capacités d\'un Pokémon.',
                'Astuce : le Wiki (en haut) explique tout le jeu.',
                'Astuce : au Casino, il faut d\'abord une Boîte Jetons.',
                'Astuce : sur téléphone, garde B appuyé pour courir.',
                'Astuce : le bouton Manette (en haut) affiche ou cache les commandes tactiles.',
            ];
            let tip = Math.floor(Math.random() * tips.length);
            const showTip = () => { $('loading-tip').textContent = tips[tip++ % tips.length]; };
            showTip();
            const timer = setInterval(showTip, 4000);

            // Le personnage du joueur qui marche (planche de marche 4 x 4 : ligne du bas = face)
            const hero = $('loading-hero');
            const sheet = new Image();
            sheet.onload = () => {
                const w = sheet.width / 4, h = sheet.height / 4;
                Object.assign(hero.style, { width: `${w}px`, height: `${h}px`, backgroundImage: `url('${sheet.src}')` });
                let f = 0;
                hero.walk = setInterval(() => { hero.style.backgroundPosition = `-${(f++ % 4) * w}px 0`; }, 180);
            };
            sheet.src = `${window.GAME_CONFIG.basePath}/sprites/player/${window.GAME_CONFIG.character.avatar}_walk.png?v=${window.GAME_CONFIG.version}`;

            let shown = 0;
            return {
                set(progress, label) {
                    shown = Math.max(shown, Math.min(1, progress));   // la barre ne recule jamais
                    $('loading-fill').style.width = `${Math.round(shown * 100)}%`;
                    $('loading-percent').textContent = `${Math.round(shown * 100)} %`;
                    if (label) $('loading-label').textContent = label;
                },
                done() {
                    this.set(1, 'C\'est parti !');
                    clearInterval(timer);
                    setTimeout(() => {
                        $('loading').classList.add('done');
                        setTimeout(() => { clearInterval(hero.walk); $('loading').remove(); }, 500);
                    }, 150);
                },
            };
        })();
        gameLoading.set(0.02, 'Chargement du jeu...');
    </script>

    {{-- 2. Phaser, depuis un CDN --}}
    <script src="https://cdn.jsdelivr.net/npm/phaser@3.90.0/dist/phaser.min.js"></script>

    {{-- 2 bis. Multijoueur (étape 9) : Pusher (le protocole de Reverb) et Laravel Echo, depuis un CDN --}}
    @if ($gameConfig['multiplayer']['enabled'] ?? false)
        <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/laravel-echo@2.3.4/dist/echo.iife.js"></script>
    @endif

    {{-- 3. Ton jeu. type="module" permet d'utiliser import/export entre tes fichiers --}}
    <script type="module" src="{{ asset('js/game/main.js') }}?v={{ $version }}"></script>
</body>
</html>
