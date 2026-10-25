import Npc from '../objects/Npc.js';
import RemotePlayers from '../objects/RemotePlayers.js';
import Follower from '../objects/Follower.js';
import FieldObjects from '../objects/FieldObjects.js';
import ItemBalls from '../objects/ItemBalls.js';
import BerryBushes, { berrySheet } from '../objects/BerryBushes.js';
import DayNight from '../objects/DayNight.js';
import StoryRunner from '../events/StoryRunner.js';
import * as TilesetData from '../objects/TilesetData.js';
import NeighborMaps from '../objects/NeighborMaps.js';
import { showLocation } from '../ui/LocationBanner.js';
import { loadImages } from '../battle/BattleAssets.js';
import { api } from '../menu/Api.js';
import { toast } from '../ui/Toast.js';
import PixelText from '../ui/PixelText.js';
import { createWindow } from '../ui/WindowSkin.js';
import { sliceCharacterSheet } from './BootScene.js';

// Taille d'une case, en pixels
const TILE = 32;

// Durées, en millisecondes
const WALK_MS = 250;   // un pas en marchant
const RUN_MS = 140;    // un pas en courant (Maj enfoncée)
const SURF_MS = 180;   // un pas en Surf (étape 11)
const BIKE_MS = 100;   // un pas à Bicyclette (étape 14)
const JUMP_MS = 380;   // un saut par-dessus un rebord (étape 16)
const TURN_MS = 100;   // délai après un demi-tour sur place avant de pouvoir avancer

// Profondeurs d'affichage. Les personnages utilisent le y de leurs pieds (entre 0 et la hauteur de la carte) :
// celui qui est plus bas sur l'écran est dessiné devant. "above" et le débogage passent devant tout le monde.
const DEPTH_ABOVE = 100000;
const DEPTH_BUBBLE = 100001;   // le "!" au-dessus d'un dresseur
const DEPTH_DEBUG = 100002;
const DEPTH_DARK = 100010;     // l'obscurité des grottes (étape 11), devant tout
const DEPTH_HUD = 100015;       // étape 15 : le compteur du Parc Safari
const DEPTH_FIELD_MOVE = 100020; // le bandeau "Pikachu utilise Coupe !"

// Ce que disent les Pokémon qui suivent le joueur quand on leur parle ({name} = leur nom)
const FOLLOWER_LINES = [
    '{name} a l\'air ravi d\'être à vos côtés !',
    '{name} vous regarde avec confiance.',
    '{name} fait un petit saut de joie !',
    '{name} renifle les alentours, curieux.',
    '{name} se frotte contre votre jambe.',
    '{name} semble prêt pour la prochaine aventure !',
];

// Pour chaque direction : le décalage en cases, et la ligne correspondante dans la planche du joueur
const DIRECTIONS = {
    down:  { x: 0,  y: 1,  row: 0 },
    left:  { x: -1, y: 0,  row: 1 },
    right: { x: 1,  y: 0,  row: 2 },
    up:    { x: 0,  y: -1, row: 3 },
};

// Direction opposée : un dresseur qui regarde à gauche voit le joueur, qui doit alors regarder à droite
const OPPOSITE = { up: 'down', down: 'up', left: 'right', right: 'left' };

export default class WorldScene extends Phaser.Scene {
    constructor() {
        super('World');
    }

    init(data) {
        this.mapKey = data.map;
        // Case de départ demandée (peut être null : on utilisera alors le point d'apparition)
        this.startTile = data.x != null && data.y != null ? { x: data.x, y: data.y } : null;
        this.facing = data.facing ?? 'down';
        this.isMoving = false;
        this.moveTarget = null; // case vers laquelle le joueur marche (réservée pendant le pas)
        this.wasMoving = false; // le joueur marchait-il juste avant ? (pour savoir s'il faut tourner sur place)
        this.turnUntil = 0;     // heure jusqu'à laquelle on attend après un demi-tour
        this.cutscene = false;  // vrai pendant une "cinématique" (un dresseur vient vers le joueur...)
        this.warped = data.warped === true; // arrive-t-on par une sortie ? (-> sauvegarde immédiate)
        this.warpFrom = data.from ?? null;  // étape 19 : la sortie empruntée (entrée d'une grotte, pour la Corde Sortie)
        this.seamless = data.seamless === true; // étape 25 : arrivée en marchant depuis une carte collée (pas de fondu)
        // Étape 11 : capacités de terrain (remises à zéro à chaque carte, comme dans les jeux)
        this.surfing = false;
        this.strengthActive = false;   // Force utilisée sur cette carte : on peut pousser les rochers
        this.flashUsed = false;        // Flash utilisé dans cette grotte
        this.field = { moves: {}, fly: [] }; // capacités de terrain connues par l'équipe (lues sur le serveur)
        // Étape 14 : à Bicyclette ? On le reste d'une carte à l'autre (gardé dans le registre du jeu)
        this.cycling = this.registry.get('cycling') === true;
    }

    /**
     * Étape 21 : avant de dessiner une carte, on charge SEULEMENT ce qu'il lui faut (si ce n'est pas déjà en mémoire) :
     * son fichier .tmj, puis ses tilesets et les planches de ses PNJ et dresseurs (lus dans le .tmj).
     */
    preload() {
        const config = this.registry.get('config');
        const v = `?v=${config.version}`;
        if (window.gameLoading) {
            this.load.on('progress', (value) => window.gameLoading?.set(0.6 + value * 0.35, 'Chargement de la carte...'));
        }
        // Étape 25 : la carte, et les cartes collées autour (dessinées elles aussi)
        for (const key of [this.mapKey, ...NeighborMaps.around(config, this.mapKey).map((n) => n.key)]) {
            if (this.cache.tilemap.exists(key)) {
                this.queueMapAssets(key);
            } else {
                this.load.tilemapTiledJSON(key, `${config.basePath}/maps/${key}.tmj${v}`);
                this.load.once(`filecomplete-tilemapJSON-${key}`, () => this.queueMapAssets(key));
            }
        }
    }

    /** Les tilesets et les planches de personnages de la carte, qui ne sont pas encore chargés */
    queueMapAssets(mapKey = this.mapKey) {
        const config = this.registry.get('config');
        const v = `?v=${config.version}`;
        const data = this.cache.tilemap.get(mapKey)?.data;
        if (!data) return;
        for (const ts of data.tilesets ?? []) {
            if (ts.name && (config.tilesets ?? []).includes(ts.name) && !this.textures.exists(ts.name)) {
                this.load.image(ts.name, `${config.basePath}/tilesets/${ts.name}.png${v}`);
            }
        }
        TilesetData.queue(this, data);   // les collisions et terrains partagés (tilesets/*.tsj)
        for (const layer of data.layers ?? []) {
            if (layer.type !== 'objectgroup') continue;
            for (const obj of layer.objects ?? []) {
                const props = Object.fromEntries((obj.properties ?? []).map((p) => [p.name, p.value]));
                if (props.sprite && (config.npcSprites ?? []).includes(props.sprite) && !this.textures.exists(`npc_${props.sprite}`)) {
                    this.load.image(`npc_${props.sprite}`, `${config.basePath}/sprites/npc/${props.sprite}.png${v}`);
                } else if (props.sprite && (config.trainerSprites ?? []).includes(props.sprite) && !this.textures.exists(`trainer_${props.sprite}`)) {
                    // Étape 24 : un PNJ ordinaire peut porter une planche de dresseur (professor, lass...)
                    this.load.image(`trainer_${props.sprite}`, `${config.basePath}/sprites/trainers/overworld/${props.sprite}.png${v}`);
                }
                // Étape 24 : un Pokémon posé sur la carte (propriété pokemon = SNORLAX)
                if (layer.name === 'npcs' && typeof props.pokemon === 'string' && /^[A-Za-z0-9_]+$/.test(props.pokemon) && !this.textures.exists(`pkmn_${props.pokemon}`)) {
                    this.load.image(`pkmn_${props.pokemon}`, `${config.basePath}/sprites/pokemon/overworld/${props.pokemon}.png${v}`);
                }
                const overworld = props.trainer ? config.trainers?.[props.trainer]?.overworld : null;
                if (overworld && (config.trainerSprites ?? []).includes(overworld) && !this.textures.exists(`trainer_${overworld}`)) {
                    this.load.image(`trainer_${overworld}`, `${config.basePath}/sprites/trainers/overworld/${overworld}.png${v}`);
                }
                // Étape 22 : buisson à Baies (planches d'Essentials, 4 x 4 images de 32 x 64)
                if (layer.name === 'berries' && props.berry) {
                    for (const sheet of [berrySheet(props.berry), 'berrytreeplanted']) {
                        if (!this.textures.exists(sheet) && !this.queuedSheets?.has(sheet)) {
                            (this.queuedSheets ??= new Set()).add(sheet);
                            this.load.spritesheet(sheet, `${config.basePath}/sprites/berries/${sheet}.png${v}`, { frameWidth: 32, frameHeight: 64 });
                        }
                    }
                }
            }
        }
    }

    /** Étape 21 : l'interface des combats et des menus est-elle chargée ? Sinon on l'attend (avec un petit message) */
    async waitAssets() {
        if (this.registry.get('assetsReady')) return;
        toast('Chargement...');
        await this.registry.get('assetsPromise');
    }

    create() {
        // Étape 21 : les planches de PNJ et de dresseurs arrivées avec cette carte sont découpées en 4 x 4
        for (const key of this.textures.getTextureKeys()) {
            if (key.startsWith('npc_') || key.startsWith('trainer_') || key.startsWith('pkmn_')) sliceCharacterSheet(this, key);
        }

        this.avatar = this.registry.get('config').character.avatar ?? (this.registry.get('config').character.gender === 'female' ? 'leaf' : 'red'); // étape 17 : red, leaf, brendan, may
        this.dialog = this.registry.get('dialog');

        this.createMap();
        this.createAnimations();
        this.fieldObjects = new FieldObjects(this, this.readObjects('field')); // étape 11 : arbres, rochers
        this.itemBalls = new ItemBalls(this);   // étape 16 : Poké Balls par terre (chargées depuis le serveur)
        this.loadItemBalls();
        this.berryBushes = new BerryBushes(this);   // étape 22 : buissons à Baies (étape de pousse donnée par le serveur)
        this.loadBerryBushes();
        this.createPlayer();
        // Étape 14 : carte interdite au vélo (propriété Tiled no_bike), ou arrivée sur l'eau : on descend
        if (this.cycling && (this.mapProperty('no_bike') || this.surfing)) this.setCycling(false, { quiet: true });
        this.stand();
        this.createNpcs();
        this.createFollower();      // étape 11 : le Pokémon qui suit
        this.createDarkness();      // étape 11 : grotte sombre (propriété de carte "dark")
        this.createDayNight();      // étape 23 : teinte du jour et de la nuit (propriété de carte "outdoor")
        this.setupMultiplayer();
        this.refreshField();
        // Étape 24 : le scénario (objets cachés selon les drapeaux, puis événements "auto" de la carte)
        this.story = new StoryRunner(this);
        this.events.once('shutdown', () => { this.follower?.destroy(); this.itemBalls?.destroy(); this.neighbors?.destroy(); this.berryBushes?.destroy(); this.dayNight?.destroy(); this.queuedSheets = null; });
        this.audio = this.registry.get('audio');
        this.playMapMusic(); // étape 12 : la musique de la carte (propriété Tiled "bgm")

        // La caméra suit le joueur.
        // PAS de "true" en 2e paramètre : en mode Canvas, l'arrondi de Phaser étire chaque sprite
        // d'un demi-pixel, ce qui fait déborder un trait de la frame. On arrondit nous-mêmes (voir move()).
        this.cameras.main.startFollow(this.player);
        this.cameras.main.centerOn(this.player.x, this.player.y);

        // Ouverture en fondu depuis le noir (à l'arrivée sur chaque carte) ; étape 25 : pas en marchant d'une carte collée à l'autre
        if (!this.seamless) this.cameras.main.fadeIn(250, 0, 0, 0);
        this.showLocationName();
        // Le Parc Safari : en le quittant, le joueur sort tout seul du bâtiment
        const autoWalk = this.registry.get('autoWalk');
        if (autoWalk) {
            this.registry.set('autoWalk', null);
            this.cutscene = true;
            this.time.delayedCall(350, () => this.walkOut(autoWalk));
        }

        // Sauvegarde automatique : la scène se déclare, et on sauvegarde tout de suite après un changement de carte
        this.saver = this.registry.get('save');
        this.saver.attach(this);
        if (this.warped) {
            // Une fois sauvegardée, cette carte peut être une nouvelle destination de Vol : on relit la liste
            this.saver.save({ from: this.warpFrom }).then(() => this.refreshField());
        }

        // Flèches + ZQSD + Maj pour courir + touches Action (Espace, Entrée, E)
        this.keys = this.input.keyboard.addKeys({
            up: 'UP', down: 'DOWN', left: 'LEFT', right: 'RIGHT',
            z: 'Z', s: 'S', q: 'Q', d: 'D',
            run: 'SHIFT',
            bike: 'B',   // étape 14 : monter sur la Bicyclette / descendre
            fish: 'F',   // étape 15 : pêcher avec la meilleure canne
            space: 'SPACE', enter: 'ENTER', e: 'E',
            // Menu : Échap, X ou M (ou clic droit)
            esc: 'ESC', x: 'X', m: 'M',
        });

        // Outil de débogage : la touche C affiche / cache les cases bloquantes et les hautes herbes
        this.createCollisionDebug();
        this.input.keyboard.on('keydown-C', () => {
            this.collisionDebug.setVisible(!this.collisionDebug.visible);
        });

        // Souris : clic gauche = fait avancer une boîte de dialogue ; clic droit = ouvre le menu
        this.input.mouse?.disableContextMenu();
        this.input.on('pointerdown', (pointer) => {
            if (pointer.rightButtonDown()) {
                if (this.canOpenMenu()) this.openPauseMenu();
                return;
            }
            if (this.dialog.isOpen()) this.dialog.advance();
        });

        // Étape 21 : la carte est prête : l'écran de chargement s'efface (une seule fois, au lancement),
        // et l'interface des combats et des menus se charge en arrière-plan
        if (window.gameLoading) {
            window.gameLoading.done();
            window.gameLoading = null;
        }
        if (!this.registry.get('assetsLaunched')) {
            this.registry.set('assetsLaunched', true);
            this.scene.launch('Assets');
        }

        // Étape 24 : après la mise en place (sauvegarde, caméra) : l'état du scénario et les événements "auto"
        this.story.load();

        // Page rechargée en plein combat : on le reprend directement
        const battleConfig = this.registry.get('config').battle;
        if (battleConfig?.active) {
            const battle = battleConfig.active;
            battleConfig.active = null;
            this.startBattle({ battle, events: [] }, { instant: true });
        }
    }

    /** Calque de débogage : rouge = bloquant, vert = hautes herbes (masqué par défaut) */
    createCollisionDebug() {
        this.collisionDebug = this.add.graphics().setDepth(DEPTH_DEBUG).setVisible(false);

        for (let y = 0; y < this.map.height; y++) {
            for (let x = 0; x < this.map.width; x++) {
                if (this.ledgeAt(x, y)) {
                    this.collisionDebug.fillStyle(0xffd000, 0.5);   // étape 16 : rebord (jaune)
                } else if (this.isBlocked(x, y)) {
                    this.collisionDebug.fillStyle(0xff0000, 0.35);
                } else if (this.hasTileProperty(x, y, 'encounter')) {
                    this.collisionDebug.fillStyle(0x00ff66, 0.35);
                } else {
                    continue;
                }
                this.collisionDebug.fillRect(x * TILE, y * TILE, TILE, TILE);
            }
        }
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    createMap() {
        TilesetData.apply(this, this.mapKey);   // les propriétés des tuiles partagées par toutes les cartes
        this.map = this.make.tilemap({ key: this.mapKey });
        const tilesets = this.map.tilesets.map((ts) => this.map.addTilesetImage(ts.name, ts.name));

        // Calques qui comptent pour les collisions : le sol (eau...) et le décor (murs, troncs...).
        // PAS "above" : ce qui est dessiné devant le joueur (cime des arbres, bord des toits)
        // ne doit pas le bloquer, sinon il ne pourrait jamais passer derrière.
        this.solidLayers = [];

        for (const name of ['ground', 'decor', 'above']) {
            // Calque absent de cette carte (ex. une pièce sans "above") : on passe, sans avertissement
            if (!this.map.getLayer(name)) continue;

            const layer = this.map.createLayer(name, tilesets);
            TilesetData.hideTiles(layer);   // les murs invisibles (tuile « X »)

            if (name === 'above') {
                layer.setDepth(DEPTH_ABOVE);
            } else {
                this.solidLayers.push(layer);
            }
        }

        // Étape 25 : les cartes collées autour (avant la caméra : elle doit pouvoir les montrer)
        this.neighbors = new NeighborMaps(this);
        this.neighbors.create();
        this.setCameraBounds();

        // Les objets placés dans Tiled
        this.warps = this.readObjects('warps').map((obj) => ({
            ...obj,
            toMap: obj.props.to_map,
            toX: Number(obj.props.to_x),  // Number() : au cas où la propriété a été créée en "string" dans Tiled
            toY: Number(obj.props.to_y),
            facing: obj.props.facing,     // facultatif : direction du joueur à l'arrivée
        }));
        this.signs = this.readObjects('signs');
        // Étape 24 : les zones d'événements (calque "events" : propriétés event et trigger = step, action ou auto)
        this.eventZones = this.readObjects('events').filter((z) => z.props.event);
        // Le Parc Safari : la zone devant le comptoir (action = safari_gate, facing = sens du passage, exit = sens de sortie)
        this.gateZones = this.readObjects('events').filter((z) => z.props.action === 'safari_gate');
    }

    /**
     * Limites de la caméra = la carte.
     * Si la carte est plus PETITE que l'écran (une pièce de maison), on agrandit les limites
     * de chaque côté pour que la carte soit CENTRÉE, entourée de noir, comme dans les jeux.
     */
    setCameraBounds() {
        const cam = this.cameras.main;
        const mapW = this.map.widthInPixels;
        const mapH = this.map.heightInPixels;

        // Étape 25 : carte collée à d'autres : la caméra peut montrer les voisines
        if (this.neighbors?.list.length) {
            const b = this.neighbors.bounds(mapW, mapH);
            cam.setBounds(b.x, b.y, b.width, b.height);
            return;
        }

        const marginX = mapW < cam.width ? Math.floor((cam.width - mapW) / 2) : 0;
        const marginY = mapH < cam.height ? Math.floor((cam.height - mapH) / 2) : 0;

        cam.setBounds(-marginX, -marginY, mapW + marginX * 2, mapH + marginY * 2);
    }

    /**
     * Lit un calque d'objets Tiled et convertit chaque objet en CASES :
     * { x, y, width, height, props }   (props = propriétés personnalisées, sous forme d'objet simple)
     */
    readObjects(layerName) {
        const layer = this.map.getObjectLayer(layerName);
        if (!layer) return [];

        return layer.objects.map((obj) => {
            // Tiled donne les propriétés sous forme de liste [{ name, value }] : on en fait un objet simple
            const props = {};
            for (const prop of obj.properties ?? []) {
                props[prop.name] = prop.value;
            }

            // Un "objet tuile" (inséré avec une image) a son y en BAS de l'image, pas en haut
            const top = obj.gid ? obj.y - obj.height : obj.y;

            return {
                id: obj.id,          // étape 24 : numéro de l'objet dans Tiled (événements du scénario)
                name: obj.name ?? '',
                x: Math.floor(obj.x / TILE),
                y: Math.floor(top / TILE),
                width: Math.max(1, Math.round(obj.width / TILE)),   // un point a une largeur de 0 -> 1 case
                height: Math.max(1, Math.round(obj.height / TILE)),
                props,
            };
        });
    }

    /** L'objet de la liste qui couvre la case (x, y), ou undefined */
    objectAt(list, x, y) {
        return list.find((o) => x >= o.x && x < o.x + o.width && y >= o.y && y < o.y + o.height);
    }

    /** La sortie qui couvre la case (x, y), ou undefined */
    warpAt(x, y) {
        return this.objectAt(this.warps, x, y);
    }

    /** Le panneau qui couvre la case (x, y), ou undefined */
    signAt(x, y) {
        return this.objectAt(this.signs, x, y);
    }

    createNpcs() {
        // Dresseurs envoyés par Laravel : { "cremieu_gamin_leo": { name, class, intro, defeat, after, sight, defeated } }
        const trainers = this.registry.get('config').trainers ?? {};

        // Badges du joueur : un PNJ "gardien" (requires_badge / requires_badges) s'écarte quand la condition est remplie
        this.npcs = this.readObjects('npcs').filter((obj) => !this.gateOpen(obj.props)).map((obj) => {
            const data = { x: obj.x, y: obj.y, ...obj.props }; // sprite, text, name, facing, behavior, radius, trainer

            if (obj.props.trainer) {
                data.trainerData = trainers[obj.props.trainer];

                if (!data.trainerData) {
                    console.warn(`PNJ en (${obj.x}, ${obj.y}) : dresseur "${obj.props.trainer}" introuvable dans la table trainers.`);
                } else if (data.behavior !== 'look') {
                    data.behavior = 'static'; // un dresseur ne se promène pas (il peut seulement regarder autour)
                }
            }

            const npc = new Npc(this, data);
            npc.trainerSlug = obj.props.trainer ?? null; // clé du dresseur, envoyée à Laravel pour lancer le combat
            npc.action = obj.props.action ?? null;       // 'heal', 'pc', 'shop', 'title_board'
            npc.props = obj.props;
            // Étape 24 : scénario. Un PNJ avec une condition (show_if / hide_if) reste caché jusqu'à la réponse du serveur
            npc.objectId = obj.id;
            npc.objectName = obj.name;
            npc.eventName = obj.props.event ?? null;
            npc.conditional = obj.props.show_if !== undefined || obj.props.hide_if !== undefined;
            if (npc.conditional) npc.setHidden(true);
            return npc;
        });
    }

    /**
     * Un gardien laisse-t-il passer ? requires_badge = numéro du badge (1 à 8),
     * requires_badges = nombre de badges. Sans ces propriétés : ce n'est pas un gardien (false).
     */
    gateOpen(props) {
        const badges = this.registry.get('config').badges ?? [];
        if (props.requires_badge !== undefined) return badges.includes(Number(props.requires_badge));
        if (props.requires_badges !== undefined) return badges.length >= Number(props.requires_badges);
        return false;
    }

    /** Après un nouveau badge : les gardiens concernés disparaissent */
    updateGates() {
        this.npcs = this.npcs.filter((npc) => {
            if (!this.gateOpen(npc.props ?? {})) return true;
            npc.remove();
            return false;
        });
    }

    /** Le PNJ qui occupe la case (x, y), ou undefined */
    npcAt(x, y) {
        return this.npcs.find((npc) => npc.occupies(x, y));
    }

    /** Un PNJ peut-il marcher sur cette case ? (appelé par Npc.tryStep) */
    canNpcEnter(x, y, npc) {
        if (!this.isInsideMap(x, y) || this.isBlocked(x, y) || this.warpAt(x, y)) return false;   // étape 25 : il reste sur sa carte

        // Ni sur le joueur, ni sur la case où il est en train d'aller
        if (this.tile.x === x && this.tile.y === y) return false;
        if (this.moveTarget && this.moveTarget.x === x && this.moveTarget.y === y) return false;

        // Ni sur le Pokémon qui suit, ni sur un arbre / rocher (étape 11)
        if (this.follower?.occupies(x, y) || this.fieldObjects?.at(x, y) || this.itemBalls?.blocks(x, y) || this.berryBushes?.at(x, y)) return false;

        // Ni sur un autre PNJ
        return !this.npcs.some((other) => other !== npc && other.occupies(x, y));
    }

    createAnimations() {
        // Étape 11 : Surf. Le joueur assis et le Pokémon dessous "flottent" en permanence (4 images par direction)
        for (const [dir, info] of Object.entries(DIRECTIONS)) {
            const first = info.row * 4;
            for (const sheet of [`${this.avatar}_surf`, 'surf_base']) {
                const key = `${sheet}_${dir}`;
                if (this.anims.exists(key) || !this.textures.exists(sheet)) continue;
                this.anims.create({
                    key,
                    frames: this.anims.generateFrameNumbers(sheet, { frames: [first, first + 1, first + 2, first + 3] }),
                    frameRate: 4,
                    repeat: -1,
                });
            }
        }

        // Une animation par mode (marche, course, vélo) et par direction : "male_walk_left", "male_run_up"...
        // Étape 14 : sans planche de vélo (male_bike.png), le vélo utilise la planche de marche
        for (const mode of ['walk', 'run', 'bike']) {
            // Étape 17 : planche absente (course...) -> celle de marche
            const wanted = mode === 'bike' ? this.bikeSheet() : `${this.avatar}_${mode}`;
            const sheet = this.textures.exists(wanted) ? wanted : `${this.avatar}_walk`;
            const name = `${this.avatar}_${mode}`;

            for (const [dir, info] of Object.entries(DIRECTIONS)) {
                const key = `${name}_${dir}`;

                // Les animations appartiennent au jeu entier : on ne les crée qu'une fois
                if (this.anims.exists(key)) continue;

                // Ligne "row" de la planche : frames row*4 à row*4+3.
                // On commence par la colonne 1 (un pied en avant) et on finit par la 0 (immobile).
                const first = info.row * 4;
                this.anims.create({
                    key,
                    frames: this.anims.generateFrameNumbers(sheet, {
                        frames: [first + 1, first + 2, first + 3, first],
                    }),
                    frameRate: mode === 'walk' ? 8 : 12,
                    repeat: -1,
                });
            }
        }
    }

    /** Étape 14 : la planche du joueur à vélo (sprites/player/male_bike.png), ou celle de marche à défaut */
    bikeSheet() {
        return this.textures.exists(`${this.avatar}_bike`) ? `${this.avatar}_bike` : `${this.avatar}_walk`;
    }

    /** Comment le joueur se déplace, pour les autres joueurs : 'surf', 'bike' ou 'walk' */
    moveMode() {
        return this.surfing ? 'surf' : this.cycling ? 'bike' : 'walk';
    }

    createPlayer() {
        // Case demandée si elle est libre (ou si c'est de l'eau : la partie a été sauvegardée en Surf),
        // sinon point d'apparition (évite d'apparaître dans un mur si la carte a été modifiée depuis)
        const t = this.startTile;
        const start = t && (!this.isBlocked(t.x, t.y) || this.isSurfTile(t.x, t.y)) && !this.fieldObjects.at(t.x, t.y)
            ? t
            : this.findSpawn();

        // Position du joueur EN CASES (la grille). Les pixels s'en déduisent.
        this.tile = { x: start.x, y: start.y };

        this.player = this.add.sprite(0, 0, `${this.avatar}_walk`, this.idleFrame());

        // Point d'ancrage au milieu des PIEDS (et non au centre de l'image) :
        // le perso fait 48 px de haut pour une case de 32, sa tête déborde sur la case du dessus.
        this.player.setOrigin(0.5, 1);

        this.player.setPosition(this.pixelX(this.tile.x), this.pixelY(this.tile.y));
        this.player.setDepth(this.player.y); // profondeur = y des pieds (voir DEPTH_ABOVE)

        // Arrivée sur l'eau (partie reprise en Surf, Vol...) : le joueur est déjà sur son Pokémon
        if (this.isSurfTile(this.tile.x, this.tile.y)) this.setSurfing(true);
    }

    findSpawn() {
        // Premier objet du calque "spawns" dans Tiled
        const spawn = this.map.findObject('spawns', () => true);
        if (spawn) {
            return { x: Math.floor(spawn.x / TILE), y: Math.floor(spawn.y / TILE) };
        }
        return { x: 0, y: 0 };
    }

    // ------------------------------------------------------------------
    // Boucle de jeu
    // ------------------------------------------------------------------

    // time = heure actuelle du jeu, en millisecondes
    update(time) {
        this.syncOverlays(); // étape 11 : le Pokémon de Surf et l'obscurité suivent le joueur

        const actionPressed = this.actionJustPressed();

        // Multijoueur : invitation reçue, échange ou combat à lancer (dès que le joueur est libre)
        if (this.processMultiplayer()) return;

        // Étape 13 : le Professeur annonce une récompense du Pokédex
        if (this.pendingDexRewards && this.canOpenMenu() && !this.scene.isActive('Battle') && !this.scene.isActive('Menu')) {
            this.showDexRewards();
            return;
        }

        // Étape 19 : l'effet du Repousse est terminé
        if (this.pendingRepelEnd && this.canOpenMenu() && !this.scene.isActive('Battle') && !this.scene.isActive('Menu')) {
            const info = this.pendingRepelEnd;
            this.pendingRepelEnd = null;
            this.repelEnded(info);
            return;
        }

        // Étape 18 : un Œuf de l'équipe éclot (dès que le joueur est libre)
        if (this.pendingHatches?.length && this.canOpenMenu() && !this.scene.isActive('Battle') && !this.scene.isActive('Menu')) {
            const list = this.pendingHatches;
            this.pendingHatches = [];
            this.openOverlay({ type: 'hatch', list });
            return;
        }

        // Une boîte de dialogue est ouverte : la touche Action la fait avancer, et TOUT le reste attend
        if (this.dialog.isOpen()) {
            if (actionPressed) this.dialog.advance();
            return;
        }

        // Cinématique en cours (un dresseur arrive) : le joueur ne contrôle plus rien
        if (this.cutscene) return;

        // Les PNJ vivent leur vie (tourner la tête, se promener)
        for (const npc of this.npcs) {
            npc.update(time);
        }

        // Pendant un pas, on ne lit pas le clavier : le pas doit se terminer
        if (this.isMoving) return;

        // Un dresseur voit-il le joueur ? (après chaque pas du joueur, ou quand un dresseur tourne la tête)
        if (this.checkTrainerSight()) return;

        if (actionPressed) {
            this.interact();
            return;
        }

        // Menu pause
        const menuPressed = [this.keys.esc, this.keys.x, this.keys.m].map((k) => Phaser.Input.Keyboard.JustDown(k)).includes(true);
        if (menuPressed) {
            this.openPauseMenu();
            return;
        }

        // Étape 14 : touche B = Bicyclette
        if (Phaser.Input.Keyboard.JustDown(this.keys.bike)) {
            this.toggleBike();
            return;
        }

        // Étape 15 : touche F = pêcher
        if (Phaser.Input.Keyboard.JustDown(this.keys.fish)) {
            this.fish();
            return;
        }

        const dir = this.readDirection();

        if (!dir) {
            // Aucune touche : le joueur s'arrête, pose immobile
            this.stand();
            this.wasMoving = false;
            return;
        }

        // TOURNER SANS AVANCER : nouvelle direction alors que le joueur était à l'arrêt
        // -> il se tourne seulement, et on attend un court instant.
        // Si la touche est encore enfoncée après ce délai, il se met à marcher.
        if (dir !== this.facing && !this.wasMoving) {
            this.facing = dir;
            this.stand();
            this.turnUntil = time + TURN_MS;
            this.multi?.sendPosition({ x: this.tile.x, y: this.tile.y, facing: dir, mode: this.moveMode(), follower: this.followerPosition() });
            return;
        }
        if (time < this.turnUntil) return;

        this.move(dir);
    }

    /**
     * Vrai UNE seule fois par appui sur Espace, Entrée ou E (JustDown).
     * On lit les 3 touches à chaque image, sans s'arrêter au premier "vrai" : sinon un appui
     * resterait "en attente" sur une autre touche et serait lu à l'image suivante.
     */
    actionJustPressed() {
        const pressed = [this.keys.space, this.keys.enter, this.keys.e]
            .map((key) => Phaser.Input.Keyboard.JustDown(key));
        return pressed.includes(true);
    }

    readDirection() {
        const k = this.keys;
        if (k.up.isDown || k.z.isDown) return 'up';
        if (k.down.isDown || k.s.isDown) return 'down';
        if (k.left.isDown || k.q.isDown) return 'left';
        if (k.right.isDown || k.d.isDown) return 'right';
        return null;
    }

    // ------------------------------------------------------------------
    // Interactions
    // ------------------------------------------------------------------

    /** Touche Action : parler au PNJ ou lire le panneau qui est JUSTE DEVANT le joueur */
    interact() {
        const d = DIRECTIONS[this.facing];
        const front = { x: this.tile.x + d.x, y: this.tile.y + d.y };

        // Un autre joueur : Échanger / Combattre
        const other = this.remotes?.at(front.x, front.y);
        if (other) {
            this.stand();
            return this.openOverlay({ type: 'player', target: { id: other.id, name: other.name } });
        }

        // Étape 11 : le Pokémon qui suit le joueur
        if (this.follower?.occupies(front.x, front.y)) {
            this.stand();
            this.follower.faceTowards(this.tile);
            const line = FOLLOWER_LINES[Math.floor(Math.random() * FOLLOWER_LINES.length)];
            this.dialog.open(line.replace('{name}', this.follower.pokemon.name));
            return;
        }

        // Étape 16 : un COMPTOIR (propriété de tuile "counter") : on parle au PNJ qui est derrière
        // (un comptoir épais : jusqu'à 3 cases de comptoir, comme le grand comptoir d'un Centre Pokémon)
        let npc = this.npcAt(front.x, front.y);
        for (let k = 0, at = { ...front }; !npc && k < 3 && this.hasTileProperty(at.x, at.y, 'counter'); k++) {
            at = { x: at.x + d.x, y: at.y + d.y };
            npc = this.npcAt(at.x, at.y);
        }
        if (npc) {
            if (npc.isMoving) return;        // il est en train de marcher : on réessaiera
            npc.faceTowards(this.tile);      // il se tourne vers le joueur
            this.stand();

            // Étape 24 : un PNJ du scénario (propriété "event") : c'est son événement qui décide de tout
            if (npc.eventName) return this.story.start(npc.objectId, npc);

            // PNJ spéciaux (propriété Tiled "action") : infirmière, PC, vendeur, tableau des tenants
            if (npc.action === 'heal') return this.nurse(npc);
            if (npc.action === 'pc') return this.usePc();
            if (npc.action === 'shop') return this.openOverlay({ type: 'shop', shop: npc.props.shop ?? 'default', map: this.mapKey });
            if (npc.action === 'title_board') return this.openOverlay({ type: 'titleBoard', slug: npc.props.title });
            if (npc.action === 'gift') return this.receiveGift(npc);   // étape 14
            if (npc.action === 'safari') return this.safariGate(npc);  // étape 15
            // Étape 18 : la Pension (la responsable, et le gardien qui donne des nouvelles)
            if (npc.action === 'daycare') return this.openOverlay({ type: 'daycare', map: this.mapKey, name: npc.name ?? '' });
            if (npc.action === 'daycare_man') return this.daycareMan(npc);
            // Étape 28 : la Tour de Combat (le comptoir, et le tableau des meilleures séries)
            if (npc.action === 'battle_tower') return this.openTower();
            if (npc.action === 'bank') return this.openOverlay({ type: 'bank', map: this.mapKey });   // la Banque
            if (npc.action === 'tower_board') return this.openOverlay({ type: 'towerBoard' });
            // Étape 19 : le Maître des capacités
            if (npc.action === 'move_tutor') return this.openOverlay({ type: 'moveTutor', map: this.mapKey, name: npc.name ?? '' });
            // Étape 20 : le Casino (guichet des jetons, comptoirs des lots, et un PNJ qui garde une machine)
            if (this.casinoAction(npc.action, npc.props)) return;
            if (npc.trainer?.isTitle) return this.titleChallenge(npc);

            if (npc.canChallenge()) {
                // Dresseur pas encore battu, abordé directement : il lance son défi
                this.cutscene = true;
                this.challenge(npc);
            } else if (npc.trainer?.leaderRematch) {
                // Étape 26 : un Champion déjà battu, et le joueur a tous les badges : il propose une revanche
                this.cutscene = true;
                this.dialog.open(npc.trainer.after || npc.text, npc.name, async () => {
                    if (await this.askYesNo('Tu veux une revanche ? Mes Pokémon se sont bien entraînés depuis !')) {
                        this.cutscene = true;
                        this.challenge(npc);
                    } else {
                        this.cutscene = false;
                    }
                });
            } else if (npc.trainer) {
                // Dresseur déjà battu : sa phrase "après le combat"
                this.dialog.open(npc.trainer.after || npc.text, npc.name);
            } else {
                this.dialog.open(npc.text, npc.name);
            }
            return;
        }

        // Étape 16 : une Poké Ball par terre (ou cachée)
        const ball = this.itemBalls.at(front.x, front.y);
        if (ball) {
            this.stand();
            return this.pickItemBall(ball);
        }

        // Étape 22 : un buisson à Baies
        const bush = this.berryBushes?.at(front.x, front.y);
        if (bush) {
            this.stand();
            return this.inspectBerryBush(bush);
        }

        // Étape 11 : petit arbre, rocher fissuré, rocher à pousser, eau
        const obstacle = this.fieldObjects.at(front.x, front.y);
        if (obstacle) {
            this.stand();
            return this.inspectObstacle(obstacle);
        }
        if (!this.surfing && this.isSurfTile(front.x, front.y)) {
            this.stand();
            return this.offerFieldMove('surf', 'L\'eau est d\'un bleu profond...', front);
        }

        // Étape 24 : une zone d'événement qu'on regarde (trigger = action)
        const zone = this.eventZoneAt(front.x, front.y, 'action');
        if (zone) {
            this.stand();
            return this.story.start(zone.id);
        }

        const sign = this.signAt(front.x, front.y);
        if (sign && !this.story.isHidden(sign)) {
            this.stand();
            if (sign.props.event) return this.story.start(sign.id);   // étape 24 : un panneau du scénario
            if (sign.props.action === 'pc') return this.usePc();
            if (sign.props.action === 'title_board') return this.openOverlay({ type: 'titleBoard', slug: sign.props.title });
            if (sign.props.action === 'heal') return this.nurse(null);
            if (sign.props.action === 'tower_board') return this.openOverlay({ type: 'towerBoard' });   // étape 28
            if (sign.props.action === 'bank') return this.openOverlay({ type: 'bank', map: this.mapKey, atm: true });   // distributeur
            if (this.casinoAction(sign.props.action, sign.props)) return;   // étape 20 : machines à sous, roulettes
            this.dialog.open(sign.props.text);
        }
    }

    // ------------------------------------------------------------------
    // Dresseurs
    // ------------------------------------------------------------------

    /**
     * Pour chaque dresseur pas encore battu : regarde les cases DEVANT lui, en ligne droite,
     * jusqu'à sa portée de vue. Un mur ou un autre PNJ lui bouche la vue.
     * Renvoie true si un dresseur a repéré le joueur (la rencontre commence).
     */
    checkTrainerSight() {
        for (const npc of this.npcs) {
            if (!npc.canChallenge() || npc.isMoving) continue;

            const d = DIRECTIONS[npc.facing];
            const range = Number(npc.trainer.sight ?? 4);

            for (let i = 1; i <= range; i++) {
                const x = npc.tile.x + d.x * i;
                const y = npc.tile.y + d.y * i;

                if (x === this.tile.x && y === this.tile.y) {
                    this.startTrainerEncounter(npc, i);
                    return true;
                }
                if (this.isBlocked(x, y) || this.npcAt(x, y)) break; // vue bouchée
            }
        }
        return false;
    }

    /** Le dresseur a vu le joueur : "!", il marche jusqu'à lui, puis lance son défi */
    startTrainerEncounter(npc, distance) {
        this.cutscene = true;
        this.wasMoving = false;
        this.stand();

        this.showExclamation(npc, () => {
            // Il s'arrête sur la case juste devant le joueur : distance - 1 pas
            npc.walk(npc.facing, distance - 1, () => {
                this.facing = OPPOSITE[npc.facing]; // le joueur se tourne vers lui
                this.stand();
                this.challenge(npc);
            });
        });
    }

    /** Affiche le "!" d'Essentials au-dessus de la tête d'un PNJ, puis appelle onDone */
    showExclamation(npc, onDone) {
        const headY = npc.sprite.y - npc.sprite.displayHeight;

        // Dans chaque case de 192 x 192, le bas du "!" est au pixel 110 : on ancre ce point
        // juste au-dessus de la tête du PNJ
        const bubble = this.add.sprite(npc.sprite.x, headY + 4, 'exclamation', 0)
            .setOrigin(0.5, 110 / 192)
            .setDepth(DEPTH_BUBBLE);

        bubble.play('exclamation_pop');
        this.audio?.se('exclaim');
        bubble.once('animationcomplete', () => {
            // Le "!" complet reste affiché un instant avant de disparaître
            this.time.delayedCall(500, () => {
                bubble.destroy();
                onDone();
            });
        });
    }

    /** Le Tenant du titre : il se présente (infos à jour depuis Laravel), puis "Veux-tu me défier ?" */
    titleChallenge(npc) {
        this.openOverlay({ type: 'title', slug: npc.trainerSlug }, (result) => {
            if (!result?.challenge) return;
            this.cutscene = true;
            this.startTrainerBattle(npc);
        });
    }

    /** Dialogue de défi du dresseur, puis le combat */
    challenge(npc) {
        this.dialog.open(npc.trainer.intro || npc.text, npc.name, () => this.startTrainerBattle(npc));
    }

    /** Après son défi, le dresseur se bat : Laravel prépare le combat (son équipe, vérifie qu'il n'a pas déjà été battu) */
    async startTrainerBattle(npc) {
        const data = await this.postJson(this.registry.get('config').battle.trainerUrl, { trainer: npc.trainerSlug });

        if (!data || data.message) {
            // Refusé (déjà battu, aucun Pokémon en forme...) : on l'affiche et le jeu continue
            this.dialog.open(data?.message ?? 'Le combat n\'a pas pu commencer.', '', () => (this.cutscene = false));
            return;
        }
        this.battleNpc = npc;
        this.startBattle(data);
    }

    // ------------------------------------------------------------------
    // Combats
    // ------------------------------------------------------------------

    /**
     * Un pas dans les hautes herbes : une chance sur 10 (réglable) de rencontrer un Pokémon.
     * Le navigateur tire au sort la rencontre, mais c'est Laravel qui vérifie la case
     * et choisit le Pokémon (table wild_encounters).
     */
    async tryWildEncounter() {
        let chance = this.registry.get('config').battle?.encounterChance ?? 10;
        // Étape 22 : Flûte Noire (moins) / Blanche (plus), jusqu'au changement de carte
        const flute = this.registry.get('encounterRate');
        if (flute?.map === this.mapKey) chance = chance * flute.rate / 100;
        if (Math.random() * 100 >= chance) return false;

        this.cutscene = true; // on bloque le joueur pendant la question au serveur
        this.stand();
        const data = await this.postJson(this.registry.get('config').battle.wildUrl, { map: this.mapKey, x: this.tile.x, y: this.tile.y });

        if (!data || !data.battle) {
            this.cutscene = false; // pas de Pokémon ici (pas de table de rencontres pour cette carte)
            return false;
        }
        this.startBattle(data);
        return true;
    }

    /** Transition (deux flashs puis noir), puis l'écran de combat. La carte est mise en pause. */
    startBattle(data, { instant = false } = {}) {
        this.cutscene = true;
        this.wasMoving = false;
        this.stand();

        // Étape 12 : musique du combat (sauvage, dresseur, champion, Tenant du titre, joueur)
        const b = data.battle ?? {};
        this.audio?.bgm(b.type === 'wild' || b.type === 'safari' ? 'battle_wild' : b.type === 'pvp' ? 'battle_pvp' : `battle_${b.foe?.music ?? 'trainer'}`);

        const launch = async () => {
            await this.waitAssets();   // étape 21
            this.scene.launch('Battle', { ...data, onEnd: (result) => this.afterBattle(result) });
            this.scene.sleep();
        };

        if (instant) {
            launch();
            return;
        }

        const cam = this.cameras.main;
        cam.flash(150, 255, 255, 255);
        this.time.delayedCall(300, () => cam.flash(150, 255, 255, 255));
        this.time.delayedCall(600, () => {
            cam.fadeOut(350, 0, 0, 0);
            cam.once('camerafadeoutcomplete', launch);
        });
    }

    /**
     * Étape 27 : la Ligue a avancé (ou tout recommence) : seul le membre dont c'est le tour peut être affronté.
     * On met à jour les dresseurs de la configuration (les salles suivantes les reliront) et ceux de la carte.
     */
    applyLeague(info) {
        const config = this.registry.get('config');
        config.league = { ...config.league, progress: info.progress };
        (info.members ?? config.league.members ?? []).forEach((slug, index) => {
            const defeated = !(config.league.badges && index === info.progress);
            if (config.trainers[slug]) config.trainers[slug].defeated = defeated;
            for (const npc of this.npcs) if (npc.trainerSlug === slug && npc.trainer) npc.trainer.defeated = defeated;
        });
    }

    /** Étape 27 : le Maître est battu : la cérémonie du Panthéon, puis le retour au point prévu (config league.after) */
    hallOfFame(entry) {
        const config = this.registry.get('config');
        config.league = { ...config.league, wins: (config.league?.wins ?? 0) + 1 };
        this.openOverlay({ type: 'hallOfFame', entry }, () => {
            const after = entry.after;
            if (after?.map) this.scriptTeleport(after);
        });
    }

    /** Étape 28 : le comptoir de la Tour de Combat (after : le résultat du combat qui vient de finir) */
    openTower(after = null) {
        this.openOverlay({ type: 'tower', map: this.mapKey, after }, (r) => {
            if (r?.towerBattle) this.startTowerBattle();
        });
    }

    /** Étape 28 : le combat suivant de la série : l'adversaire se présente, puis le combat */
    async startTowerBattle() {
        this.cutscene = true;
        this.stand();
        const data = await this.postJson(this.registry.get('config').menu.towerBattle, { map: this.mapKey });
        if (!data?.battle) {
            this.dialog.open(data?.message ?? 'Le combat n\'a pas pu commencer.', '', () => (this.cutscene = false));
            return;
        }
        const intro = `Combat n° ${data.number} ! ${data.trainer.name} veut se battre !`;
        this.dialog.open(intro, '', () => {
            const go = () => this.startBattle(data);
            if (data.trainer.intro) this.dialog.open(data.trainer.intro, data.trainer.name, go);
            else go();
        });
    }

    /** Retour sur la carte après le combat */
    afterBattle(result) {
        if (result.league) this.applyLeague(result.league);   // étape 27 (avant le K.O. total : tout recommence)
        this.scene.wake();
        this.playMapMusic(); // la musique de la carte reprend (sauf K.O. total : la scène redémarre et la relance)
        this.refreshField(); // un Pokémon a pu être mis K.O. ou évoluer : le Pokémon qui suit change

        // K.O. total : on se réveille ailleurs (dernier Centre Pokémon ou point de départ)
        if (result.result === 'loss' && result.whiteout) {
            if (this.story) this.story.waitingBattle = false;   // étape 24 : l'événement s'arrête là
            const w = result.whiteout;
            this.saver.attach(null);
            this.scene.restart({ map: w.map, x: w.x, y: w.y, facing: w.facing, warped: false });
            return;
        }

        // Dresseur battu : il ne défiera plus le joueur (sauf le Tenant du titre, qu'on peut toujours défier)
        if (result.trainerDefeated && this.battleNpc && !this.battleNpc.trainer.isTitle) {
            this.battleNpc.trainer.defeated = true;
        }
        this.battleNpc = null;

        // Nouveau badge : les gardiens qui l'attendaient s'écartent
        if (result.badge) {
            this.audio?.me('badge');
            const config = this.registry.get('config');
            config.badges = [...new Set([...(config.badges ?? []), result.badge.order])];
            this.updateGates();
        }

        // Le Tenant du titre a changé (le joueur vient de prendre le titre)
        if (result.title?.info) {
            const info = result.title.info;
            this.registry.get('config').trainers[info.slug] = { ...this.registry.get('config').trainers[info.slug], name: info.name, playerSprite: info.playerSprite };
            for (const npc of this.npcs) {
                if (npc.trainerSlug === info.slug) npc.setTitleHolder(info);
            }
        }

        this.cameras.main.resetFX();

        // Étape 15 : Parc Safari, les Balls qui restent, ou la fin de la partie (plus de Balls)
        if (result.safariGame?.ended) {
            this.cameras.main.fadeIn(300, 0, 0, 0);
            this.endSafari(result.safariGame);
            return;
        }
        if (result.safariGame?.safari) {
            this.field.safari = this.withPendingSteps(result.safariGame.safari);
            this.updateSafari();
        }

        // Un Pokémon a gagné un niveau et peut évoluer : écran d'évolution (étape 24 : puis la suite de l'événement)
        // Étape 28 : un combat de la Tour : retour au comptoir (la suite de la série, ou sa fin)
        if (result.tower) {
            this.cameras.main.fadeIn(300, 0, 0, 0);
            this.cutscene = false;
            this.openTower(result.tower);
            return;
        }
        const hallOfFame = result.league?.hallOfFame;   // étape 27 : le Maître est battu
        if (result.evolutions?.length) {
            this.openOverlay({ type: 'evolutions', list: result.evolutions }, () => (hallOfFame ? this.hallOfFame(hallOfFame) : this.story?.afterBattle()));
            return;
        }
        if (hallOfFame) {
            this.hallOfFame(hallOfFame);
            return;
        }

        this.cameras.main.fadeIn(300, 0, 0, 0);
        this.cutscene = false;
        this.story?.afterBattle();   // étape 24 : un combat lancé par un événement : l'événement continue
    }

    // ------------------------------------------------------------------
    // Menus (scène "Menu" par-dessus la carte) : menu pause, PC, évolutions
    // ------------------------------------------------------------------

    canOpenMenu() {
        return !this.cutscene && !this.isMoving && !this.dialog.isOpen();
    }

    /** Ouvre la scène Menu par-dessus la carte ; after(result) est appelé à sa fermeture */
    async openOverlay(task, after = null) {
        this.cutscene = true;
        this.wasMoving = false;
        this.stand();
        await this.waitAssets();   // étape 21 : les images des menus arrivent en arrière-plan
        this.scene.launch('Menu', {
            ...task,
            onClose: (result) => {
                this.scene.resume();
                // Les touches appuyées pendant le menu ne doivent pas agir sur la carte en revenant
                this.input.keyboard.resetKeys();
                const simple = task.type === 'ask' || task.type === 'choose';   // une simple question : pas de fondu
                if (!simple) this.cameras.main.fadeIn(200, 0, 0, 0);
                // Étape 24 : pendant un événement du scénario, le joueur reste bloqué
                this.cutscene = Boolean(this.story?.running);
                // L'équipe a pu changer (ordre, objet, évolution) : Pokémon qui suit et capacités de terrain
                if (!simple) this.refreshField();
                this.playMapMusic(); // après une évolution (musique d'évolution), la musique de la carte reprend
                after?.(result);
            },
        });
        this.scene.pause();
    }

    // ------------------------------------------------------------------
    // Multijoueur (étape 9)
    // ------------------------------------------------------------------

    /** Les autres joueurs de la carte, et mes notifications (invitations, réponses, titre perdu) */
    setupMultiplayer() {
        this.multi = this.registry.get('multi');
        this.remotes = new RemotePlayers(this);
        if (!this.multi) return;

        this.multi.pendingInvites ??= [];
        // Page rechargée en plein combat entre joueurs : on le reprend
        const config = this.registry.get('config').multiplayer;
        if (config?.pvpBattle) {
            this.multi.pendingStart = { battleId: config.pvpBattle };
            config.pvpBattle = null;
        }

        this.registry.get('chat')?.enterMap(this.mapKey);
        // Étape 25 : les joueurs des cartes collées sont visibles aussi ; leurs positions arrivent dans les
        // coordonnées de LEUR carte : on les décale dans celles de la mienne
        const offsets = { [this.mapKey]: { dx: 0, dy: 0 } };
        for (const n of NeighborMaps.around(this.registry.get('config'), this.mapKey)) offsets[n.key] = n;
        this.multi.joinMap(this.mapKey, {
            onMove: (p) => {
                const o = offsets[p.map];
                if (!o) return this.remotes.remove(p.id);   // parti sur une carte que je ne vois pas
                const follower = p.follower ? { ...p.follower, x: p.follower.x + o.dx, y: p.follower.y + o.dy } : p.follower;
                this.remotes.update({ ...p, x: p.x + o.dx, y: p.y + o.dy, follower });
            },
            // Il quitte le canal d'une carte : on ne l'enlève que s'il était sur CETTE carte
            onLeave: (id, key) => {
                if (this.remotes.players.get(id)?.map === key) this.remotes.remove(id);
            },
        }, Object.keys(offsets).filter((k) => k !== this.mapKey));
        this.multi.sendPosition({ x: this.tile.x, y: this.tile.y, facing: this.facing, mode: this.moveMode(), instant: true, follower: this.followerPosition() });
        // La position À JOUR (et le Pokémon qui suit) est relue à chaque renvoi : arrivée d'un joueur, rappel régulier
        this.multi.positionProvider = () => (this.sys.isActive() && !this.isMoving ? this.currentPosition() : null);
        this.events.once('shutdown', () => { if (this.multi) this.multi.positionProvider = null; });

        const handlers = {
            invitation: (data) => this.multi.pendingInvites.push({ ...data, receivedAt: Date.now() }),
            answer: (data) => {
                if (!data.accepted) {
                    toast(`${data.name} a refusé votre invitation.`);
                    return;
                }
                this.multi.pendingStart = data.kind === 'trade' ? { tradeId: data.tradeId } : { battleId: data.battleId };
            },
            title_lost: (data) => toast(`${data.by} vous a pris votre titre de ${data.title} !`, 6),
            // Étape 10 : les outils des administrateurs
            kicked: (data) => this.kicked(data.message),
            // Le compte a ouvert le jeu ailleurs (autre onglet, autre appareil) : cette partie se met en pause
            elsewhere: (data) => {
                if (data.token === this.registry.get('config').playToken) window.playElsewhere?.();
            },
            admin_message: (data) => toast(data.message, 5),
            teleport: (data) => {
                if (data.message) toast(data.message, 4);
                this.multi.pendingStart = { teleport: data };
            },
            spawn: () => (this.multi.pendingStart = { spawn: true }),
        };
        for (const [type, handler] of Object.entries(handlers)) this.multi.on(type, handler);
        // La scène redémarre à chaque changement de carte : on retire ces écoutes
        this.events.once('shutdown', () => {
            for (const [type, handler] of Object.entries(handlers)) this.multi.off(type, handler);
        });
    }

    /** Appelée à chaque image : lance ce qui attend que le joueur soit libre. Renvoie true si quelque chose démarre. */
    processMultiplayer() {
        const multi = this.multi;
        if (!multi || !this.canOpenMenu() || this.scene.isActive('Battle') || this.scene.isActive('Menu')) return false;

        if (multi.pendingStart) {
            const start = multi.pendingStart;
            multi.pendingStart = null;
            if (start.tradeId) this.openOverlay({ type: 'trade', tradeId: start.tradeId });
            else if (start.teleport) this.teleportTo(start.teleport);
            else if (start.spawn) this.startSpawnedBattle();
            else this.startPvp(start.battleId);
            return true;
        }

        // Invitations de moins de 55 secondes (elles expirent au bout de 60)
        multi.pendingInvites = multi.pendingInvites.filter((inv) => Date.now() - inv.receivedAt < 55000);
        const invitation = multi.pendingInvites.shift();
        if (invitation) {
            this.openOverlay({ type: 'invitation', invitation }, (result) => {
                if (result?.tradeId || result?.battleId) multi.pendingStart = result;
            });
            return true;
        }
        return false;
    }

    /** Combat entre joueurs : on lit le début du combat, puis on lance l'écran de combat */
    async startPvp(battleId) {
        this.cutscene = true;
        this.stand();
        const url = this.registry.get('config').multiplayer.pvp.replace('BATTLE_ID', battleId);
        const r = await api.get(url);
        if (!r.ok || !r.data.battle) {
            this.cutscene = false;
            return;
        }
        // Combat déjà commencé (page rechargée) : pas d'animation d'ouverture, on reprend où on en était
        const resumed = r.data.battle.pvp.seq > 0;
        this.startBattle({ battle: r.data.battle, events: resumed ? [] : r.data.events }, { instant: resumed });
    }

    // ------------------------------------------------------------------
    // Administrateurs (étape 10) : ce que leurs commandes déclenchent chez le joueur
    // ------------------------------------------------------------------

    /** /kick ou /ban : message, puis retour à l'écran de sélection (ou à la connexion si banni) */
    kicked(message) {
        if (this.kickedOnce) return;
        this.kickedOnce = true;
        this.saver.attach(null);                     // plus de sauvegarde
        this.game.input.keyboard.enabled = false;    // plus de commandes
        toast(message ?? 'Vous avez été déconnecté.', 10);
        setTimeout(() => window.location.assign(this.registry.get('config').dashboardUrl ?? '/jeu'), 3500);
    }

    /** /tp ou /tpici : le serveur a déjà déplacé le personnage, on recharge la carte à la nouvelle place */
    teleportTo(place) {
        if (!this.registry.get('config').maps.includes(place.map)) return;
        this.isMoving = true;
        this.stand();
        this.saver.attach(null);
        this.cameras.main.fadeOut(250, 0, 0, 0);
        this.cameras.main.once('camerafadeoutcomplete', () => {
            this.scene.restart({ map: place.map, x: place.x, y: place.y, facing: place.facing ?? this.facing, warped: false });
        });
    }

    /** /spawn : le combat est déjà prêt sur le serveur, on le lit et on le lance avec sa transition */
    async startSpawnedBattle() {
        this.cutscene = true;
        this.stand();
        const r = await api.get(this.registry.get('config').battle.currentUrl);
        if (!r.ok || !r.data?.battle) {
            this.cutscene = false;
            return;
        }
        this.startBattle(r.data);
    }

    /** L'infirmière du Centre Pokémon soigne l'équipe (Laravel vérifie qu'il y a bien une infirmière sur cette carte) */
    nurse(npc) {
        const name = npc?.name ?? '';
        this.cutscene = true;
        this.dialog.open('Bonjour ! Bienvenue au Centre Pokémon.\nNous allons soigner vos Pokémon.', name, async () => {
            const data = await this.postJson(this.registry.get('config').menu.heal, { map: this.mapKey, x: this.tile.x, y: this.tile.y });
            // Le jingle du soin (la musique s'arrête pendant ce temps) ; étape 16 : avec l'animation de la machine
            if (data?.party) await this.healAnimation(npc, data.party.length);
            const text = data?.party
                ? 'Vos Pokémon sont en pleine forme !\nNous espérons vous revoir bientôt !'
                : (data?.message ?? 'Le soin n\'a pas pu être fait.');
            this.dialog.open(text, name, () => (this.cutscene = false));
        });
    }

    /**
     * Étape 16 : la MACHINE DE SOIN du Centre Pokémon (planches d'Essentials "Healing balls 1 / 2").
     * L'infirmière se tourne vers la machine, y pose une Ball par Pokémon, puis les Balls clignotent
     * pendant le jingle. La machine est indiquée sur l'infirmière : propriétés Tiled machine_x, machine_y
     * (la case où dessiner les Balls). Sans ces propriétés : seulement le jingle, comme avant.
     */
    async healAnimation(npc, count) {
        const props = npc?.props ?? {};
        const mx = Number(props.machine_x);
        const my = Number(props.machine_y);
        if (!Number.isFinite(mx) || !Number.isFinite(my) || !this.textures.exists('heal_balls_1') || count <= 0) {
            await this.audio?.me('heal');
            return;
        }
        const wait = (ms) => new Promise((resolve) => this.time.delayedCall(ms, resolve));
        const balls = this.add.sprite(this.pixelX(mx), this.pixelY(my), 'heal_balls_1', 0).setOrigin(0.5, 1).setDepth(this.pixelY(my) + 1).setVisible(false);
        // Planche 1 : 1 à 3 Balls (lignes 1 à 3) ; planche 2 : 4 à 6 Balls. 4 colonnes = 4 images du clignotement.
        const show = (n, pattern) => {
            const sheet = n <= 3 ? 'heal_balls_1' : 'heal_balls_2';
            const row = n <= 3 ? n : n - 3;
            balls.setTexture(sheet, row * 4 + pattern).setVisible(true);
        };

        npc.faceTowards({ x: mx, y: my });
        await wait(300);
        for (let n = 1; n <= Math.min(6, count); n++) {
            show(n, 0);
            this.audio?.se('ball_drop');
            await wait(350);
        }
        const total = Math.min(6, count);
        let pattern = 0;
        const blink = this.time.addEvent({ delay: 130, loop: true, callback: () => show(total, (pattern = (pattern + 1) % 4)) });
        await this.audio?.me('heal');
        if (!this.audio) await wait(2500);
        blink.remove();
        balls.destroy();
        npc.faceTowards(this.tile);
    }

    /** Le PC de stockage */
    usePc() {
        this.audio?.se('pc_open');
        this.cutscene = true;
        const name = this.registry.get('config').character.name;
        this.dialog.open(`${name} allume le PC.`, '', async () => {
            // Étape 27 : après une victoire à la Ligue, le PC a aussi le Panthéon
            const league = this.registry.get('config').league;
            if (!league?.wins) return this.openOverlay({ type: 'pc' });
            const choice = await this.askChoice('À quel PC se connecter ?', ['Stockage Pokémon', 'Panthéon', 'Éteindre'], 2);
            if (choice === 0) this.openOverlay({ type: 'pc' });
            else if (choice === 1) this.openOverlay({ type: 'hallOfFamePc' });
            else this.cutscene = false;
        });
    }

    /** POST JSON vers Laravel ; renvoie la réponse (ou null : pas de contenu / erreur réseau) */
    async postJson(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(body),
            });
            if (response.status === 419 || response.status === 401) {
                document.getElementById('session-expired').hidden = false;
                return null;
            }
            if (response.status === 204) return null;
            return await response.json();
        } catch (error) {
            console.warn('Requête impossible :', error);
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Déplacement
    // ------------------------------------------------------------------

    move(dir) {
        this.facing = dir;
        const d = DIRECTIONS[dir];
        const target = { x: this.tile.x + d.x, y: this.tile.y + d.y };

        // Un PNJ se trouve sur la case : on ne peut pas lui passer dessus
        if (this.npcAt(target.x, target.y)) {
            this.stand();
            this.wasMoving = false;
            return;
        }

        // Étape 16 : une Poké Ball par terre bloque le passage (étape 22 : un buisson à Baies aussi)
        if (this.itemBalls.blocks(target.x, target.y) || this.berryBushes?.at(target.x, target.y)) {
            this.stand();
            this.wasMoving = false;
            return;
        }

        // Étape 11 : un obstacle (arbre, rocher). Avec Force, on pousse le rocher.
        const obstacle = this.fieldObjects.at(target.x, target.y);
        if (obstacle) {
            this.stand();
            this.wasMoving = false;
            if (obstacle.kind === 'strength' && this.strengthActive) this.pushBoulder(obstacle, d);
            return;
        }

        // Étape 11 : en Surf, on avance sur l'eau ; une case de terre libre = on descend
        if (this.surfing) {
            if (this.isSurfTile(target.x, target.y)) {
                this.step(dir, target, 'surf');
            } else if (!this.isBlocked(target.x, target.y) || this.warpAt(target.x, target.y)) {
                this.leaveWater(target);
            } else {
                this.stand();
                this.wasMoving = false;
            }
            return;
        }

        // Étape 16 : un REBORD (propriété de tuile "ledge" = down, up, left ou right).
        // On saute par-dessus dans le sens du rebord ; à vélo, dans les deux sens.
        const ledge = this.ledgeAt(target.x, target.y);
        if (ledge) {
            const land = { x: target.x + d.x, y: target.y + d.y };
            const crossing = ledge === dir || (this.cycling && ledge === OPPOSITE[dir]);
            if (crossing && this.canLand(land.x, land.y)) {
                this.step(dir, land, this.cycling ? 'bike' : 'walk', { jump: true });
            } else {
                this.stand();
                this.wasMoving = false;
                this.bump();
            }
            return;
        }

        // Case bloquée (mur, arbre, eau, bord de la carte...) : le joueur reste sur place, tourné vers l'obstacle
        if (this.isBlocked(target.x, target.y)) {
            this.stand();
            this.wasMoving = false;

            // ... sauf si c'est une PORTE : une sortie posée sur une tuile bloquante.
            // On "entre" en marchant dedans, sans faire de pas.
            const door = this.warpAt(target.x, target.y);
            if (door && this.field.safari && this.mapProperty('safari')) {
                this.confirmLeaveSafari(door, target, null, dir);   // Parc Safari : on demande avant de sortir
            } else if (door) {
                this.useWarp(door, target);
            } else {
                this.bump();
            }
            return;
        }

        // COURIR : Maj enfoncée -> planche de course et pas plus court. Étape 14 : à vélo, encore plus vite
        this.step(dir, target, this.cycling ? 'bike' : this.keys.run.isDown ? 'run' : 'walk');
    }

    /**
     * Un pas vers "target" : mode = 'walk', 'run', 'surf' ou 'bike'.
     * Étape 16 : jump = saut par-dessus un rebord (2 cases, en cloche)
     */
    step(dir, target, mode, { jump = false, scripted = null } = {}) {
        const from = { ...this.tile };
        const duration = jump ? JUMP_MS : ({ run: RUN_MS, surf: SURF_MS, bike: BIKE_MS }[mode] ?? WALK_MS);
        if (jump) this.audio?.se('jump');

        this.isMoving = true;
        this.wasMoving = true;
        this.moveTarget = target; // réservée : un PNJ ne peut plus y aller
        // L'animation change aussi l'image utilisée (planche de marche, de course ou de Surf)
        this.player.anims.play(`${this.avatar}_${mode}_${dir}`, true);
        if (mode === 'surf') this.surfBase?.anims.play(`surf_base_${dir}`, true);

        // Étape 11 : le Pokémon qui suit prend la case que le joueur quitte
        if (this.follower?.active) this.follower.stepTo(from.x, from.y, duration);

        // Les autres joueurs de la carte voient ce pas (et mon Pokémon)
        this.multi?.sendPosition({ x: target.x, y: target.y, facing: dir, mode, jump, follower: this.followerPosition() });

        // Un "tween" fait glisser une valeur de 0 à 1 pendant la durée du pas ; on en déduit la position.
        // Saut : le joueur monte puis redescend (arc de 20 pixels).
        const sx = this.player.x;
        const sy = this.player.y;
        const tx = this.pixelX(target.x);
        const ty = this.pixelY(target.y);
        this.tweens.add({
            targets: { p: 0 },
            p: 1,
            duration,
            // À chaque image du pas : position arrondie au pixel près (pixel art net, sans trait parasite)
            onUpdate: (tween) => {
                const p = tween.progress;
                this.player.x = Math.round(sx + (tx - sx) * p);
                this.player.y = Math.round(sy + (ty - sy) * p - (jump ? Math.sin(p * Math.PI) * 20 : 0));
                this.player.setDepth(Math.round(sy + (ty - sy) * p));
            },
            onComplete: () => {
                this.player.setPosition(tx, ty).setDepth(ty);
                this.tile = target;   // le joueur est arrivé : on met à jour sa case
                this.moveTarget = null;
                this.isMoving = false;

                // Étape 24 : un pas joué par un événement du scénario : ni sortie, ni rencontre
                if (scripted) {
                    scripted();
                    return;
                }

                // Étape 25 : le joueur est passé sur une carte collée : on continue sur celle-ci
                if (!this.isInsideMap(target.x, target.y)) {
                    this.crossTo(target);
                    return;
                }

                // Arrivé sur une sortie (bord de route, tapis de sortie d'une maison...) : on change de carte
                const warp = this.warpAt(target.x, target.y);
                if (warp) {
                    // Au Parc Safari, avant la fin de la partie : « Voulez-vous quitter le Parc Safari ? »
                    if (this.field.safari && this.mapProperty('safari')) {
                        this.confirmLeaveSafari(warp, target, from, dir);
                        return;
                    }
                    this.useWarp(warp, target);
                    return;
                }

                // Étape 15 : un pas de moins au Parc Safari (la fin peut ramener le joueur à l'entrée)
                if (this.countSafariStep()) return;

                // L'accueil du Parc Safari arrête le joueur qui passe devant son comptoir
                const gate = this.gateZones?.find((z) => (z.props.facing ?? dir) === dir
                    && target.x >= z.x && target.x < z.x + z.width && target.y >= z.y && target.y < z.y + z.height);
                if (gate && !this.field.safari) {
                    this.stand();
                    this.safariGateStop(gate);
                    return;
                }

                // Étape 24 : une zone d'événement (trigger = step) : l'événement passe avant les rencontres
                const zone = this.eventZoneAt(target.x, target.y, 'step');
                if (zone) {
                    this.stand();
                    this.story.start(zone.id);
                    return;
                }

                // Étape 18 : Pension (Exp., Œufs) et Œufs de l'équipe (éclosion)
                this.countBreedingStep();

                // Dans les hautes herbes, ou sur l'eau en Surf : peut-être un Pokémon sauvage
                // (propriété de carte "encounters_everywhere" : partout, comme dans une grotte)
                if (this.hasTileProperty(target.x, target.y, 'encounter') || (this.surfing && this.isSurfTile(target.x, target.y))
                    || (this.mapProperty('encounters_everywhere') && !this.surfing)) {
                    this.tryWildEncounter();
                }
            },
        });
    }

    /**
     * Emprunte une sortie : fondu au noir, puis la scène redémarre avec la nouvelle carte.
     * "from" = case par laquelle le joueur entre dans la sortie.
     */
    useWarp(warp, from) {
        if (!(this.registry.get('config').maps ?? []).includes(warp.toMap)) {   // étape 21 : chargée à l'arrivée
            // Faute de frappe dans "to_map", ou carte pas encore créée : on prévient au lieu de planter
            console.warn(`Sortie vers "${warp.toMap}" : cette carte n'existe pas (fichier ${warp.toMap}.tmj introuvable).`);
            return;
        }

        // Sortie large (plusieurs cases) : on garde le décalage.
        // Ex. sortie de 2 cases de large, entrée par la 2e case -> arrivée sur la 2e case de destination.
        const x = warp.toX + (from.x - warp.x);
        const y = warp.toY + (from.y - warp.y);

        this.isMoving = true; // bloque le clavier pendant le fondu
        this.stand();
        this.saver.attach(null); // pas de sauvegarde pendant la transition (carte et position ne vont plus ensemble)
        this.audio?.se('door');

        this.cameras.main.fadeOut(250, 0, 0, 0);
        this.cameras.main.once('camerafadeoutcomplete', () => {
            // Redémarre CETTE scène avec d'autres données : init() puis create() sont rappelés
            this.scene.restart({
                map: warp.toMap,
                x,
                y,
                facing: warp.facing ?? this.facing,
                warped: true,
                from: { map: this.mapKey, x: from.x, y: from.y, facing: this.facing },   // étape 19
            });
        });
    }

    /** Arrête l'animation et affiche la pose immobile (toujours celle de la planche de MARCHE) */
    stand() {
        // En Surf, le joueur et son Pokémon flottent en permanence
        if (this.surfing) {
            this.player.anims.play(`${this.avatar}_surf_${this.facing}`, true);
            this.surfBase?.anims.play(`surf_base_${this.facing}`, true);
            return;
        }
        this.player.anims.stop();
        // Étape 14 : à vélo, la pose immobile vient de la planche de vélo
        this.player.setTexture(this.cycling ? this.bikeSheet() : `${this.avatar}_walk`, this.idleFrame());
    }

    // ------------------------------------------------------------------
    // Étape 11 : le Pokémon qui suit le joueur
    // ------------------------------------------------------------------

    /** Le Pokémon est posé derrière le joueur si la case est libre, sinon "caché" sur sa case jusqu'au premier pas */
    createFollower() {
        this.follower = new Follower(this);
        const d = DIRECTIONS[this.facing];
        const behind = { x: this.tile.x - d.x, y: this.tile.y - d.y };
        const free = !this.isBlocked(behind.x, behind.y) && !this.warpAt(behind.x, behind.y)
            && !this.npcAt(behind.x, behind.y) && !this.fieldObjects.at(behind.x, behind.y);
        if (free) this.follower.place(behind.x, behind.y, this.facing);
        else this.follower.place(this.tile.x, this.tile.y, this.facing, true);
        this.follower.setHidden(this.surfing);
        // Une fois sa planche chargée, les autres joueurs doivent le voir (avant, il n'apparaissait qu'au premier pas)
        this.follower.setPokemon(this.registry.get('config').follower ?? null).then(() => this.multi?.resendPosition());
    }

    /** Pour les autres joueurs : quel Pokémon me suit, et sur quelle case */
    followerPosition() {
        const f = this.follower;
        if (!f?.active || !f.pokemon) return null;
        // L'adresse de sa planche SANS le nom du site (/game/sprites/...) : chaque joueur la charge depuis SON
        // adresse. (Avec l'adresse complète, un joueur sur localhost ne pouvait pas charger l'image d'un joueur
        // sur 127.0.0.1 : le navigateur bloque les images d'un autre site.)
        let sprite = f.pokemon.sprite;
        try {
            sprite = new URL(sprite, window.location.href).pathname;
        } catch (error) {
            // adresse déjà relative
        }
        return { sprite, x: f.tile.x, y: f.tile.y, tucked: f.tucked };
    }

    /** Où je suis, pour les autres joueurs (renvoyé quand ils arrivent, et régulièrement) */
    currentPosition() {
        return { x: this.tile.x, y: this.tile.y, facing: this.facing, mode: this.moveMode(), follower: this.followerPosition() };
    }

    /**
     * Relit sur le serveur les capacités de terrain de l'équipe et le Pokémon qui suit
     * (après un menu, un combat : l'ordre de l'équipe, un K.O. ou une évolution changent tout ça).
     */
    async refreshField() {
        const url = this.registry.get('config').field?.index;
        if (!url) return;
        const r = await api.get(url);
        if (!r.ok || !this.sys.isActive() && !this.sys.isSleeping() && !this.sys.isPaused()) return;
        this.field = {
            moves: r.data.moves ?? {}, fly: r.data.fly ?? [], bike: r.data.bike === true,
            rods: r.data.rods ?? [], safari: this.withPendingSteps(r.data.safari), safariRules: r.data.safariRules ?? null, // étape 15
            breeding: r.data.breeding ?? null, // étape 18 : envoyer les pas ? (Pension occupée, Œuf dans l'équipe)
        };
        this.updateSafari();
        // Étape 13 : récompenses du Pokédex à annoncer (montrées dès que le joueur est libre, voir update)
        if (r.data.dexRewards?.length) this.pendingDexRewards = { list: r.data.dexRewards, professor: r.data.professor };
        this.registry.get('config').follower = r.data.follower;
        // Étape 23 : l'heure du jeu (un administrateur a pu forcer le moment avec /heure)
        if (r.data.clock) {
            this.registry.get('config').clock = r.data.clock;
            if (this.dayNight) {
                this.dayNight.clock = r.data.clock;
                this.dayNight.received = Date.now();
                this.dayNight.apply();
            }
        }
        await this.follower?.setPokemon(r.data.follower ?? null);
        this.multi?.resendPosition();   // le Pokémon qui suit a pu changer : les autres joueurs le voient tout de suite
    }

    /** Étape 23 : la teinte du moment de la journée, seulement dehors (propriété de carte Tiled "outdoor") */
    createDayNight() {
        this.dayNight = null;
        const clock = this.registry.get('config').clock;
        if (!clock || !this.mapProperty('outdoor')) return;
        this.dayNight = new DayNight(this, clock);
    }

    /** À chaque image : le Pokémon de Surf sous le joueur, et le trou de lumière autour de lui */
    syncOverlays() {
        if (this.surfBase) this.surfBase.setPosition(this.player.x, this.player.y + 14).setDepth(this.player.depth - 1);
        if (this.darkness) {
            const cam = this.cameras.main;
            this.darkness.setPosition(Math.round(this.player.x - cam.worldView.x), Math.round(this.player.y - 24 - cam.worldView.y));
        }
    }

    // ------------------------------------------------------------------
    // Étape 11 : capacités utilisées hors combat
    // ------------------------------------------------------------------

    isSurfTile(x, y) {
        return this.hasTileProperty(x, y, 'surf');
    }

    /** Propriété de la carte définie dans Tiled (Carte > Propriétés de la carte) */
    mapProperty(name) {
        // Phaser garde la liste de Tiled telle quelle ([{ name, value }]) ;
        // une carte SANS propriété donne un objet vide {} (et non une liste vide)
        const props = this.map.properties;
        if (Array.isArray(props)) return props.find((p) => p.name === name)?.value;
        return props?.[name];
    }

    /** Menu pause ; il peut renvoyer une capacité choisie dans l'équipe ({ field: 'cut' } ou { field: 'fly', to }) */
    async openPauseMenu() {
        this.audio?.se('menu_open');
        // Vol : une ville "fly" est retenue quand la partie est sauvegardée sur sa carte. Si le joueur est
        // dans une ville pas encore retenue (il vient d'arriver), on sauvegarde d'abord, puis on relit la liste.
        if (this.mapProperty('fly') && !this.field.fly?.some((d) => d.map === this.mapKey)) {
            this.cutscene = true;
            this.stand();
            await this.saver.save();
            await this.refreshField();
        }
        this.openOverlay({ type: 'menu', field: this.field, map: this.mapKey }, (result) => {
            if (result?.escape) this.useEscapeRope(result.escape); // étape 19 : Corde Sortie
            if (result?.vsSeeker) this.useVsSeeker();             // étape 26 : Cherche VS
            if (result?.field) this.fieldMoveFromMenu(result);
            if (result?.bike) this.toggleBike({ fromBag: true });
            if (result?.fish) this.fish(result.fish); // étape 15 : une canne utilisée depuis le Sac // étape 14 : Bicyclette utilisée depuis le Sac
        });
    }

    /** Question Oui / Non (avec la boîte de message du menu) : renvoie true ou false */
    askYesNo(text) {
        return new Promise((resolve) => this.openOverlay({ type: 'ask', text }, (answer) => resolve(answer === true)));
    }

    /**
     * Étape 26 : le CHERCHE VS. Le serveur dit quels dresseurs battus, à l'écran, veulent une revanche :
     * ils lèvent la main ("!") et redeviennent des dresseurs à affronter (ils te verront et viendront vers toi).
     */
    async useVsSeeker() {
        this.cutscene = true;
        this.stand();
        const name = this.registry.get('config').character.name;
        await this.say(`${name} utilise le Cherche VS !`);
        this.audio?.se('vs_seeker');
        const data = await this.postJson(this.registry.get('config').menu.vsSeeker, { map: this.mapKey, x: this.tile.x, y: this.tile.y });
        if (!data?.ready) {
            await this.say(data?.message ?? 'Le Cherche VS ne répond pas.');
            this.cutscene = false;
            return;
        }
        for (const slug of data.ready) {
            const npc = this.npcs.find((n) => n.trainerSlug === slug && !n.hidden);
            if (!npc?.trainer) continue;
            npc.trainer.defeated = false;   // il veut une revanche : il défiera le joueur en le voyant
            npc.faceTowards(this.tile);
            await new Promise((resolve) => this.showExclamation(npc, resolve));
        }
        if (data.message) await this.say(data.message);
        this.cutscene = false;
    }

    /** Étape 24 : un message et une liste de choix ; renvoie l'index choisi (Retour = cancelIndex) */
    askChoice(text, labels, cancelIndex = labels.length - 1) {
        return new Promise((resolve) => this.openOverlay({ type: 'choose', text, labels, cancelIndex }, (index) => resolve(index ?? cancelIndex)));
    }

    /** Étape 24 : ouvre un menu et attend sa fermeture */
    openOverlayAsync(task) {
        return new Promise((resolve) => this.openOverlay(task, resolve));
    }

    /** Étape 24 : la zone d'événement (calque "events") de cette case, si elle est visible et du bon type */
    eventZoneAt(x, y, trigger) {
        if (!this.story?.ready) return undefined;
        return this.eventZones.find((z) => (z.props.trigger ?? 'step') === trigger && !this.story.isHidden(z)
            && x >= z.x && x < z.x + z.width && y >= z.y && y < z.y + z.height);
    }

    /** Étape 24 : un pas du joueur commandé par un événement (aucune vérification : le script décide) */
    scriptedStep(dir) {
        const d = DIRECTIONS[dir];
        return new Promise((resolve) => this.step(dir, { x: this.tile.x + d.x, y: this.tile.y + d.y }, this.surfing ? 'surf' : 'walk', { scripted: () => {
            this.stand();
            resolve();
        } }));
    }

    /** Étape 24 : le joueur se tourne (événement du scénario) */
    scriptedFace(dir) {
        this.facing = dir;
        this.stand();
        this.multi?.sendPosition({ x: this.tile.x, y: this.tile.y, facing: dir, mode: this.moveMode(), follower: this.followerPosition() });
    }

    /** Étape 24 : "teleporter" dans un événement (le serveur a déjà enregistré la nouvelle position) */
    scriptTeleport(place) {
        if (!this.registry.get('config').maps.includes(place.map)) return;
        this.isMoving = true;
        this.stand();
        this.saver.attach(null);
        this.cameras.main.fadeOut(300, 0, 0, 0);
        this.cameras.main.once('camerafadeoutcomplete', () => {
            this.scene.restart({ map: place.map, x: place.x, y: place.y, facing: place.facing ?? this.facing, warped: true });
        });
    }

    /** Ouvre une boîte de dialogue et attend qu'elle soit fermée */
    say(text) {
        return new Promise((resolve) => this.dialog.open(text, '', resolve));
    }

    /** Action devant un arbre / un rocher */
    inspectObstacle(obstacle) {
        const texts = {
            cut: ['cut', 'Ce petit arbre a l\'air de pouvoir être coupé !'],
            rock_smash: ['rock-smash', 'Ce rocher a l\'air fragile. Un Pokémon pourrait le briser !'],
            strength: ['strength', 'C\'est un gros rocher. Un Pokémon costaud pourrait le pousser.'],
        };
        const [slug, text] = texts[obstacle.kind];
        if (obstacle.kind === 'strength' && this.strengthActive) {
            return this.dialog.open('Force est déjà utilisée : marchez vers le rocher pour le pousser.');
        }
        return this.offerFieldMove(slug, text, { x: obstacle.x, y: obstacle.y }, obstacle);
    }

    /**
     * Le texte de l'obstacle, puis, si un Pokémon de l'équipe connaît la capacité :
     * "Voulez-vous utiliser Coupe ?" (ou pourquoi c'est impossible : badge manquant).
     */
    async offerFieldMove(slug, text, target, obstacle = null) {
        this.cutscene = true;
        await this.say(text);
        const move = this.field.moves?.[slug];
        if (!move) {
            this.cutscene = false;
            return;
        }
        if (!move.allowed) {
            await this.say(move.reason);
            this.cutscene = false;
            return;
        }
        const yes = await this.askYesNo(`Voulez-vous utiliser ${move.name} ?`);
        if (!yes) {
            this.cutscene = false;
            return;
        }
        await this.useFieldMove(slug, target, { obstacle });
    }

    /** Une capacité choisie dans le menu Pokémon : on regarde ce qu'il y a devant le joueur */
    fieldMoveFromMenu({ field: slug, to }) {
        const d = DIRECTIONS[this.facing];
        const front = { x: this.tile.x + d.x, y: this.tile.y + d.y };
        const obstacle = this.fieldObjects.at(front.x, front.y);
        const kinds = { cut: 'cut', 'rock-smash': 'rock_smash', strength: 'strength' };
        const nothing = () => this.dialog.open('Impossible d\'utiliser cette capacité ici.');

        if (kinds[slug]) {
            if (slug === 'strength' && this.fieldObjects.list.some((o) => o.kind === 'strength')) return this.useFieldMove(slug, front, { obstacle });
            if (obstacle?.kind !== kinds[slug]) return nothing();
            return this.useFieldMove(slug, front, { obstacle });
        }
        if (slug === 'surf') return !this.surfing && this.isSurfTile(front.x, front.y) ? this.useFieldMove('surf', front) : nothing();
        if (slug === 'flash') return this.mapProperty('dark') && !this.flashUsed ? this.useFieldMove('flash', this.tile) : this.dialog.open('Il fait déjà assez clair ici.');
        if (slug === 'fly') return this.useFieldMove('fly', this.tile, { to });
        return nothing();
    }

    /**
     * Demande au serveur (qui vérifie tout), puis : "Pikachu utilise Surf !", le bandeau, et l'effet.
     */
    async useFieldMove(slug, target, { obstacle = null, to = null } = {}) {
        this.cutscene = true;
        this.stand();
        const r = await api.post(this.registry.get('config').field.use, { move: slug, map: this.mapKey, x: target.x, y: target.y, to });
        if (!r.ok) {
            this.audio?.se('buzzer');
            await this.say(r.data?.message ?? 'Impossible d\'utiliser cette capacité.');
            this.cutscene = false;
            return;
        }

        const move = this.field.moves?.[slug];
        const [first, ...rest] = (r.data.text ?? '').split('\n');
        await this.say(first);
        await this.fieldMoveAnimation(move?.sprite);
        if (rest.length) await this.say(rest.join('\n'));

        switch (slug) {
            case 'cut':
            case 'rock-smash':
                this.audio?.se(slug === 'cut' ? 'cut' : 'rock_smash');
                if (obstacle) await this.fieldObjects.destroy(obstacle);
                if (r.data.battle) return this.startBattle(r.data.battle);   // un Pokémon sauvage sortait du rocher !
                break;
            case 'strength':
                this.strengthActive = true;
                break;
            case 'surf':
                return this.enterWater(target);
            case 'flash':
                this.lightUp();
                break;
            case 'fly':
                return this.flyTo(r.data.fly);
        }
        this.cutscene = false;
    }

    /**
     * Le bandeau des capacités de terrain d'Essentials (UI/Field move/bg.png) : il s'ouvre
     * au milieu de l'écran, le Pokémon le traverse, puis il se referme.
     */
    async fieldMoveAnimation(spriteUrl) {
        if (!this.textures.exists('field_move_bg')) return;
        const key = spriteUrl ? `fieldmove_${spriteUrl}` : null;
        if (key) await loadImages(this, { [key]: spriteUrl });

        const box = this.add.container(0, 0).setScrollFactor(0).setDepth(DEPTH_FIELD_MOVE);
        const band = this.add.image(256, 192, 'field_move_bg').setScale(1, 0);
        box.add(band);
        const mon = key && this.textures.exists(key) ? this.add.image(640, 192, key) : null;
        if (mon) box.add(mon);

        const tween = (config) => new Promise((resolve) => this.tweens.add({ ...config, onComplete: resolve }));
        await tween({ targets: band, scaleY: 1, duration: 200 });
        if (mon) {
            await tween({ targets: mon, x: 256, duration: 350, ease: 'Cubic.easeOut' });
            await tween({ targets: mon, x: 256, duration: 600 });
            await tween({ targets: mon, x: -128, duration: 300, ease: 'Cubic.easeIn' });
        } else {
            await tween({ targets: band, scaleY: 1, duration: 800 });
        }
        await tween({ targets: band, scaleY: 0, duration: 200 });
        box.destroy(true);
    }

    /** Force : le rocher avance d'une case si la case suivante est libre */
    async pushBoulder(boulder, d) {
        const to = { x: boulder.x + d.x, y: boulder.y + d.y };
        const free = !this.isBlocked(to.x, to.y) && !this.warpAt(to.x, to.y) && !this.npcAt(to.x, to.y)
            && !this.fieldObjects.at(to.x, to.y) && !this.follower?.occupies(to.x, to.y) && !this.remotes?.at(to.x, to.y);
        if (!free || this.pushing) return;
        this.pushing = true;
        this.audio?.se('strength');
        await this.fieldObjects.push(boulder, to.x, to.y);
        this.pushing = false;
    }

    /** Montée sur le Pokémon (Surf) : petit saut sur l'eau */
    enterWater(target) {
        this.setSurfing(true);
        this.jumpTo(target, () => {
            this.cutscene = false;
            this.multi?.sendPosition({ x: target.x, y: target.y, facing: this.facing, mode: 'surf', instant: true, follower: null });
        });
    }

    /** Descente sur la terre ferme : petit saut, le Pokémon qui suit réapparaît */
    leaveWater(target) {
        this.isMoving = true;
        this.setSurfing(false);
        this.jumpTo(target, () => {
            this.isMoving = false;
            // Le Pokémon qui suit ressort de sa Ball au premier pas sur la terre
            this.follower?.place(target.x, target.y, this.facing, true);
            this.follower?.setHidden(false);
            this.multi?.sendPosition({ x: target.x, y: target.y, facing: this.facing, mode: 'walk', instant: true, follower: this.followerPosition() });
            const warp = this.warpAt(target.x, target.y);
            if (warp) this.useWarp(warp, target);
        });
    }

    /** Saut d'une case (montée ou descente du Surf) */
    jumpTo(target, onDone) {
        this.moveTarget = target;
        this.audio?.se('jump');
        this.tweens.add({
            targets: this.player,
            x: this.pixelX(target.x),
            y: this.pixelY(target.y),
            duration: 280,
            onUpdate: (tween) => {
                // Arc de saut : jusqu'à 10 pixels plus haut au milieu du saut
                const lift = Math.round(Math.sin(tween.progress * Math.PI) * 10);
                this.player.x = Math.round(this.player.x);
                this.player.y = Math.round(this.player.y) - lift;
                this.player.setDepth(this.player.y + lift);
            },
            onComplete: () => {
                this.tile = target;
                this.moveTarget = null;
                this.stand();
                onDone?.();
            },
        });
    }

    /** Passe en Surf (planche du joueur assis, Pokémon dessous) ou revient à pied */
    setSurfing(on) {
        this.surfing = on;
        if (on && this.cycling) this.setCycling(false, { quiet: true }); // étape 14 : on range le vélo
        if (on && !this.textures.exists(`${this.avatar}_surf`)) console.warn(`sprites/player/${this.avatar}_surf.png introuvable : relance game:essentials-assets.`);
        if (on && !this.surfBase && this.textures.exists('surf_base')) {
            this.surfBase = this.add.sprite(this.player.x, this.player.y, 'surf_base', DIRECTIONS[this.facing].row * 4).setOrigin(0.5, 1);
        }
        if (!on) {
            this.surfBase?.destroy();
            this.surfBase = null;
        }
        this.follower?.setHidden(on);
        this.stand();
        this.syncOverlays();
        this.playMapMusic(); // étape 12 : musique de Surf sur l'eau
    }

    /** Vol : un fondu blanc, puis la ville choisie */
    flyTo(place) {
        this.saver.attach(null);
        this.audio?.se('fly');
        this.cameras.main.fadeOut(400, 255, 255, 255);
        this.cameras.main.once('camerafadeoutcomplete', () => {
            this.scene.restart({ map: place.map, x: place.x, y: place.y, facing: place.facing ?? 'down', warped: true });
        });
    }

    /**
     * Grotte sombre (propriété de carte "dark") : tout est noir sauf un petit cercle autour du joueur.
     * Une seule image (un rectangle noir percé d'un trou flou) qui suit le joueur ; Flash l'agrandit.
     */
    createDarkness() {
        this.darkness = null;
        if (!this.mapProperty('dark')) return;
        if (!this.textures.exists('darkness')) {
            const w = 1100;
            const h = 850;
            const canvas = this.textures.createCanvas('darkness', w, h);
            const ctx = canvas.getContext();
            ctx.fillStyle = '#000';
            ctx.fillRect(0, 0, w, h);
            const g = ctx.createRadialGradient(w / 2, h / 2, 40, w / 2, h / 2, 72);
            g.addColorStop(0, 'rgba(0,0,0,1)');
            g.addColorStop(1, 'rgba(0,0,0,0)');
            ctx.globalCompositeOperation = 'destination-out';
            ctx.fillStyle = g;
            ctx.beginPath();
            ctx.arc(w / 2, h / 2, 72, 0, Math.PI * 2);
            ctx.fill();
            canvas.refresh();
        }
        this.darkness = this.add.image(0, 0, 'darkness').setScrollFactor(0).setDepth(DEPTH_DARK);
        this.syncOverlays();
    }

    /** Flash : le cercle de lumière s'agrandit (jusqu'à la sortie de la grotte) */
    lightUp() {
        this.flashUsed = true;
        if (!this.darkness) return;
        this.tweens.add({ targets: this.darkness, scale: 3, duration: 900, ease: 'Sine.easeOut' });
    }

    // ------------------------------------------------------------------
    // Étape 13 : récompenses du Pokédex
    // ------------------------------------------------------------------

    /** "Tu as capturé 10 Pokémon ! Voici ta récompense : 10 Poké Ball." (déjà dans le Sac, donnée par le serveur) */
    async showDexRewards() {
        const { list, professor } = this.pendingDexRewards;
        this.pendingDexRewards = null;
        this.cutscene = true;
        this.stand();
        // Annoncé : le serveur oublie ces messages (les objets sont déjà dans le Sac)
        await api.post(this.registry.get('config').pokedex.ack);
        for (const reward of list) {
            const text = reward.tier === 'complete'
                ? `Incroyable ! Tu as complété le Pokédex : ${reward.count} Pokémon !\nVoici une récompense exceptionnelle !`
                : `Bravo ! Tu as déjà capturé ${reward.count} Pokémon différents !\nVoici une récompense pour ton travail.`;
            await new Promise((resolve) => this.dialog.open(text, professor, resolve));
            this.audio?.me('item');
            await new Promise((resolve) => this.dialog.open(`Tu reçois : ${reward.gifts.join(', ')} !`, '', resolve));
        }
        this.cutscene = false;
    }

    // ------------------------------------------------------------------
    // Étape 14 : Bicyclette et PNJ qui offrent un objet
    // ------------------------------------------------------------------

    // ---------------- Étape 16 : Poké Balls par terre ----------------

    /** Les Balls encore présentes sur cette carte (le serveur retire celles déjà ramassées) */
    async loadItemBalls() {
        const url = this.registry.get('config').menu.itemBalls;
        if (!url) return;
        const map = this.mapKey;
        const r = await api.get(`${url}?map=${encodeURIComponent(map)}`);
        if (!r.ok || map !== this.mapKey) return;
        this.itemBalls.set(r.data.balls ?? []);
    }

    /** Ramasser une Ball : le serveur donne la récompense (objet, argent ou Pokémon) */
    async pickItemBall(ball) {
        this.cutscene = true;
        const data = await this.postJson(this.registry.get('config').menu.itemBallPick, { map: this.mapKey, key: ball.key });
        if (!data?.messages) {
            this.itemBalls.remove(ball.key);
            this.dialog.open(data?.message ?? 'Il n\'y a rien ici.', '', () => (this.cutscene = false));
            return;
        }
        this.itemBalls.remove(ball.key);
        this.audio?.me(data.me ?? 'item');
        const show = (i) => {
            if (i >= data.messages.length) {
                this.cutscene = false;
                return;
            }
            this.dialog.open(data.messages[i], '', () => show(i + 1));
        };
        show(0);
    }

    // ---------------- Étape 22 : buissons à Baies ----------------

    async loadBerryBushes() {
        const url = this.registry.get('config').menu.berries;
        if (!url || !this.readObjects('berries').length) return;
        const map = this.mapKey;
        const r = await api.get(`${url}?map=${encodeURIComponent(map)}`);
        if (!r.ok || map !== this.mapKey) return;
        this.berryBushes.set(r.data.bushes ?? []);
    }

    /** Regarder un buisson : son étape de pousse, ou "Il y a 3 Baies Oran ! Voulez-vous les cueillir ?" */
    async inspectBerryBush(bush) {
        this.cutscene = true;
        const urls = this.registry.get('config').menu;
        const info = await this.postJson(urls.berryInspect, { map: this.mapKey, key: bush.key });
        const say = (text) => this.say(text);
        if (!info || info.stage === undefined) {
            await say(info?.message ?? 'Il n\'y a rien ici.');
            this.cutscene = false;
            return;
        }
        this.berryBushes.setStage(bush.key, info.stage);
        if (info.stage < 4) {
            await say(info.text);
            this.cutscene = false;
            return;
        }
        const what = info.quantity > 1 ? `${info.quantity} ${info.plural}` : `une ${info.name}`;
        if (await this.askYesNo(`Il y a ${what} !\nVoulez-vous ${info.quantity > 1 ? 'les' : 'la'} cueillir ?`)) {
            const r = await api.post(urls.berryPick, { map: this.mapKey, key: bush.key });
            this.cutscene = true;
            if (!r.ok) {
                await say(r.data.message ?? 'Impossible.');
            } else {
                this.berryBushes.setStage(bush.key, 0);
                this.audio?.me('item');
                for (const text of r.data.messages ?? []) await say(text);
            }
        } else {
            this.cutscene = true;
            await say(`Vous laissez ${info.quantity > 1 ? 'les' : 'la'} ${info.quantity > 1 ? info.plural : info.name} sur le plant.`);
        }
        this.cutscene = false;
    }

    // ---------------- Étape 15 : la pêche ----------------

    /**
     * Pêcher face à l'eau. itemId : la canne choisie dans le Sac ; sans : la meilleure (touche F).
     * Le serveur décide si ça mord et quand ; le joueur doit appuyer sur Action au "!".
     */
    async fish(itemId = null) {
        const rods = this.field.rods ?? [];
        const rod = itemId ? (rods.find((r) => r.id === itemId) ?? { id: itemId }) : rods[0];
        if (!rod) return; // touche F sans canne : rien

        const d = DIRECTIONS[this.facing];
        const front = { x: this.tile.x + d.x, y: this.tile.y + d.y };
        if (!this.isSurfTile(front.x, front.y) || this.npcAt(front.x, front.y) || this.fieldObjects.at(front.x, front.y)) {
            this.audio?.se('buzzer');
            this.dialog.open('Ce n\'est pas le moment d\'utiliser ça !');
            return;
        }
        if (this.cycling) this.setCycling(false);
        this.cutscene = true;
        this.wasMoving = false;
        this.stand();
        const finish = () => (this.cutscene = false);

        await this.fishingPose(true);
        const data = await this.postJson(this.registry.get('config').menu.fishCast, { map: this.mapKey, x: front.x, y: front.y, item_id: rod.id });
        if (!data || data.message || data.bite === undefined) {
            await this.fishingPose(false);
            this.dialog.open(data?.message ?? 'La canne ne veut pas fonctionner...', '', finish);
            return;
        }

        // ".", ". .", ". . ." au-dessus de la tête pendant l'attente
        const dots = new PixelText(this, this.player.x, this.player.y - 60, '', { color: '#f8f8f8', shadow: '#383838', align: 'center' }).setDepth(DEPTH_BUBBLE);
        this.add.existing(dots);
        for (let i = 1; i <= 3; i++) {
            dots.setText('.'.repeat(i).split('').join(' '));
            // Vraie horloge (et non celle du jeu) : le serveur attend la réponse à une heure précise
            await new Promise((resolve) => setTimeout(resolve, data.wait / 3));
        }
        dots.destroy();

        if (!data.bite) {
            await this.fishingPose(false);
            this.dialog.open('Ça ne mord pas...', '', finish);
            return;
        }

        // Ça mord ! Le "!" et le joueur doit appuyer sur Action à temps
        const bubble = this.add.sprite(this.player.x, this.player.y - 48, 'exclamation', 0).setOrigin(0.5, 110 / 192).setDepth(DEPTH_BUBBLE);
        bubble.play('exclamation_pop');
        this.audio?.se('exclaim');
        const hooked = await this.waitForAction(data.reaction);
        bubble.destroy();

        const r = hooked ? await this.postJson(this.registry.get('config').menu.fishHook, {}) : null;
        await this.fishingPose(false);
        if (!r?.battle) {
            this.dialog.open(hooked ? 'Le Pokémon s\'est enfui...' : 'Pas de chance... Le Pokémon s\'est enfui.', '', finish);
            return;
        }
        this.dialog.open('Un Pokémon a mordu !', '', () => this.startBattle(r.battle));
    }

    /** Lancer (true) ou relever (false) la ligne : planche {personnage}_fish (4 x 4 images, ex. 96 x 80) */
    async fishingPose(cast) {
        const sheet = `${this.avatar}_fish`;
        if (this.surfing || !this.textures.exists(sheet)) return; // en Surf, ou sans planche : pas d'animation
        const row = DIRECTIONS[this.facing].row;
        this.player.anims.stop();
        // Dans ces images, les pieds sont 16 pixels au-dessus du bas : on ancre le sprite là
        this.player.setOrigin(0.5, 64 / 80);
        const frames = cast ? [3, 2, 1, 0] : [0, 1, 2, 3];
        for (const f of frames) {
            this.player.setTexture(sheet, row * 4 + f);
            await this.delay(80);
        }
        if (!cast) {
            this.player.setOrigin(0.5, 1);
            this.stand();
        }
    }

    /** Attend un appui sur Action (Espace, Entrée, E, clic) pendant ms ; renvoie true s'il a eu lieu */
    waitForAction(ms) {
        return new Promise((resolve) => {
            // Écoute directe du navigateur : la réponse doit être immédiate, même si une image du jeu tarde
            let finished = false;
            const done = (value) => {
                if (finished) return;
                finished = true;
                window.removeEventListener('keydown', onKey);
                this.input.off('pointerdown', onClick);
                clearTimeout(timer);
                resolve(value);
            };
            const onKey = (event) => { if ([' ', 'Enter', 'e', 'E'].includes(event.key)) done(true); };
            const onClick = () => done(true);
            const timer = setTimeout(() => done(false), ms);
            window.addEventListener('keydown', onKey);
            this.input.on('pointerdown', onClick);
        });
    }

    delay(ms) {
        return new Promise((resolve) => this.time.delayedCall(ms, resolve));
    }

    // ---------------- Étape 15 : le Parc Safari ----------------

    /** L'accueil du Parc arrête le joueur (zone action = safari_gate) : « ! », le dialogue, et s'il refuse, il ressort */
    safariGateStop(gate) {
        const npc = this.npcs.find((n) => n.action === 'safari');
        if (!npc) return;
        this.cutscene = true;
        this.showExclamation(npc, () => {
            npc.faceTowards?.(this.tile);
            this.safariGate(npc, () => this.walkOut(gate.props.exit ?? OPPOSITE[this.facing]));
        });
    }

    /** Quitter le Parc avant la fin : on demande ; oui -> on sort du bâtiment tout seul ; non -> un pas en arrière */
    async confirmLeaveSafari(warp, target, from, dir) {
        this.cutscene = true;
        this.stand();
        const yes = await this.askYesNo('Voulez-vous quitter le Parc Safari ?');
        this.cutscene = true;
        if (yes) {
            this.registry.set('autoWalk', warp.facing ?? dir);
            this.useWarp(warp, target);
            return;
        }
        if (from) await this.scriptedStep(OPPOSITE[dir]);   // il était entré sur la sortie : un pas en arrière
        this.scriptedFace(OPPOSITE[dir]);
        this.cutscene = false;
    }

    /** Le joueur marche tout seul dans ce sens jusqu'à la sortie (porte, tapis, bord de carte) */
    async walkOut(dir) {
        this.cutscene = true;
        this.scriptedFace(dir);
        const d = DIRECTIONS[dir];
        for (let i = 0; i < 15; i++) {
            const next = { x: this.tile.x + d.x, y: this.tile.y + d.y };
            const exit = this.warpAt(next.x, next.y);
            if (exit && this.isBlocked(next.x, next.y)) {
                this.useWarp(exit, next);       // une porte : on y entre sans faire de pas
                return;
            }
            if (exit || !this.isInsideMap(next.x, next.y)) {
                this.step(dir, next, 'walk');   // un vrai pas : la sortie est empruntée
                return;
            }
            if (this.isBlocked(next.x, next.y) || this.npcAt(next.x, next.y)) break;
            await this.scriptedStep(dir);
        }
        this.cutscene = false;
    }

    /** Le gardien de l'entrée (propriétés Tiled action = safari, to_map, to_x, to_y) ; leave : s'il refuse */
    async safariGate(npc, leave = null) {
        const name = npc.name ?? '';
        const done = () => (leave ? leave() : (this.cutscene = false));
        const rules = this.field.safariRules ?? { price: 500, balls: 30, steps: 500 };
        this.cutscene = true;
        if (this.field.safari) {
            this.dialog.open('Vous êtes déjà en pleine partie de Safari. Bonne chasse !', name, () => (this.cutscene = false));
            return;
        }
        if (npc.props?.text) await new Promise((resolve) => this.dialog.open(npc.props.text, name, resolve));
        const steps = rules.steps > 0 ? ` et vous pouvez faire ${rules.steps} pas` : '';
        const yes = await this.askYesNo(`Bienvenue au Parc Safari ! L'entrée coûte ${rules.price} ₽. Vous recevez ${rules.balls} Safari Balls${steps}. Vous entrez ?`);
        this.cutscene = true;
        if (!yes) {
            this.dialog.open('Revenez quand vous voulez !', name, done);
            return;
        }
        // Plusieurs zones (propriété to_map = "safari_kanto,safari_jotho..." et zone_names = "Kanto,Johto...") : on choisit
        let zone = null;
        const zones = String(npc.props?.to_map ?? '').split(',').map((z) => z.trim()).filter(Boolean);
        if (zones.length > 1) {
            const names = String(npc.props?.zone_names ?? '').split(',').map((z) => z.trim());
            const labels = zones.map((z, i) => names[i] || z);
            const choice = await this.askChoice('Quelle zone du Parc voulez-vous visiter ?', [...labels, 'Annuler']);
            this.cutscene = true;
            if (choice === null || choice >= zones.length) {
                this.dialog.open('Revenez quand vous voulez !', name, done);
                return;
            }
            zone = zones[choice];
        }
        const data = await this.postJson(this.registry.get('config').menu.safariEnter, { map: this.mapKey, x: this.tile.x, y: this.tile.y, facing: this.facing, zone });
        if (!data?.to) {
            this.dialog.open(data?.message ?? 'L\'entrée est fermée.', name, done);
            return;
        }
        this.audio?.se('buy');
        this.dialog.open(`Voici vos ${data.safari.balls} Safari Balls. Bonne chasse !`, name, () => {
            this.field.safari = data.safari;
            this.teleportTo({ map: data.to.map, x: data.to.x, y: data.to.y, facing: data.to.facing });
        });
    }

    /** Le compteur en haut à gauche (pas et Safari Balls), sur les cartes du Parc seulement */
    updateSafari() {
        const s = this.field.safari;
        // Hors du Parc avec une partie en cours : le joueur est sorti (sortie, Vol...) -> fin de la partie
        if (s && !this.mapProperty('safari')) {
            this.field.safari = null;
            api.post(this.registry.get('config').menu.safariLeave, {});
            toast('Vous quittez le Parc Safari.');
        }
        if (!this.field.safari) {
            this.safariHud?.destroy(true);
            this.safariHud = null;
            return;
        }
        if (!this.safariHud) {
            this.safariHud = this.add.container(4, 4).setScrollFactor(0).setDepth(DEPTH_HUD);
            this.safariHud.add(createWindow(this, 'ui_choice', 204, 80));
            this.safariHud.steps = new PixelText(this, 18, 12, '', { color: '#505058', shadow: '#a0a0a8' });
            this.safariHud.balls = new PixelText(this, 18, 42, '', { color: '#505058', shadow: '#a0a0a8' });
            this.safariHud.add([this.safariHud.steps, this.safariHud.balls]);
        }
        const st = this.field.safari;
        this.safariHud.steps.setText(st.maxSteps > 0 ? `Pas : ${st.steps}/${st.maxSteps}` : 'Pas : illimités');
        this.safariHud.balls.setText(`Balls : ${st.balls}`);
    }

    /** Un pas au Parc : le serveur est prévenu tous les 10 pas. Renvoie true si la partie se termine. */
    countSafariStep() {
        const st = this.field.safari;
        if (!st || st.maxSteps <= 0 || !this.mapProperty('safari')) return false;
        st.steps = Math.max(0, st.steps - 1);
        this.safariPending = (this.safariPending ?? 0) + 1;
        this.updateSafari();
        if (this.safariPending < 10 && st.steps > 0) return false;

        const count = this.safariPending;
        this.safariPending = 0;
        const last = st.steps <= 0;
        if (last) {
            this.cutscene = true;
            this.stand();
        }
        api.post(this.registry.get('config').menu.safariSteps, { steps: count }).then((r) => {
            if (r.ok && r.data.ended === 'steps') this.endSafari(r.data);
            else if (r.ok && r.data.safari) {
                this.field.safari = this.withPendingSteps(r.data.safari);
                this.updateSafari();
            } else if (last) this.cutscene = false;
        });
        return last;
    }

    /** L'état du Safari envoyé par le serveur, moins les pas pas encore envoyés (ils partent par 10) */
    withPendingSteps(safari) {
        if (!safari || safari.maxSteps <= 0) return safari ?? null;
        return { ...safari, steps: Math.max(0, safari.steps - (this.safariPending ?? 0)) };
    }

    /** Fin de la partie (plus de pas, plus de Balls) : message, puis retour devant le gardien */
    endSafari(data) {
        this.safariPending = 0;
        this.cutscene = true;
        this.stand();
        this.field.safari = null;
        this.updateSafari();
        const text = data.ended === 'steps' ? 'HAUT-PARLEUR : Ding-dong ! Le temps est écoulé !\n' : '';
        this.dialog.open(`${text}Votre partie de Safari est terminée.`, '', () => {
            if (data.exit) this.teleportTo(data.exit);
            else this.cutscene = false;
        });
    }

    // ------------------------------------------------------------------
    // Étape 18 : la Pension et les Œufs
    // ------------------------------------------------------------------

    /**
     * Un pas compté pour la Pension et les Œufs de l'équipe. Envoyés au serveur par paquets de 16
     * (ou moins quand un Œuf est sur le point d'éclore), seulement s'il y a quelque chose à faire.
     */
    countBreedingStep() {
        const b = this.field.breeding;
        if (!b?.active) return;
        this.breedingPending = (this.breedingPending ?? 0) + 1;
        // Étape 19 : envoyé plus tôt quand le Repousse est presque fini (le message arrive au bon pas)
        const every = Math.max(1, Math.min(16, b.hatchIn ?? 16, b.repel > 0 ? b.repel : 16));
        if (this.breedingPending >= every && !this.breedingBusy) this.sendBreedingSteps();
    }

    async sendBreedingSteps() {
        const steps = this.breedingPending;
        this.breedingPending = 0;
        this.breedingBusy = true;
        const r = await api.post(this.registry.get('config').menu.steps, { steps });
        this.breedingBusy = false;
        if (!r.ok) return;
        if (this.field) this.field.breeding = r.data.status ?? null;
        // Un Œuf de la Pension est parti au PC : message dans le chat
        // (sans chat, c'est-à-dire sans Reverb : une petite bulle en haut de l'écran)
        for (const egg of r.data.eggs ?? []) {
            const chat = this.registry.get('chat');
            if (chat) chat.add({ name: '', text: egg.message, system: true });
            else toast(egg.message);
        }
        if (r.data.hatched?.length) this.pendingHatches = [...(this.pendingHatches ?? []), ...r.data.hatched];
        // Étape 19 : le Repousse ne fait plus effet
        if (r.data.repelEnded) this.pendingRepelEnd = { next: r.data.repelNext ?? null };
    }

    /**
     * Étape 20 : les objets du Casino (propriété Tiled "action" d'un PNJ ou d'un panneau). Renvoie true si c'en est un.
     *   coins : guichet des jetons    prizes : comptoir des lots (prizes = pokemon / tms / items)
     *   slots : machine à sous (difficulty = 0, 1, 2)    roulette : table (wager = 1 ou 3, color = red / green / blue)
     */
    casinoAction(action, props = {}) {
        const map = this.mapKey;
        const tasks = {
            coins: () => ({ type: 'coins', map }),
            prizes: () => ({ type: 'prizes', map, counter: props.prizes ?? 'pokemon' }),
            slots: () => ({ type: 'slots', map, difficulty: Number(props.difficulty ?? 1) }),
            roulette: () => ({ type: 'roulette', map, wager: Number(props.wager ?? 1), color: props.color ?? 'red' }),
        };
        if (!tasks[action]) return false;
        this.openOverlay(tasks[action]());
        return true;
    }

    /** Étape 19 : "L'effet du Repousse a disparu..." et, s'il en reste dans le Sac, en utiliser un autre ? */
    async repelEnded({ next }) {
        this.cutscene = true;
        this.stand();
        await new Promise((resolve) => this.dialog.open('L\'effet du Repousse a disparu...', '', resolve));
        if (next && (await this.askYesNo(`Utiliser un autre ${next.name} ?`))) {
            const r = await api.post(this.registry.get('config').menu.fieldItem, { item_id: next.id, map: this.mapKey });
            if (r.ok) {
                this.audio?.se('repel');
                this.field.breeding = r.data.status ?? this.field.breeding;
            }
            this.cutscene = true;
            await new Promise((resolve) => this.dialog.open(r.data.message ?? 'Impossible.', '', resolve));
        }
        this.cutscene = false;
    }

    /** Étape 19 : la Corde Sortie (depuis le Sac) : le joueur tourne sur lui-même et ressort à l'entrée de la grotte */
    async useEscapeRope(itemId) {
        this.cutscene = true;
        this.stand();
        const r = await api.post(this.registry.get('config').menu.fieldItem, { item_id: itemId, map: this.mapKey });
        if (!r.ok || !r.data.to) {
            this.dialog.open(r.data?.message ?? 'Ce n\'est pas le moment d\'utiliser ça !', '', () => (this.cutscene = false));
            return;
        }
        await new Promise((resolve) => this.dialog.open(r.data.message, '', resolve));
        this.audio?.se('escape_rope');
        const order = ['down', 'left', 'up', 'right'];
        for (let i = 0; i < 12; i++) {
            this.facing = order[i % 4];
            this.stand();
            await this.delay(70);
        }
        this.setCycling?.(false, { quiet: true });
        this.teleportTo(r.data.to);
    }

    /** Le gardien de la Pension (dehors) : des nouvelles des Pokémon confiés et de leur entente */
    async daycareMan(npc) {
        const name = npc.name ?? '';
        this.cutscene = true;
        const r = await api.get(this.registry.get('config').menu.daycare);
        const slots = r.ok ? r.data.slots : [];
        const text = slots.length === 0
            ? 'Je suis le gardien de la Pension.\nVous pouvez confier vos Pokémon à ma femme, à l\'intérieur.'
            : slots.length === 1
                ? `Ah, c'est vous ! Votre ${slots[0].name} va très bien.\nAvec un autre Pokémon, il pourrait avoir un Œuf...`
                : `Ah, c'est vous ! Vos ${slots[0].name} et ${slots[1].name} vont bien.\n${r.data.compatibilityText ?? ''}`;
        this.dialog.open(text, name, () => (this.cutscene = false));
    }

    /** Étape 14 : monter sur la Bicyclette ou en descendre (touche B, ou Sac > Bicyclette > Utiliser) */
    toggleBike({ fromBag = false } = {}) {
        // Touche B sans Bicyclette : rien. Depuis le Sac, le joueur l'a forcément.
        if (!fromBag && !this.field.bike && !this.cycling) return;
        if (!this.cycling && (this.surfing || this.mapProperty('no_bike'))) {
            this.audio?.se('buzzer');
            this.dialog.open('Ce n\'est pas le moment d\'utiliser ça !');
            return;
        }
        this.setCycling(!this.cycling);
    }

    setCycling(on, { quiet = false } = {}) {
        this.cycling = on;
        this.registry.set('cycling', on);
        if (!this.player) return;
        this.stand();
        if (quiet) return;
        this.playMapMusic();
        this.multi?.sendPosition({ x: this.tile.x, y: this.tile.y, facing: this.facing, mode: this.moveMode(), instant: true, follower: this.followerPosition() });
    }

    /**
     * Étape 14 : un PNJ qui offre un objet (propriétés Tiled action = gift, item, quantity, gift, text, after).
     * Il dit son texte, puis le serveur donne l'objet (une seule fois par personnage).
     */
    async receiveGift(npc) {
        this.cutscene = true;
        const props = npc.props ?? {};
        const name = npc.name ?? '';
        const finish = () => (this.cutscene = false);
        // On demande d'abord au serveur : déjà reçu -> seulement la phrase "après"
        const url = this.registry.get('config').menu.gift;
        const data = await this.postJson(url, { map: this.mapKey, gift: String(props.gift ?? props.item ?? '') });
        if (!data?.given) {
            if (data?.message) console.warn(`Cadeau du PNJ ${name} : ${data.message}`);
            this.dialog.open(props.after ?? props.text ?? '...', name, finish);
            return;
        }
        if (data.bike) this.field.bike = true;
        const give = async () => {
            await this.audio?.me(data.pocket ? 'key_item' : 'item');
            const pocket = data.pocket ? `\nVous rangez l'objet dans la poche ${data.pocket}.` : '';
            this.dialog.open(`${data.message}${pocket}`, '', () => {
                if (props.after) this.dialog.open(props.after, name, finish);
                else finish();
            });
        };
        if (props.text) this.dialog.open(props.text, name, give);
        else give();
    }

    // ------------------------------------------------------------------
    // Étape 12 : musique et sons
    // ------------------------------------------------------------------

    /** La musique de la carte (propriété Tiled "bgm"), ou celle du Surf (ou du vélo) */
    playMapMusic() {
        if (!this.audio) return;
        if (this.surfing && this.audio.events.bgm?.surf) return this.audio.bgm('surf');
        if (this.cycling && this.audio.events.bgm?.bicycle) return this.audio.bgm('bicycle'); // étape 14
        this.audio.mapBgm(this.mapProperty('bgm'));
    }

    /** Le joueur se cogne : un petit "bonk" (pas plus de 2 par seconde quand on reste contre un mur) */
    bump() {
        const now = this.time.now;
        if (now - (this.lastBump ?? 0) < 450) return;
        this.lastBump = now;
        this.audio?.se('bump');
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    isInsideMap(x, y) {
        return x >= 0 && y >= 0 && x < this.map.width && y < this.map.height;
    }

    /**
     * Une case est bloquée si elle est hors de la carte, ou si UNE des tuiles posées dessus
     * (calques "ground" et "decor") a la propriété Tiled "collides" cochée.
     */
    isBlocked(x, y) {
        const at = this.layersAt(x, y);
        if (!at) return true;   // hors de la carte (et d'aucune carte collée)
        // Étape 16 : un rebord bloque (on ne le franchit qu'en sautant, voir move())
        if (this.ledgeAt(x, y)) return true;
        // Étape 25 : un PNJ ou une Poké Ball d'une carte collée
        if (at.neighbor && this.neighbors.blocks(x, y)) return true;

        // La tuile la plus haute décide (un pont posé sur l'eau se traverse), comme dans RPG Maker XP
        return TilesetData.blocked(at.layers, at.x, at.y);
    }

    /**
     * Étape 25 : les calques solides (sol, décor) qui couvrent la case (x, y) : ceux de la carte, ou ceux d'une
     * carte collée (avec la case traduite dans SES coordonnées). null : il n'y a rien ici.
     */
    layersAt(x, y) {
        if (this.isInsideMap(x, y)) return { layers: this.solidLayers, x, y, neighbor: null };
        const n = this.neighbors?.at(x, y);
        return n ? { layers: n.solidLayers, x: x - n.dx, y: y - n.dy, neighbor: n } : null;
    }

    /** Étape 25 : on vient de marcher sur la case (x, y) d'une carte collée : la scène passe sur cette carte, sans fondu */
    crossTo(target) {
        const n = this.neighbors.at(target.x, target.y);
        if (!n) return;
        this.isMoving = true;   // plus rien ne bouge jusqu'au redémarrage
        this.saver.attach(null);
        this.scene.restart({
            map: n.key, x: target.x - n.dx, y: target.y - n.dy, facing: this.facing,
            warped: true, seamless: true, from: null,
        });
    }

    /** Étape 25 : le nom du lieu en haut à gauche, quand on entre dans une autre zone (comme dans Émeraude) */
    showLocationName() {
        const name = this.mapProperty('displayName');
        const inWorld = Boolean(this.registry.get('config').world?.maps?.[this.mapKey]);
        const wanted = this.mapProperty('banner') ?? inWorld;   // propriété de carte "banner" (sinon : les cartes d'un monde)
        // Une maison, un Centre... (sans panneau) ne comptent pas : en ressortant, on est toujours dans la même ville
        if (!name || !wanted) return;
        const previous = this.registry.get('lastLocation');
        this.registry.set('lastLocation', name);
        if (previous !== name) showLocation(this, name);
    }

    /**
     * Étape 16 : un REBORD sur cette case ? Renvoie le sens dans lequel on peut le sauter
     * ('down', 'up', 'left', 'right'), ou null. Propriété de tuile Tiled "ledge" (texte).
     */
    ledgeAt(x, y) {
        const at = this.layersAt(x, y);   // étape 25 : aussi sur une carte collée
        if (!at) return null;
        for (const layer of at.layers) {
            const ledge = layer.getTileAt(at.x, at.y)?.properties?.ledge;
            if (typeof ledge === 'string' && DIRECTIONS[ledge]) return ledge;
        }
        return null;
    }

    /** Peut-on atterrir sur cette case après un saut ? */
    canLand(x, y) {
        return !this.isBlocked(x, y) && !this.npcAt(x, y) && !this.fieldObjects.at(x, y) && !this.itemBalls.blocks(x, y) && !this.berryBushes?.at(x, y) && !this.isSurfTile(x, y);
    }

    /** La tuile d'un des calques solides porte-t-elle cette propriété ? (ex. "encounter") */
    hasTileProperty(x, y, name) {
        const at = this.layersAt(x, y);   // étape 25 : aussi sur une carte collée
        return Boolean(at?.layers.some((layer) => layer.getTileAt(at.x, at.y)?.properties?.[name] === true));
    }

    // Case -> pixels : milieu de la case en X, bas de la case en Y (là où sont les pieds)
    pixelX(tileX) {
        return tileX * TILE + TILE / 2;
    }

    pixelY(tileY) {
        return tileY * TILE + TILE;
    }

    // Frame immobile de la direction actuelle : colonne 0 de la bonne ligne
    idleFrame() {
        return DIRECTIONS[this.facing].row * 4;
    }
}
