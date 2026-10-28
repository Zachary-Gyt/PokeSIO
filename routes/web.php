<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\MultiplayerController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BagController;
use App\Http\Controllers\BattleController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\FishingController;
use App\Http\Controllers\GiftController;
use App\Http\Controllers\ItemBallController;
use App\Http\Controllers\SafariController;
use App\Http\Controllers\FieldMoveController;
use App\Http\Controllers\GameSaveController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\PokedexController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\TrainerCardController;
use App\Http\Controllers\WikiController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureNotBanned;
use App\Http\Middleware\SinglePlaySession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pages publiques
|--------------------------------------------------------------------------
*/

// Écran titre (avec le Tenant du titre et le classement des joueurs)
Route::get('/', HomeController::class)->name('home');

// Le Wiki : tout ce qu'il faut savoir sur le jeu (rubriques : WikiController::SECTIONS)
Route::get('/wiki', [WikiController::class, 'index'])->name('wiki');
Route::get('/wiki/pokedex', [WikiController::class, 'pokedex'])->name('wiki.pokedex');
Route::get('/wiki/pokedex/{slug}', [WikiController::class, 'pokemon'])->where('slug', '[a-z0-9-]+')->name('wiki.pokemon');
Route::get('/wiki/objets', [WikiController::class, 'items'])->name('wiki.items');   // étape 16
Route::get('/wiki/pension', [WikiController::class, 'daycare'])->name('wiki.daycare');   // étape 18
// Mise à jour du Wiki (après l'étape 23) : rubriques de référence, activités et aide
Route::get('/wiki/evolutions', [\App\Http\Controllers\WikiReferenceController::class, 'evolutions'])->name('wiki.evolutions');
Route::get('/wiki/talents', [\App\Http\Controllers\WikiReferenceController::class, 'abilities'])->name('wiki.abilities');
Route::get('/wiki/capacites', [\App\Http\Controllers\WikiReferenceController::class, 'moves'])->name('wiki.moves');
Route::get('/wiki/capacites/{slug}', [\App\Http\Controllers\WikiReferenceController::class, 'move'])->where('slug', '[a-z0-9-]+')->name('wiki.move');
Route::get('/wiki/types', [\App\Http\Controllers\WikiReferenceController::class, 'types'])->name('wiki.types');
Route::get('/wiki/activites/{slug}', [\App\Http\Controllers\WikiGuideController::class, 'activity'])->where('slug', '[a-z0-9-]+')->name('wiki.activity');
Route::get('/wiki/guide', [\App\Http\Controllers\WikiGuideController::class, 'guide'])->name('wiki.guide');
Route::get('/wiki/combat', [\App\Http\Controllers\WikiGuideController::class, 'battle'])->name('wiki.battle');
Route::get('/wiki/pantheon', [\App\Http\Controllers\HallOfFameController::class, 'wiki'])->name('wiki.halloffame');   // étape 27
Route::get('/wiki/tour-de-combat', [\App\Http\Controllers\WikiGuideController::class, 'tower'])->name('wiki.tower');   // étape 28

// L'ancienne adresse du Pokédex renvoie vers le Wiki (le nom "pokedex" reste valable)
Route::redirect('/pokedex', '/wiki/pokedex', 301)->name('pokedex');

/*
|--------------------------------------------------------------------------
| Visiteurs non connectés uniquement
|--------------------------------------------------------------------------
| Le middleware "guest" renvoie un joueur déjà connecté vers la route "dashboard".
*/
Route::middleware('guest')->group(function () {
    Route::get('/inscription', [RegisterController::class, 'create'])->name('register');
    Route::post('/inscription', [RegisterController::class, 'store']);

    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store']);

    Route::get('/mot-de-passe-oublie', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetController::class, 'store'])
        ->middleware('throttle:6,1,password.email:') // 6 demandes par minute max : évite d'inonder une boîte mail
        ->name('password.email');

    // Le nom "password.reset" est celui qu'utilise Laravel pour construire le lien de l'e-mail
    Route::get('/reinitialiser-mot-de-passe/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reinitialiser-mot-de-passe', [PasswordResetController::class, 'update'])->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Joueurs connectés uniquement
|--------------------------------------------------------------------------
| Le middleware "auth" renvoie un visiteur non connecté vers la route "login".
| EnsureNotBanned (étape 10) déconnecte un compte banni et lui affiche le motif.
| SinglePlaySession : une seule partie à la fois par compte (les requêtes d'une partie remplacée sont refusées).
*/
Route::middleware(['auth', EnsureNotBanned::class, SinglePlaySession::class])->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');

    // Écran des 3 emplacements
    Route::get('/jeu', [CharacterController::class, 'index'])->name('dashboard');

    // Personnages
    Route::get('/personnages/creer/{slot}', [CharacterController::class, 'create'])
        ->whereNumber('slot')
        ->name('characters.create');
    Route::post('/personnages', [CharacterController::class, 'store'])->name('characters.store');
    // {character} : Laravel charge automatiquement le personnage d'après son id ("route model binding")
    Route::get('/personnages/{character}', [CharacterController::class, 'show'])->name('characters.show');
    Route::delete('/personnages/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');

    // Le jeu
    Route::get('/jouer/{character}', [GameController::class, 'show'])->name('game');

    // Sauvegarde automatique (appelée par le jeu en JavaScript). throttle : 30 requêtes / minute max (son propre compteur : « game.save »)
    Route::post('/jouer/{character}/sauvegarde', GameSaveController::class)
        ->middleware('throttle:30,1,game.save:')
        ->name('game.save');

    // Combats (appelés par le jeu en JavaScript)
    Route::prefix('/jouer/{character}/combat')->name('battle.')->group(function () {
        Route::get('/', [BattleController::class, 'current'])->name('current');
        Route::post('/sauvage', [BattleController::class, 'wild'])->middleware('throttle:60,1,battle.wild:')->name('wild');
        Route::post('/dresseur', [BattleController::class, 'trainer'])->middleware('throttle:20,1,battle.trainer:')->name('trainer');
        Route::post('/{battle}/action', [BattleController::class, 'action'])->middleware('throttle:120,1,battle.action:')->name('action');
    });

    // Menu du jeu : équipe, Sac, PC (appelés par le jeu en JavaScript)
    // Les limites (throttle:nombre,minutes,nom) : le « nom » donne à chaque route SON compteur.
    // Sans lui, Laravel compte toutes les requêtes du joueur ensemble, et la plus petite limite bloquait tout le jeu
    // (« Too Many Attempts » en parlant à un PNJ). « jeu » : 600 requêtes / minute au total, contre les robots.
    Route::prefix('/jouer/{character}')->middleware('throttle:600,1,jeu:')->group(function () {
        Route::get('/equipe', [PartyController::class, 'index'])->name('party.index');
        Route::post('/equipe/echanger', [PartyController::class, 'swap'])->name('party.swap');
        Route::post('/equipe/evoluer', [PartyController::class, 'evolve'])->name('party.evolve');
        Route::post('/equipe/apprendre', [PartyController::class, 'learn'])->name('party.learn');
        Route::post('/equipe/soigner', [PartyController::class, 'heal'])->name('party.heal');
        // Étape 14 : objets tenus
        Route::post('/equipe/donner', [PartyController::class, 'give'])->name('party.give');
        Route::post('/equipe/prendre', [PartyController::class, 'take'])->name('party.take');
        // Étape 19 : surnom
        Route::post('/equipe/surnom', [PartyController::class, 'nickname'])->middleware('throttle:30,1,party.nickname:')->name('party.nickname');

        Route::get('/sac', [BagController::class, 'index'])->name('bag.index');
        Route::post('/sac/utiliser', [BagController::class, 'use'])->name('bag.use');
        Route::post('/sac/jeter', [BagController::class, 'toss'])->name('bag.toss');

        Route::get('/pc', [StorageController::class, 'index'])->name('storage.index');
        Route::post('/pc/deplacer', [StorageController::class, 'move'])->name('storage.move');
        Route::post('/pc/relacher', [StorageController::class, 'release'])->name('storage.release');

        // Étape 8 : Carte Dresseur, Boutique, Tenant du titre
        Route::get('/carte-dresseur', TrainerCardController::class)->name('trainer-card');
        Route::get('/boutique', [ShopController::class, 'index'])->name('shop.index');
        Route::post('/boutique/acheter', [ShopController::class, 'buy'])->name('shop.buy');
        Route::post('/boutique/vendre', [ShopController::class, 'sell'])->name('shop.sell');
        Route::get('/tenant/{slug}', TitleController::class)->where('slug', '[a-z0-9_]+')->name('title.show');

        // Multijoueur (étape 9) : invitations, échanges, combats entre joueurs
        Route::post('/multi/inviter', [MultiplayerController::class, 'invite'])->middleware('throttle:20,1,multi.invite:')->name('multi.invite');
        Route::post('/multi/repondre', [MultiplayerController::class, 'answer'])->name('multi.answer');
        Route::get('/echange/{trade}', [MultiplayerController::class, 'trade'])->name('multi.trade');
        Route::post('/echange/{trade}/offrir', [MultiplayerController::class, 'tradeOffer'])->name('multi.trade.offer');
        Route::post('/echange/{trade}/confirmer', [MultiplayerController::class, 'tradeConfirm'])->name('multi.trade.confirm');
        Route::post('/echange/{trade}/annuler', [MultiplayerController::class, 'tradeCancel'])->name('multi.trade.cancel');
        Route::get('/pvp/{battle}', [MultiplayerController::class, 'pvpState'])->name('multi.pvp');
        Route::post('/pvp/{battle}/action', [MultiplayerController::class, 'pvpAction'])->name('multi.pvp.action');
        Route::post('/pvp/{battle}/abandon', [MultiplayerController::class, 'pvpForfeit'])->name('multi.pvp.forfeit');
        Route::post('/pvp/{battle}/reclamer', [MultiplayerController::class, 'pvpClaim'])->name('multi.pvp.claim');
        // Étape 13 : le Pokédex
        Route::get('/pokedex', [PokedexController::class, 'index'])->name('pokedex.index');
        Route::get('/pokedex/{species}', [PokedexController::class, 'show'])->whereNumber('species')->name('pokedex.show');
        Route::post('/pokedex/recompenses-vues', [PokedexController::class, 'acknowledge'])->name('pokedex.ack');

        // Étape 11 : capacités utilisées hors combat (Coupe, Surf, Vol...)
        Route::get('/terrain', [FieldMoveController::class, 'index'])->name('field.index');
        Route::post('/terrain', [FieldMoveController::class, 'use'])->middleware('throttle:60,1,field.use:')->name('field.use');
        // Étape 14 : un PNJ qui offre un objet (propriété Tiled action = gift)
        Route::post('/cadeau', [GiftController::class, 'claim'])->middleware('throttle:30,1,gift.claim:')->name('gift.claim');
        // Étape 15 : pêche et Parc Safari
        Route::post('/peche/lancer', [FishingController::class, 'cast'])->middleware('throttle:60,1,fishing.cast:')->name('fishing.cast');
        Route::post('/peche/ferrer', [FishingController::class, 'hook'])->middleware('throttle:60,1,fishing.hook:')->name('fishing.hook');
        Route::post('/safari/entrer', [SafariController::class, 'enter'])->name('safari.enter');
        Route::post('/safari/pas', [SafariController::class, 'steps'])->name('safari.steps');
        Route::post('/safari/quitter', [SafariController::class, 'leave'])->name('safari.leave');
        // Étape 16 : les Poké Balls posées par terre
        Route::get('/objets-carte', [ItemBallController::class, 'index'])->name('itemballs.index');
        Route::post('/objets-carte', [ItemBallController::class, 'pick'])->middleware('throttle:30,1,itemballs.pick:')->name('itemballs.pick');
        // Étape 18 : la Pension, et les pas du joueur (Exp. de la Pension, Œufs, éclosions)
        Route::get('/pension', [\App\Http\Controllers\DaycareController::class, 'show'])->name('daycare.show');
        Route::post('/pension/confier', [\App\Http\Controllers\DaycareController::class, 'deposit'])->middleware('throttle:30,1,daycare.deposit:')->name('daycare.deposit');
        Route::post('/pension/reprendre', [\App\Http\Controllers\DaycareController::class, 'withdraw'])->middleware('throttle:30,1,daycare.withdraw:')->name('daycare.withdraw');
        Route::post('/pas', [\App\Http\Controllers\DaycareController::class, 'steps'])->name('daycare.steps');
        // Étape 19 : Repousse et Corde Sortie, Maître des capacités
        Route::post('/objet-terrain', [\App\Http\Controllers\FieldItemController::class, 'use'])->middleware('throttle:30,1,fielditem.use:')->name('fielditem.use');
        // Changer les capacités d'un Pokémon depuis le Résumé (remplace le Maître des capacités)
        Route::get('/equipe/capacites', [\App\Http\Controllers\PartyMovesController::class, 'index'])->name('party.moves');
        Route::post('/equipe/capacites', [\App\Http\Controllers\PartyMovesController::class, 'replace'])->middleware('throttle:60,1,party.moves:')->name('party.moves.replace');
        // Étape 20 : le Casino
        Route::prefix('/casino')->name('casino.')->controller(\App\Http\Controllers\GameCornerController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::post('/jetons', 'buy')->middleware('throttle:30,1,casino.buy:')->name('buy');
            Route::post('/jetons/vendre', 'sell')->middleware('throttle:30,1,casino.sell:')->name('sell');
            Route::get('/lots', 'prizes')->name('prizes');
            Route::post('/lots', 'exchange')->middleware('throttle:30,1,casino.exchange:')->name('exchange');
            Route::post('/machine', 'openSlots')->name('slots.open');
            Route::post('/machine/jouer', 'playSlots')->middleware('throttle:200,1,slots.play:')->name('slots.play');
            Route::post('/roulette', 'openRoulette')->name('roulette.open');
            Route::post('/roulette/jouer', 'playRoulette')->middleware('throttle:120,1,roulette.play:')->name('roulette.play');
        });
        // Étape 22 : les buissons à Baies
        Route::get('/baies', [\App\Http\Controllers\BerryBushController::class, 'index'])->name('berries.index');
        Route::post('/baies/regarder', [\App\Http\Controllers\BerryBushController::class, 'inspect'])->middleware('throttle:60,1,berries.inspect:')->name('berries.inspect');
        Route::post('/baies', [\App\Http\Controllers\BerryBushController::class, 'pick'])->middleware('throttle:30,1,berries.pick:')->name('berries.pick');
        // La Banque (PNJ ou distributeur : action = bank)
        Route::prefix('/banque')->name('bank.')->controller(\App\Http\Controllers\BankController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::post('/deposer', 'deposit')->middleware('throttle:60,1,bank.deposit:')->name('deposit');
            Route::post('/retirer', 'withdraw')->middleware('throttle:60,1,bank.withdraw:')->name('withdraw');
        });
        // Étape 28 : la Tour de Combat
        Route::prefix('/tour')->name('tower.')->controller(\App\Http\Controllers\BattleTowerController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::post('/commencer', 'start')->name('start');
            Route::post('/combat', 'battle')->middleware('throttle:60,1,tower.battle:')->name('battle');
            Route::post('/abandonner', 'quit')->name('quit');
            Route::get('/classement', 'leaderboard')->name('leaderboard');
        });
        // Étape 27 : le Panthéon (PC du Panthéon)
        Route::get('/pantheon', [\App\Http\Controllers\HallOfFameController::class, 'index'])->name('halloffame.index');
        // Étape 26 : le Cherche VS (revanches)
        Route::post('/cherche-vs', [\App\Http\Controllers\RematchController::class, 'vsSeeker'])->middleware('throttle:30,1,rematch.vsseeker:')->name('rematch.vsseeker');
        // Étape 24 : les événements du scénario (resources/scenario/*.txt)
        Route::get('/evenements', [\App\Http\Controllers\EventController::class, 'state'])->name('events.state');
        // Les images que le jeu n'a pas pu charger (notées dans le journal du serveur)
        Route::post('/diagnostic/image', [\App\Http\Controllers\DiagnosticController::class, 'image'])->middleware('throttle:20,1,diagnostic:')->name('diagnostic.image');
        Route::post('/evenements', [\App\Http\Controllers\EventController::class, 'start'])->middleware('throttle:60,1,events.start:')->name('events.start');
        Route::post('/evenements/suite', [\App\Http\Controllers\EventController::class, 'resume'])->middleware('throttle:60,1,events.resume:')->name('events.resume');
        Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
        Route::post('/chat', [ChatController::class, 'send'])->name('chat.send');
        // Les groupes de joueurs (chat /g, menu > Groupe)
        Route::get('/groupe', [\App\Http\Controllers\GroupController::class, 'show'])->name('group.show');
        Route::post('/groupe/inviter', [\App\Http\Controllers\GroupController::class, 'invite'])->middleware('throttle:20,1,group.invite:')->name('group.invite');
        Route::post('/groupe/repondre', [\App\Http\Controllers\GroupController::class, 'answer'])->name('group.answer');
        Route::post('/groupe/quitter', [\App\Http\Controllers\GroupController::class, 'leave'])->name('group.leave');
        Route::post('/groupe/exclure', [\App\Http\Controllers\GroupController::class, 'kick'])->name('group.kick');
        Route::post('/groupe/chef', [\App\Http\Controllers\GroupController::class, 'promote'])->name('group.promote');
    });

    /*
    |----------------------------------------------------------------------
    | Administration (étape 10) : réservé aux comptes is_admin
    |----------------------------------------------------------------------
    | Donner les droits : php artisan game:admin ton@email.fr
    */
    Route::prefix('/admin')->name('admin.')->middleware(EnsureAdmin::class)->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('/comptes/{user}/bannir', [AdminController::class, 'ban'])->name('ban');
        Route::post('/comptes/{user}/debannir', [AdminController::class, 'unban'])->name('unban');
        Route::post('/comptes/{user}/rendre-parole', [AdminController::class, 'unmute'])->name('unmute');
        Route::post('/comptes/{user}/professeur', [AdminController::class, 'professor'])->name('professor');   // grade Professeur
    });
});
