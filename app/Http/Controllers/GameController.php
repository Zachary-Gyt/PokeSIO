<?php

namespace App\Http\Controllers;

use App\Models\Battle;
use App\Models\Character;
use App\Models\Trainer;
use App\Services\BattleService;
use App\Services\PlaySession;
use App\Services\TitleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class GameController extends Controller
{
    public function show(Request $request, Character $character, BattleService $battles, TitleService $titles)
    {
        abort_unless($character->user_id === $request->user()->id, 404);

        $maps = $this->assetNames('game/maps', 'tmj');               // ["cremieu", "maison_joueur", ...]
        $tilesets = $this->assetNames('game/tilesets', 'png');       // ["outside", "interior", ...]
        $npcSprites = $this->assetNames('game/sprites/npc', 'png');  // ["npc_10", "npc_11", ...]
        $trainerSprites = $this->assetNames('game/sprites/trainers/overworld', 'png'); // ["lass", "youngster", ...]

        // Version = date du fichier le plus récent : force le navigateur à recharger après une modif.
        // (Pas tout public/game : avec les sprites de Pokémon, ça ferait plus de 1 000 fichiers à lire.)
        $version = collect(File::allFiles(public_path('js/game')))
            ->merge(File::allFiles(public_path('game/maps')))
            ->merge(File::glob(public_path('game/tilesets/*.tsj')) ? collect(File::glob(public_path('game/tilesets/*.tsj')))->map(fn ($f) => new \SplFileInfo($f)) : [])
            ->max(fn ($file) => $file->getMTime());

        // Combat en cours (page rechargée en plein combat) : le jeu le reprend tout de suite
        $activeBattle = $battles->activeBattle($character);

        // Carte de la sauvegarde introuvable : carte de départ
        $mapExists = $maps->contains($character->current_map);

        // Début de la session de jeu : le temps de jeu se comptera à partir de maintenant
        $character->forceFill(['last_seen_at' => now()])->save();

        // Multijoueur : le personnage joué dans cet onglet (le canal de présence de la carte s'en sert)
        $request->session()->put('playing_character_id', $character->id);
        $pvpBattle = Battle::involving($character)->active()->where('type', 'pvp')->latest('id')->first();

        // Dresseurs déjà battus par CE personnage (table character_trainer)
        $defeatedIds = $character->defeatedTrainers()->pluck('trainers.id')->all();
        // Étape 26 : ceux qui veulent une revanche (Cherche VS), et les Champions qui l'acceptent (tous les badges)
        $readyIds = $character->defeatedTrainers()->wherePivot('rematch_ready', true)->pluck('trainers.id')->all();
        $leadersRematch = $character->badges()->count() >= (int) config('rematches.leaders_badges', 8);
        // Étape 27 : la Ligue (seul le membre dont c'est le tour peut être affronté)
        $league = app(\App\Services\LeagueService::class);

        // Tous les dresseurs, indexés par slug : c'est ce slug qu'on écrit dans Tiled (propriété "trainer")
        $trainers = Trainer::all()->mapWithKeys(fn (Trainer $trainer) => [
            $trainer->slug => $trainer->is_title ? $this->titleConfig($trainer, $titles, $character) : [
                'name' => $trainer->name,
                'class' => $trainer->trainer_class,
                'intro' => $trainer->intro_text,
                'defeat' => $trainer->defeat_text,
                'after' => $trainer->after_text,
                'sight' => $trainer->sight_range,
                'overworld' => $trainer->sprite_overworld, // planche sur la carte (sprites/trainers/overworld)
                'battle' => $trainer->sprite_battle,       // image de combat (sprites/trainers/battle) : étape 6
                // Battu ET sans revanche possible : il ne défiera plus le joueur
                'defeated' => $league->isMember($trainer)
                    ? ! $league->canChallenge($character, $trainer)
                    : in_array($trainer->id, $defeatedIds, true) && ! $trainer->rematchable && ! in_array($trainer->id, $readyIds, true),
                // Étape 26 : un Champion déjà battu propose une revanche quand on lui parle
                'leaderRematch' => $trainer->badge_id !== null && $leadersRematch && in_array($trainer->id, $defeatedIds, true),
            ],
        ]);

        // Badges obtenus (numéros 1 à 8) : les PNJ "gardiens" (propriété requires_badge) s'écartent
        $badges = $character->badges()->pluck('order')->map(fn ($o) => (int) $o)->all();

        return view('game', [
            'character' => $character,
            'version' => $version,
            'gameConfig' => [
                // Une seule partie à la fois par compte : ce jeton accompagne chaque requête du jeu
                'playToken' => PlaySession::start($request->user(), $character),
                'character' => [
                    'id' => $character->id,
                    'name' => $character->name,
                    'gender' => $character->gender,
                    'avatar' => $character->avatarKey(),   // étape 17 : red, leaf, brendan, may
                    'map' => $mapExists ? $character->current_map : config('game.starting_map'),
                    // null = le jeu utilisera le point d'apparition de la carte
                    'x' => $mapExists ? $character->position_x : null,
                    'y' => $mapExists ? $character->position_y : null,
                    'facing' => $character->facing,
                    'isAdmin' => (bool) $request->user()->is_admin,   // étape 10 : commandes /… du chat
                ],
                'maps' => $maps,
                // Étape 17 : tous les personnages (les autres joueurs peuvent être n'importe lequel)
                'avatars' => array_keys(config('avatars', [])),
                'tilesets' => $tilesets,
                'tilesetData' => $this->assetNames('game/tilesets', 'tsj'),   // collisions et terrains partagés (game:tilesets)
                'npcSprites' => $npcSprites,
                'trainerSprites' => $trainerSprites,
                // (object) : une liste vide doit devenir {} en JavaScript, et non []
                'trainers' => (object) $trainers->all(),
                'badges' => $badges,
                'version' => $version,
                'basePath' => asset('game'),
                'saveUrl' => route('game.save', $character),   // où le jeu envoie ses sauvegardes
                'autosaveSeconds' => 30,
                'dashboardUrl' => route('dashboard'),           // étape 10 : retour à la sélection (kick)

                // Menu du jeu (équipe, Sac, PC) : adresses appelées par le JavaScript
                'menu' => [
                    'party' => route('party.index', $character),
                    'swap' => route('party.swap', $character),
                    'evolve' => route('party.evolve', $character),
                    'learn' => route('party.learn', $character),
                    'heal' => route('party.heal', $character),
                    'give' => route('party.give', $character),   // étape 14 : objets tenus
                    'take' => route('party.take', $character),
                    'gift' => route('gift.claim', $character),   // étape 14 : PNJ qui offre un objet
                    // Étape 15 : pêche et Parc Safari
                    'fishCast' => route('fishing.cast', $character),
                    'fishHook' => route('fishing.hook', $character),
                    'safariEnter' => route('safari.enter', $character),
                    'safariSteps' => route('safari.steps', $character),
                    'safariLeave' => route('safari.leave', $character),
                    // Étape 16 : les Poké Balls posées par terre
                    'itemBalls' => route('itemballs.index', $character),
                    'itemBallPick' => route('itemballs.pick', $character),
                    // Étape 22 : buissons à Baies
                    'berries' => route('berries.index', $character),
                    'berryInspect' => route('berries.inspect', $character),
                    'berryPick' => route('berries.pick', $character),
                    // Étape 18 : la Pension et les pas (Œufs)
                    'daycare' => route('daycare.show', $character),
                    'daycareDeposit' => route('daycare.deposit', $character),
                    'daycareWithdraw' => route('daycare.withdraw', $character),
                    'steps' => route('daycare.steps', $character),
                    // Étape 19 : surnoms, Maître des capacités, Repousse et Corde Sortie
                    'nickname' => route('party.nickname', $character),
                    'moveTutor' => route('movetutor.show', $character),
                    'moveTutorSave' => route('movetutor.update', $character),
                    'fieldItem' => route('fielditem.use', $character),
                    'vsSeeker' => route('rematch.vsseeker', $character),   // étape 26
                    // Étape 28 : la Tour de Combat
                    'tower' => route('tower.show', $character),
                    'towerStart' => route('tower.start', $character),
                    'towerBattle' => route('tower.battle', $character),
                    // La Banque
                    'bank' => route('bank.show', $character),
                    'bankDeposit' => route('bank.deposit', $character),
                    'bankWithdraw' => route('bank.withdraw', $character),
                    'towerQuit' => route('tower.quit', $character),
                    'towerBoard' => route('tower.leaderboard', $character),
                    // Étape 20 : le Casino
                    'casino' => route('casino.show', $character),
                    'casinoBuy' => route('casino.buy', $character),
                    'casinoPrizes' => route('casino.prizes', $character),
                    'casinoExchange' => route('casino.exchange', $character),
                    'slotsOpen' => route('casino.slots.open', $character),
                    'slotsPlay' => route('casino.slots.play', $character),
                    'rouletteOpen' => route('casino.roulette.open', $character),
                    'roulettePlay' => route('casino.roulette.play', $character),
                    // Étape 24 : les événements du scénario
                    'events' => route('events.state', $character),
                    'eventStart' => route('events.start', $character),
                    'eventResume' => route('events.resume', $character),
                    'bag' => route('bag.index', $character),
                    'bagUse' => route('bag.use', $character),
                    'bagToss' => route('bag.toss', $character),
                    'pc' => route('storage.index', $character),
                    'pcMove' => route('storage.move', $character),
                    'pcRelease' => route('storage.release', $character),
                    'trainerCard' => route('trainer-card', $character),
                    'shop' => route('shop.index', $character),
                    'shopBuy' => route('shop.buy', $character),
                    'shopSell' => route('shop.sell', $character),
                    // SLUG est remplacé par le slug du dresseur dans le JavaScript
                    'title' => route('title.show', [$character, 'SLUG']),
                ],

                // Étape 11 : capacités utilisées hors combat, et le Pokémon qui suit le joueur
                'field' => [
                    'index' => route('field.index', $character),
                    'use' => route('field.use', $character),
                ],
                'follower' => self::follower($character),

                // Étape 13 : le Pokédex (SPECIES est remplacé par le numéro de l'espèce dans le JavaScript)
                'pokedex' => [
                    'index' => route('pokedex.index', $character),
                    'show' => route('pokedex.show', [$character, 'SPECIES']),
                    'ack' => route('pokedex.ack', $character),
                ],

                // Étape 12 : musique et sons (fichiers de public/game/audio, choisis dans config/audio.php)
                'audio' => app(\App\Services\AudioLibrary::class)->forClient(),

                // Combats
                'battle' => [
                    'wildUrl' => route('battle.wild', $character),
                    // Combat en cours / lancé par un administrateur (/spawn) : étape 10
                    'currentUrl' => route('battle.current', $character),
                    'trainerUrl' => route('battle.trainer', $character),
                    // {id} est remplacé par le numéro du combat dans BattleScene.js
                    'actionUrl' => route('battle.action', [$character, 'BATTLE_ID']),
                    // Chance de rencontre à chaque pas dans les hautes herbes (en %)
                    'encounterChance' => config('game.encounter_chance', 10),
                    'active' => $activeBattle ? $battles->view($activeBattle) : null,
                ],

                // Étape 27 : la Ligue (membres dans l'ordre, membres battus dans la tentative en cours) et le Panthéon
                'league' => [
                    'members' => $league->members(),
                    'progress' => $league->progress($character),
                    'badges' => $league->hasBadges($character),
                    'wins' => $league->wins($character),
                    'hallOfFame' => route('halloffame.index', $character),
                ],

                // Étape 25 : les cartes collées (mondes Tiled *.world) : position de chaque carte, en cases
                'world' => [
                    'maps' => (object) app(\App\Services\WorldMap::class)->maps(),
                    'margin' => \App\Services\WorldMap::MARGIN,
                ],

                // Étape 23 : l'heure du jeu, pour la teinte du jour et de la nuit (rafraîchie à chaque carte)
                'clock' => \App\Services\TimeOfDay::clock(),

                // Multijoueur (étape 9) : Reverb, invitations, échanges, combats entre joueurs.
                // TRADE_ID / BATTLE_ID sont remplacés dans le JavaScript.
                'multiplayer' => [
                    'enabled' => config('broadcasting.default') === 'reverb',
                    'reverb' => [
                        'key' => config('broadcasting.connections.reverb.key'),
                        'host' => config('broadcasting.connections.reverb.options.host'),
                        'port' => (int) config('broadcasting.connections.reverb.options.port', 8080),
                        'scheme' => config('broadcasting.connections.reverb.options.scheme', 'http'),
                    ],
                    'invite' => route('multi.invite', $character),
                    'answer' => route('multi.answer', $character),
                    'trade' => route('multi.trade', [$character, 'TRADE_ID']),
                    'tradeOffer' => route('multi.trade.offer', [$character, 'TRADE_ID']),
                    'tradeConfirm' => route('multi.trade.confirm', [$character, 'TRADE_ID']),
                    'tradeCancel' => route('multi.trade.cancel', [$character, 'TRADE_ID']),
                    'pvp' => route('multi.pvp', [$character, 'BATTLE_ID']),
                    'pvpAction' => route('multi.pvp.action', [$character, 'BATTLE_ID']),
                    'pvpForfeit' => route('multi.pvp.forfeit', [$character, 'BATTLE_ID']),
                    'pvpClaim' => route('multi.pvp.claim', [$character, 'BATTLE_ID']),
                    'chat' => route('chat.index', $character),
                    'chatSend' => route('chat.send', $character),
                    // Combat entre joueurs en cours (page rechargée) : le jeu le reprend
                    'pvpBattle' => $pvpBattle?->id,
                ],
            ],
        ]);
    }

    /** Le Tenant du titre, vu par le jeu : il peut toujours être défié (sauf par le tenant lui-même) */
    /**
     * Le Pokémon qui suit le joueur : le premier de l'équipe qui n'est pas K.O. (null s'il n'y en a pas).
     * Public : PartyController et le combat renvoient la même chose quand l'équipe change.
     */
    public static function follower(Character $character): ?array
    {
        $lead = $character->battleParty()->with('species')->where('current_hp', '>', 0)->first();
        if (! $lead || ! $lead->species->sprite('overworld')) {
            return null;
        }

        return [
            'id' => $lead->id,
            'name' => $lead->display_name,
            'sprite' => $lead->species->sprite('overworld', (bool) $lead->is_shiny),
        ];
    }

    private function titleConfig(Trainer $trainer, TitleService $titles, Character $character): array
    {
        $info = $titles->info($trainer, $character);

        return [
            'name' => $info['name'],
            'class' => $trainer->trainer_class,
            'intro' => null,
            'defeat' => null,
            'after' => null,
            'sight' => 0,
            'overworld' => $trainer->sprite_overworld,
            'battle' => $trainer->sprite_battle,
            'defeated' => false,
            'isTitle' => true,
            // Un joueur tenant : le PNJ prend sa planche de marche (red_walk, may_walk...)
            'playerSprite' => $info['playerSprite'],
        ];
    }

    /** Noms (sans extension) des fichiers d'un dossier de public/ ; liste vide si le dossier n'existe pas */
    private function assetNames(string $directory, string $extension)
    {
        $path = public_path($directory);

        if (! File::isDirectory($path)) {
            return collect();
        }

        return collect(File::files($path))
            ->filter(fn ($file) => strtolower($file->getExtension()) === $extension)
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->values();
    }
}
