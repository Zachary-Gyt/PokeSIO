<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Accueil') — PokéSIO</title>
    <style>
        /* Les polices du jeu (Pokémon Essentials, dans public/game/fonts) : tout le site parle comme le jeu */
        @font-face { font-family: 'Power Green'; src: url('{{ asset('game/fonts/power_green.ttf') }}') format('truetype'); font-display: block; }
        @font-face { font-family: 'Power Green Small'; src: url('{{ asset('game/fonts/power_green_small.ttf') }}') format('truetype'); font-display: block; }
        @font-face { font-family: 'Power Green Narrow'; src: url('{{ asset('game/fonts/power_green_narrow.ttf') }}') format('truetype'); font-display: block; }

        :root {
            --bg: #14161c;           /* même fond que la page du jeu */
            --bar: #1d2029;
            --bar-line: #2b3040;
            --light: #e8ebf2;
            --gold: #ffcb05;
            /* Couleurs du texte des fenêtres d'Essentials : texte sombre + ombre grise */
            --ink: #505058;
            --ink-shadow: #d0d0c8;
            --muted: #8890a0;
            --red: #e3350d;
            --red-dark: #9c2208;
            --blue: #3060b8;
            --blue-dark: #1c3c80;
            --error: #c62828;
            --ok: #2e7d32;
            --font: 'Power Green', monospace;
            --font-small: 'Power Green Small', monospace;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            font-family: var(--font); font-size: 22px; line-height: 1.25;
            color: var(--light); background: var(--bg);
            min-height: 100vh; display: flex; flex-direction: column;
        }
        img.px, .px { image-rendering: pixelated; }
        h1, h2, h3, strong, b, th { font-weight: normal; }

        /* ---- En-tête : le même bandeau que la page du jeu ---- */
        .topbar {
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
            height: 48px; padding: 0 16px;
            background: var(--bar); border-bottom: 2px solid var(--bar-line);
            font-size: 22px;
        }
        .topbar a, .topbar button { color: var(--light); text-decoration: none; }
        .topbar a:hover, .topbar button:hover { color: var(--gold); }
        .topbar .brand { font-size: 26px; color: var(--gold); text-shadow: 2px 2px 0 #2a4a8c; letter-spacing: 1px; }
        .topbar nav { display: flex; gap: 22px; align-items: center; }
        .topbar form { margin: 0; }
        .topbar .who { color: var(--muted); }
        .topbar .admin { color: #f08080; }
        .link-btn { background: none; border: 0; padding: 0; font: inherit; cursor: pointer; }

        main { flex: 1; display: flex; justify-content: center; align-items: flex-start; padding: 28px 16px 48px; }

        /* ---- Fenêtre façon Essentials : le cadre "choice" du jeu, découpé en 9 (coins de 16 px) ---- */
        .box {
            width: 100%; max-width: 480px;
            border-style: solid; border-width: 16px;
            border-image: url('{{ asset('game/ui/choice.png') }}') 16 fill / 16px stretch;
            image-rendering: pixelated;
            color: var(--ink); text-shadow: 2px 2px 0 var(--ink-shadow);
            padding: 10px 14px 14px;
        }
        .box.wide { max-width: 860px; }
        .box h1 { font-size: 34px; font-weight: normal; margin: 0 0 10px; }
        .box h2 { font-size: 26px; font-weight: normal; margin: 18px 0 8px; }
        .box p.lead { margin: 0 0 16px; color: #707880; }
        .box a { color: var(--blue); }

        label { display: block; font-size: 22px; margin: 12px 0 4px; }
        input[type=text], input[type=email], input[type=password], input[type=number], select {
            width: 100%; height: 40px; padding: 0 10px;
            font: 22px/40px var(--font); color: var(--ink);
            border: 2px solid #a0a0a8; border-radius: 0; background: #fff;
            box-shadow: inset 2px 2px 0 #e0e0e0;
        }
        input:focus, select:focus { outline: none; border-color: var(--blue); }
        /* La police pixel est mal placée par le navigateur dans les textes d'exemple (coupée en bas) : police système */
        ::placeholder { font-family: system-ui, sans-serif; font-size: 16px; color: #a0a0a8; }
        .checkbox { display: flex; gap: 8px; align-items: center; margin-top: 12px; }
        .checkbox input { width: 18px; height: 18px; }

        .error { color: var(--error); text-shadow: none; font-size: 20px; margin-top: 4px; }
        .status { background: #e8f5e9; color: var(--ok); text-shadow: none; padding: 6px 10px; margin-bottom: 12px; border: 2px solid #a5d6a7; }

        /* Boutons "pixel" : pas d'arrondi, une ombre en escalier */
        .btn {
            display: inline-block; font: 22px var(--font); text-shadow: 2px 2px 0 rgba(0,0,0,.35);
            padding: 5px 18px 9px; border: 0; border-radius: 0;
            background: var(--red); color: #fff;
            box-shadow: inset -3px -3px 0 var(--red-dark), inset 3px 3px 0 #ff8a6a;
            cursor: pointer; text-decoration: none; text-align: center;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn:active { box-shadow: inset 3px 3px 0 var(--red-dark); }
        .btn.secondary { background: var(--blue); box-shadow: inset -3px -3px 0 var(--blue-dark), inset 3px 3px 0 #7aa0e8; }
        .btn.block { display: block; width: 100%; margin-top: 18px; }

        .below { margin-top: 16px; font-size: 20px; text-align: center; color: #707880; }

        /* ---- "Écran de jeu" 512 x 384 (accueil, sélection du personnage) ----
           --gp = UN pixel du jeu : tout est dessiné aux mesures d'Essentials, puis agrandi */
        .screen {
            container-type: inline-size;
            position: relative; overflow: hidden;
            width: min(100%, 1024px, (100vh - 120px) * 4 / 3);
            aspect-ratio: 4 / 3;
            border: 4px solid #000; box-shadow: 0 0 0 4px var(--bar-line);
            background: #000; image-rendering: pixelated;
            user-select: none;
        }
        .screen > .inner { --gp: calc(100cqw / 512); position: absolute; inset: 0; }
    </style>
    @stack('styles')
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ route('home') }}">PokéSIO</a>
    <nav>
        <a href="{{ route('wiki') }}">Wiki</a>
        @auth
            @if (auth()->user()->is_admin)
                <a class="admin" href="{{ route('admin.index') }}">Admin</a>
            @endif
            <a href="{{ route('dashboard') }}" title="Choisir un autre personnage">Changer</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="link-btn" type="submit">Déconnexion</button>
            </form>
        @else
            <a href="{{ route('login') }}">Connexion</a>
            <a href="{{ route('register') }}">Inscription</a>
        @endauth
    </nav>
</header>

<main>
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
