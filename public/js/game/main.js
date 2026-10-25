import BootScene from './scenes/BootScene.js';
import AssetsScene from './scenes/AssetsScene.js';
import WorldScene from './scenes/WorldScene.js';
import BattleScene from './battle/BattleScene.js';
import MenuScene from './menu/MenuScene.js';
import DialogBox from './ui/DialogBox.js';
import SaveManager from './SaveManager.js';
import Multiplayer from './net/Multiplayer.js';
import Chat from './net/Chat.js';
import AudioManager from './audio/AudioManager.js';
import TouchControls from './ui/TouchControls.js';

// On attend que la police des dialogues soit chargée AVANT de lancer le jeu :
// la boîte de dialogue mesure le texte pour le découper en pages, il faut donc la vraie police.
window.gameLoading?.set(0.05, 'Chargement des polices...');   // étape 21 : écran de chargement
await Promise.all([
    document.fonts.load("27px 'Power Green'"),
    document.fonts.load("21px 'Power Green Small'"),
    document.fonts.load("27px 'Power Green Narrow'"),
]).catch(() => {});

const game = new Phaser.Game({
    type: Phaser.CANVAS,    // pas de limite de taille d'image (le tileset fait 16 064 px de haut)
    parent: 'game',         // l'id de la <div> qui accueille le jeu
    width: 512,             // 16 cases de 32 px  \ résolution native de Pokémon Essentials :
    height: 384,            // 12 cases de 32 px  / toute son interface est dessinée pour 512 x 384
    pixelArt: true,         // pas de flou quand l'image est agrandie
    backgroundColor: '#000000',
    scale: {
        mode: Phaser.Scale.FIT,               // s'adapte à la taille de la fenêtre
        autoCenter: Phaser.Scale.CENTER_BOTH,
    },
    // la première de la liste démarre seule ; étape 21 : AssetsScene charge les menus et combats en arrière-plan
    scene: [BootScene, WorldScene, BattleScene, MenuScene, AssetsScene],
});

// Le "registry" : un espace de données partagé par toutes les scènes
game.registry.set('config', window.GAME_CONFIG);

// Étape 12 : la musique et les sons, partagés par toutes les scènes
game.registry.set('audio', new AudioManager(window.GAME_CONFIG.audio));

// La boîte de dialogue (HTML, par-dessus le jeu), partagée par toutes les scènes
const dialog = new DialogBox(document.getElementById('game-ui'));
game.registry.set('dialog', dialog);

// La sauvegarde automatique, partagée elle aussi
game.registry.set('save', new SaveManager({
    url: window.GAME_CONFIG.saveUrl,
    csrfToken: document.querySelector('meta[name="csrf-token"]').content,
    intervalSeconds: window.GAME_CONFIG.autosaveSeconds ?? 30,
    onSessionExpired: () => {
        document.getElementById('session-expired').hidden = false;
    },
}));

// Le multijoueur (Laravel Reverb) : les autres joueurs, les invitations, les échanges, les combats
const multi = new Multiplayer(window.GAME_CONFIG);
game.registry.set('multi', multi);
// Le chat textuel (touche T), seulement si Reverb est configuré
if (window.GAME_CONFIG.multiplayer?.enabled) {
    game.registry.set('chat', new Chat(game, multi, window.GAME_CONFIG));
}

// Étape 29 : les commandes tactiles (croix, A, B...), affichées seules sur un écran tactile
game.registry.set('touch', new TouchControls(game));

window.game = game; // pour inspecter le jeu depuis la console du navigateur
