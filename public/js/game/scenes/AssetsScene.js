import { preloadBattleAssets, registerBattleFrames } from '../battle/BattleAssets.js';
import { preloadMenuAssets } from '../menu/MenuAssets.js';

/**
 * Étape 21 : charge EN ARRIÈRE-PLAN l'interface des combats et des menus (environ 200 images),
 * une fois la carte affichée : le joueur commence à jouer sans attendre.
 *
 * Quand c'est fini, la promesse du registre "assetsPromise" est tenue (créée par BootScene).
 * WorldScene l'attend avant d'ouvrir un combat ou un menu (WorldScene.waitAssets) : en pratique,
 * tout est déjà là bien avant.
 */
export default class AssetsScene extends Phaser.Scene {
    constructor() {
        super('Assets');
    }

    preload() {
        const config = this.registry.get('config');
        const avatar = config.character.avatar ?? config.character.gender;
        preloadBattleAssets(this, config.basePath, avatar);
        preloadMenuAssets(this, config.basePath, config.character.gender);
        // Étape 11 : la carte de Vol
        this.load.image('town_map_bg', `${config.basePath}/ui/town_map/bg.png`);
        this.load.image('town_map_region', `${config.basePath}/ui/town_map/mapRegion0.png`);
        this.load.spritesheet('town_map_fly', `${config.basePath}/ui/town_map/icon_fly.png`, { frameWidth: 32, frameHeight: 32 });
        this.load.spritesheet('town_map_cursor', `${config.basePath}/ui/town_map/cursor.png`, { frameWidth: 32, frameHeight: 32 });
    }

    create() {
        const config = this.registry.get('config');
        registerBattleFrames(this, config.character.avatar ?? config.character.gender);
        this.registry.set('assetsReady', true);
        this.registry.get('assetsResolve')?.();
        this.scene.stop();
    }
}
