import { colorLayer } from '../objects/DayNight.js';
import { CommandMenu, DataBox, FightMenu, MessageBox, ChoiceWindow, InfoWindow, SafariBox, SAFARI_LAYOUT, tweenAsync, wait } from './BattleUI.js';
import PartyScreen from './PartyScreen.js';
import BagScreen from '../menu/BagScreen.js';
import SummaryScreen from '../menu/SummaryScreen.js';
import NamingScreen from '../menu/NamingScreen.js';
import { api } from '../menu/Api.js';
import { attachControls } from '../ui/Controls.js';
import { ballKey, bottomPadding, loadImages } from './BattleAssets.js';
import { blend, tint, tintFill, clearTint } from '../ui/Tint.js';

/**
 * L'ÉCRAN DE COMBAT.
 *
 * Il ne calcule RIEN : c'est Laravel (app/Battle/BattleEngine.php) qui décide de tout.
 * Cette scène :
 *   1. affiche le combat (fond, Pokémon, boîtes de PV) ;
 *   2. demande au joueur son action (menus) et l'envoie au serveur ;
 *   3. "joue" les événements renvoyés : messages, barres de PV, K.O., Ball, XP...
 *
 * Démarrée par WorldScene :  this.scene.launch('Battle', { battle, events, onEnd })
 */

// Positions d'Essentials (Battle::Scene) : centre du socle du joueur et de l'adversaire
const PLAYER_BASE = { x: 128, y: 384 - 80 };            // 128, 304 (bas du socle)
const FOE_BASE = { x: 512 - 128, y: 384 * 3 / 4 - 112 }; // 384, 176 (centre du socle)

// Couleur des "flashs" d'attaque, selon le type
const TYPE_COLORS = {
    normal: 0xa8a878, fire: 0xf08030, water: 0x6890f0, electric: 0xf8d030, grass: 0x78c850, ice: 0x98d8d8,
    fighting: 0xc03028, poison: 0xa040a0, ground: 0xe0c068, flying: 0xa890f0, psychic: 0xf85888, bug: 0xa8b820,
    rock: 0xb8a038, ghost: 0x705898, dragon: 0x7038f8, dark: 0x705848, steel: 0xb8b8d0, fairy: 0xee99ac,
};

const STAT_LABELS = { hp: 'PV Max.', attack: 'Attaque', defense: 'Défense', sp_attack: 'Atq. Spé.', sp_defense: 'Déf. Spé.', speed: 'Vitesse' };

export default class BattleScene extends Phaser.Scene {
    constructor() {
        super('Battle');
    }

    init(data) {
        this.view = data.battle;           // état du combat, version "écran" (envoyé par Laravel)
        this.introEvents = data.events ?? [];
        this.onEnd = data.onEnd;           // appelé à la fin, avec le résultat
        this.config = this.registry.get('config');
        this.focus = null;                 // élément d'interface qui reçoit les touches
        this.busy = false;
        // Combat entre joueurs (étape 9) : les deux joueurs choisissent, le serveur joue le tour
        this.pvp = this.view.type === 'pvp';
        this.multi = this.registry.get('multi');
    }

    /** Dresseur PNJ ou autre joueur : sprite du dresseur et Balls de l'équipe au début */
    get hasTrainer() {
        return this.view.type === 'trainer' || this.view.type === 'pvp';
    }

    /** Étape 15 : Parc Safari (pas de Pokémon envoyé : Safari Ball, Appât, Caillou, Fuite) */
    get safari() {
        return this.view.type === 'safari';
    }

    preload() {
        const v = this.view;
        const base = this.config.basePath;
        const bg = `${base}/battle/backgrounds`;

        this.load.image(`bb_${v.background}_bg`, `${bg}/${v.background}_bg.png`);
        this.load.image(`bb_${v.base}_base0`, `${bg}/${v.base}_base0.png`);
        this.load.image(`bb_${v.base}_base1`, `${bg}/${v.base}_base1.png`);
        this.load.image(`bb_${v.background}_message`, `${bg}/${v.background}_message.png`);

        // Sprites de dos et icônes de toute l'équipe ; sprite de face de l'adversaire
        for (const p of v.player.party) {
            if (p.sprites.back) this.load.image(spriteKey(p.sprites.back), p.sprites.back);
            if (p.sprites.icon) this.load.image(`pkmn_icon_${p.sprites.icon}`, p.sprites.icon);
        }
        if (v.foe.active.sprites.front) this.load.image(spriteKey(v.foe.active.sprites.front), v.foe.active.sprites.front);
        if (v.type !== 'wild' && v.foe.sprite) {
            this.load.image(`trainer_battle_${v.foe.sprite}`, `${base}/sprites/trainers/battle/${v.foe.sprite}.png`);
        }
    }

    create() {
        const v = this.view;
        this.gender = this.config.character.avatar ?? this.config.character.gender;   // étape 17 : le personnage (red, leaf...) pour le sprite de dos

        // ---- décor ----
        this.add.image(0, 0, `bb_${v.background}_bg`).setOrigin(0, 0);
        this.foeSide = this.add.container(0, 0);
        this.playerSide = this.add.container(0, 0);
        this.playerBase = this.add.image(PLAYER_BASE.x, PLAYER_BASE.y, `bb_${v.base}_base0`).setOrigin(0.5, 1);
        this.foeBase = this.add.image(FOE_BASE.x, FOE_BASE.y, `bb_${v.base}_base1`).setOrigin(0.5, 0.5);
        this.playerSide.add(this.playerBase);
        this.foeSide.add(this.foeBase);
        // Bande du bas (sous la boîte de message) : ajoutée APRÈS les socles, elle passe devant eux.
        // Le bas du dresseur, de son socle et du Pokémon de dos est donc caché, comme dans Essentials.
        this.add.image(0, 288, `bb_${v.background}_message`).setOrigin(0, 0);

        // ---- interface ----
        this.foeBox = new DataBox(this, 'foe').setVisible(false);
        this.playerBox = new DataBox(this, 'player').setVisible(false);
        this.message = new MessageBox(this);
        this.commandMenu = new CommandMenu(this);
        this.fightMenu = new FightMenu(this);
        this.safariBox = new SafariBox(this);   // étape 15

        this.setupKeys();
        this.cameras.main.fadeIn(300, 0, 0, 0);
        this.setWeather(v.weather ?? null);   // étape 23 : météo de la carte (pluie, soleil...)

        if (this.introEvents.length > 0) {
            this.runIntro();
        } else {
            this.resume();
        }
    }

    // =====================================================================
    // Étape 23 : la météo (un voile coloré et, pour la pluie, la grêle et le sable, des particules qui défilent)
    // =====================================================================

    setWeather(type) {
        this.weatherLayer?.destroy(true);
        this.weatherLayer = null;
        if (!type) return;
        const looks = {
            sun: { color: 0x403010, alpha: 1, blend: Phaser.BlendModes.ADD },
            rain: { color: 0x203060, alpha: 0.25, blend: Phaser.BlendModes.NORMAL, drops: [0xd0e0ff, 2, 9, 6, 14] },
            sand: { color: 0xa07838, alpha: 0.25, blend: Phaser.BlendModes.NORMAL, drops: [0xe8c890, 2, 2, 10, 2] },
            hail: { color: 0x202838, alpha: 1, blend: Phaser.BlendModes.ADD, drops: [0xffffff, 3, 3, 3, 7] },
        }[type];
        if (!looks) return;
        const layer = this.add.container(0, 0).setDepth(5);
        layer.add(colorLayer(this, `weather_tint_${type}`, 512, 288, looks.blend).paint(looks.color, looks.alpha));
        if (looks.drops) {
            const [color, w, h, dx, dy] = looks.drops;
            const key = `weather_${type}`;
            if (!this.textures.exists(key)) {
                const g = this.make.graphics({ x: 0, y: 0, add: false });
                g.fillStyle(color, 0.8);
                for (let i = 0; i < 10; i++) g.fillRect((i * 37) % 64, (i * 23) % 64, w, h);
                g.generateTexture(key, 64, 64);
                g.destroy();
            }
            const drops = this.add.tileSprite(0, 0, 512, 288, key).setOrigin(0, 0);
            layer.add(drops);
            this.weatherDrops = { sprite: drops, dx, dy };
        } else {
            this.weatherDrops = null;
        }
        this.weatherLayer = layer;
    }

    update() {
        if (this.weatherDrops?.sprite.active) {
            this.weatherDrops.sprite.tilePositionX -= this.weatherDrops.dx;
            this.weatherDrops.sprite.tilePositionY -= this.weatherDrops.dy;
        }
    }

    // =====================================================================
    // Clavier : une seule écoute, transmise à l'élément actif (menu, message...)
    // =====================================================================

    setupKeys() {
        // Clavier (AZERTY : on lit la lettre écrite sur la touche) et souris : voir ui/Controls.js
        attachControls(this);
    }

    // =====================================================================
    // Début du combat
    // =====================================================================

    async runIntro() {
        const v = this.view;
        const foe = v.foe.active;

        // Sprites de départ : l'adversaire (ou le dresseur) et le dresseur joueur, de dos
        if (this.hasTrainer) {
            this.foeTrainer = this.add.image(FOE_BASE.x, FOE_BASE.y + 6, `trainer_battle_${v.foe.sprite}`).setOrigin(0.5, 1);
            this.foeSide.add(this.foeTrainer);
        } else {
            this.foeSprite = this.createFoeSprite(foe);
        }
        this.playerTrainer = this.add.image(PLAYER_BASE.x, PLAYER_BASE.y - 16, `player_back_${this.gender}`, 0).setOrigin(0.5, 1);
        this.playerSide.add(this.playerTrainer);

        // Les deux camps glissent en place (l'adversaire depuis la gauche, le joueur depuis la droite)
        this.foeSide.x = -512;
        this.playerSide.x = 512;
        await Promise.all([
            tweenAsync(this, { targets: this.foeSide, x: 0, duration: 800, ease: 'Sine.easeOut' }),
            tweenAsync(this, { targets: this.playerSide, x: 0, duration: 800, ease: 'Sine.easeOut' }),
        ]);

        if (this.hasTrainer) {
            this.showLineups();
        }

        await this.playEvents(this.introEvents);
        this.hideLineups();
        // Étape 15 : au Parc Safari, le dresseur reste là, avec ses Safari Balls
        if (this.safari) await this.safariBox.setBalls(v.safari?.balls ?? 0).slideIn();
        await this.nextPhase();
    }

    /** Reprise d'un combat en cours (page rechargée) : tout est affiché directement */
    async resume() {
        const v = this.view;
        this.foeSprite = this.createFoeSprite(v.foe.active);
        this.foeBox.setBattler(v.foe.active);
        this.foeBox.setVisible(true);
        if (this.safari) {
            // Étape 15 : Parc Safari, le dresseur de dos et ses Safari Balls
            this.playerTrainer = this.add.image(PLAYER_BASE.x, PLAYER_BASE.y - 16, `player_back_${this.gender}`, 0).setOrigin(0.5, 1);
            this.playerSide.add(this.playerTrainer);
            this.safariBox.setBalls(v.safari?.balls ?? 0).setVisible(true);
            await this.nextPhase();
            return;
        }
        const p = v.player.party[v.player.active];
        this.playerSprite = this.createPlayerSprite(p);
        this.playerBox.setBattler(p);
        this.playerBox.setVisible(true);
        await this.nextPhase();
    }

    createFoeSprite(b) {
        const key = spriteKey(b.sprites.front);
        // Pieds posés au centre du socle : on retire la marge vide sous le Pokémon dans l'image
        const padding = this.textures.exists(key) ? bottomPadding(this, key) : 0;
        const sprite = this.add.image(FOE_BASE.x, FOE_BASE.y + 8 + padding, key).setOrigin(0.5, 1);
        sprite.homeY = sprite.y;
        this.foeSide.add(sprite);
        return sprite;
    }

    createPlayerSprite(b) {
        const sprite = this.add.image(PLAYER_BASE.x, PLAYER_BASE.y, spriteKey(b.sprites.back)).setOrigin(0.5, 1);
        sprite.homeY = sprite.y;
        this.playerSide.add(sprite);
        return sprite;
    }

    /**
     * Les Balls des deux équipes (début d'un combat de dresseur), aux positions d'Essentials
     * (Animation::LineupAppear) : une barre de 248 pixels visibles et 6 Balls espacées de 32 pixels.
     *   Joueur     : barre en (512 - 248, 384 - 142), Balls de gauche à droite à partir de x + 44
     *   Adversaire : barre qui finit en x = 248, y = 114, Balls de droite à gauche
     */
    showLineups() {
        const v = this.view;
        const BAR = 248;
        this.lineups = this.add.container(0, 0).setDepth(5);

        const ballKey = (state) => (state === undefined ? 'battle_icon_ball_empty'
            : state === 'faint' ? 'battle_icon_ball_faint'
                : state === 'status' ? 'battle_icon_ball_status' : 'battle_icon_ball');

        // Joueur : la barre part de x = 264 vers la droite (son bout arrondi à gauche : image retournée)
        const playerStates = v.player.party.map((p) => (p.hp <= 0 ? 'faint' : p.status !== 'none' ? 'status' : 'ok'));
        const py = 384 - 142;
        this.lineups.add(this.add.image(512 - BAR, py, 'battle_lineup').setOrigin(0, 0).setFlipX(true));
        for (let i = 0; i < 6; i++) {
            this.lineups.add(this.add.image(512 - BAR + 44 + 32 * i, py - 30, ballKey(playerStates[i])).setOrigin(0, 0));
        }

        // Adversaire : la barre finit en x = 248 ; 1re Ball à droite, les suivantes vers la gauche
        const fy = 114;
        this.lineups.add(this.add.image(BAR, fy, 'battle_lineup').setOrigin(1, 0));
        for (let i = 0; i < 6; i++) {
            this.lineups.add(this.add.image(BAR - 44 - 30 - 32 * i, fy - 30, ballKey(v.foe.lineup[i])).setOrigin(0, 0));
        }
    }

    hideLineups() {
        this.lineups?.destroy();
        this.lineups = null;
    }

    // =====================================================================
    // Après les événements : que fait-on ?
    // =====================================================================

    async nextPhase() {
        const v = this.view;
        // PvP : j'ai déjà choisi (ou c'est à l'autre d'envoyer un Pokémon) -> j'attends
        if (this.pvp && v.phase !== 'ended' && (v.pvp?.waiting || (v.phase === 'switch' && !v.mustSwitch))) {
            return this.pvpWait();
        }
        switch (v.phase) {
            case 'command':
                if (this.safari) return this.chooseSafari();
                if (v.locked) {
                    // Attaque imposée (2e tour de Lance-Soleil, repos après Ultralaser...) : pas de menu
                    return this.send({ type: 'continue' });
                }
                return this.chooseCommand();
            case 'switch':
                return this.forcedSwitch();
            case 'learn_move':
                return this.learnMove();
            case 'ended':
                return this.endBattle();
        }
    }

    async chooseCommand() {
        const active = this.view.player.party[this.view.player.active];
        this.message.setVisible(false);
        this.focus = this.commandMenu;
        const command = await this.commandMenu.open(active.name);
        this.focus = null;

        switch (command) {
            case 'fight': return this.chooseMove();
            case 'bag':
                if (this.pvp) {
                    await this.flash('Les objets sont interdits dans les combats entre joueurs !');
                    return this.chooseCommand();
                }
                return this.chooseItem();
            case 'pokemon': return this.chooseSwitch();
            case 'run':
                if (this.pvp) return this.forfeit();
                return this.send({ type: 'run' });
        }
    }

    /** Étape 15 : le menu du Parc Safari */
    async chooseSafari() {
        this.message.setVisible(false);
        this.focus = this.commandMenu;
        const command = await this.commandMenu.open(null, this.commandMenu.index, {
            layout: SAFARI_LAYOUT, prompt: `Que doit lancer\n${this.view.player.name} ?`,
        });
        this.focus = null;
        const actions = { ball: 'safari_ball', bait: 'bait', rock: 'rock', run: 'run' };
        return this.send({ type: actions[command] });
    }

    async chooseMove() {
        const active = this.view.player.party[this.view.player.active];

        // Plus aucun PP : Lutte directement
        if (active.moves.every((m) => m.pp <= 0)) {
            return this.send({ type: 'move', slot: -1 });
        }

        this.focus = this.fightMenu;
        const slot = await this.fightMenu.open(active, this.lastMove ?? 0);
        this.focus = null;
        if (slot === null) return this.chooseCommand();

        if (active.moves[slot].pp <= 0) {
            this.message.setVisible(true);
            await this.message.show('Il n\'y a plus de PP pour cette capacité !');
            return this.chooseMove();
        }
        this.lastMove = slot;
        return this.send({ type: 'move', slot });
    }

    async chooseSwitch() {
        const index = await this.openParty({ prompt: 'Choisissez un Pokémon.' });
        if (index === null) return this.chooseCommand();
        return this.send({ type: 'switch', party: index });
    }

    async forcedSwitch() {
        const index = await this.openParty({ prompt: 'Choisissez un Pokémon.', canCancel: false });
        return this.send({ type: 'switch', party: index });
    }

    /** Ouvre l'écran d'équipe ; refuse localement les choix impossibles (K.O., déjà au combat) */
    async openParty(options) {
        const v = this.view;
        await loadImages(this, Object.fromEntries(v.player.party.map((p) => [`pkmn_icon_${p.sprites.icon}`, p.sprites.icon])));

        while (true) {
            const commands = options.mode === 'item' ? undefined : ['Envoyer', 'Résumé', 'Annuler'];
            const screen = new PartyScreen(this, v.player.party, { ...options, commands });
            this.focus = screen;
            const result = await screen.open();
            this.focus = null;
            if (result === null || result === undefined) return null;
            const index = typeof result === 'object' ? result.index : result;

            if (result.command === 'Résumé') {
                await this.showSummary(index);
                continue;
            }

            const p = v.player.party[index];
            if (options.mode !== 'item') {
                if (p.hp <= 0) {
                    await this.flash(`${p.name} n'a plus la force de se battre !`);
                    continue;
                }
                if (index === v.player.active && v.phase !== 'switch') {
                    await this.flash(`${p.name} est déjà au combat !`);
                    continue;
                }
            }
            return index;
        }
    }

    /** Le Sac d'Essentials : seuls les objets utilisables en combat (soins, Balls) peuvent être choisis */
    async chooseItem() {
        const screen = new BagScreen(this, this.view.bag, { mode: 'battle', basePath: this.config.basePath });
        this.focus = screen;
        const item = await screen.open();
        this.focus = null;
        if (!item) return this.chooseCommand();

        if (item.needsPokemon) {
            const target = await this.openParty({ prompt: 'Utiliser sur quel Pokémon ?', mode: 'item' });
            if (target === null) return this.chooseItem();
            // Étape 22 : Huile, sur quelle capacité ?
            if (item.needsMove) {
                const p = this.view.player.party[target];
                const move = await this.chooseMoveFor(p);
                if (move === null) return this.chooseItem();
                return this.send({ type: 'item', item_id: item.id, target, move });
            }
            return this.send({ type: 'item', item_id: item.id, target });
        }
        return this.send({ type: 'item', item_id: item.id });
    }

    /** Étape 22 : choisir une capacité d'un Pokémon (Huile) ; renvoie son index (0 à 3) ou null */
    async chooseMoveFor(p) {
        this.message.setVisible(true);
        await this.message.show('Restaurer les PP de quelle capacité ?', { keep: true });
        const items = p.moves.map((m) => ({ label: m.name, right: `${m.pp}/${m.maxPp}` }));
        const list = new ChoiceWindow(this, items, { x: 512 - 280, y: 0, width: 280, cancelValue: -1 }).setDepth(900);
        this.focus = list;
        const index = await list.promise;
        this.focus = null;
        return index === null || index === undefined || index < 0 ? null : index;
    }

    /**
     * Résumé d'un Pokémon pendant le combat : la fiche complète vient du serveur (nature, dresseur...),
     * mais PV, statut, niveau et PP sont ceux du combat en cours.
     */
    async showSummary(index) {
        const r = await api.get(this.config.menu.party);
        const details = r.data.party ?? [];
        const list = this.view.player.party.map((b) => {
            const d = details.find((p) => p.id === b.id);
            if (!d) return null;
            return {
                ...d, hp: b.hp, maxHp: b.maxHp, status: b.status, level: b.level, stats: b.stats,
                exp: b.exp, levelExp: b.levelExp, nextLevelExp: b.nextLevelExp,
                moves: d.moves.map((m) => ({ ...m, pp: b.moves.find((bm) => bm.name === m.name)?.pp ?? m.pp })),
            };
        }).filter(Boolean);
        const target = list.findIndex((p) => p.id === this.view.player.party[index].id);
        const summary = new SummaryScreen(this, list, Math.max(0, target));
        this.focus = summary;
        await summary.open();
        this.focus = null;
    }

    /** "Bulbizarre veut apprendre Tranch'Herbe..." */
    async learnMove() {
        const { index, move } = this.view.learn;
        const p = this.view.player.party[index];
        this.message.setVisible(true);
        await this.message.show(`${p.name} veut apprendre ${move.name}.`, { pause: true });
        await this.message.show(`Mais ${p.name} connaît déjà quatre capacités.`, { pause: true });
        await this.message.show(`Oublier une capacité pour apprendre ${move.name} ?`, { keep: true });

        if (await this.yesNo()) {
            await this.message.show(`Quelle capacité doit être oubliée ?`, { keep: true });
            const items = [...p.moves.map((m) => ({ label: m.name, right: `${m.pp}/${m.maxPp}` })), { label: move.name, right: 'NOUV.' }];
            const list = new ChoiceWindow(this, items, { x: 512 - 280, y: 0, width: 280, cancelValue: 4 }).setDepth(900);
            this.focus = list;
            const forget = await list.promise;
            this.focus = null;
            // Étape 19 : une CS ne s'oublie que chez le Maître des capacités
            if (forget !== null && forget < 4 && p.moves[forget]?.hm) {
                await this.message.show('Une CS ne peut pas être oubliée ainsi.\nLe Maître des capacités peut la faire oublier.', { pause: true });
                return this.learnMove();
            }
            if (forget !== null && forget < 4) {
                return this.send({ type: 'learn', forget });
            }
        }
        await this.message.show(`Abandonner l'apprentissage de ${move.name} ?`, { keep: true });
        if (await this.yesNo()) {
            return this.send({ type: 'learn', forget: null });
        }
        return this.learnMove();
    }

    async yesNo() {
        const win = new ChoiceWindow(this, [{ label: 'Oui' }, { label: 'Non' }], { x: 512 - 132, y: 288 - 96, width: 132, cancelValue: 1 }).setDepth(900);
        this.focus = win;
        const choice = await win.promise;
        this.focus = null;
        return choice === 0;
    }

    /** Étape 19 : "Voulez-vous donner un surnom à Rattata ?" juste après la capture */
    async nicknameCaught(caught) {
        this.message.setVisible(true);
        await this.message.show(`Voulez-vous donner un surnom à ${caught.name} ?`, { keep: true });
        if (!(await this.yesNo())) return;
        const name = await new NamingScreen(this, { speciesName: caught.name, nickname: '', sprites: { icon: caught.icon ?? null } }).open();
        if (!name) return;
        const r = await api.post(this.config.menu.nickname, { pokemon_id: caught.id, nickname: name });
        if (!r.ok) await this.flash(r.data.message ?? 'Impossible de changer son nom.');
    }

    /** Un message rapide (refus local) */
    async flash(text) {
        this.message.setVisible(true);
        await this.message.show(text);
        this.message.setVisible(false);
    }

    // =====================================================================
    // Échange avec le serveur
    // =====================================================================

    async send(action) {
        if (this.pvp) return this.sendPvp(action);
        this.busy = true;
        const url = this.config.battle.actionUrl.replace('BATTLE_ID', this.view.id);
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(action),
            });
        } catch (error) {
            this.busy = false;
            await this.flash('Connexion perdue... Nouvel essai.');
            return this.nextPhase();
        }

        const data = await response.json().catch(() => ({}));
        this.busy = false;

        if (response.status === 419 || response.status === 401) {
            document.getElementById('session-expired').hidden = false;
            return;
        }
        if (!response.ok) {
            // Action refusée par le serveur (plus de PP, objet sans effet...) : le tour n'est pas joué
            await this.flash(data.message ?? 'Action impossible.');
            return this.nextPhase();
        }

        const previous = this.view;
        this.view = data.battle;
        this.previousView = previous;
        await this.playEvents(data.events);
        // Remise à niveau finale avec l'état officiel (au cas où une animation aurait raté une valeur)
        this.syncWithView();
        return this.nextPhase();
    }

    syncWithView() {
        const v = this.view;
        const p = v.player.party[v.player.active];
        if (p && this.playerBox.visible) this.playerBox.setBattler(p);
        if (this.foeBox.visible && v.result === null) this.foeBox.setBattler(v.foe.active);
    }

    // =====================================================================
    // Les événements, un par un
    // =====================================================================

    async playEvents(events) {
        this.message.setVisible(true);
        for (const event of events) {
            await this.playEvent(event);
        }
    }

    async playEvent(e) {
        switch (e.type) {
            case 'message':
                await this.message.show(e.text, { pause: !!e.pause });
                // Après "X monte au N. 6 !" : la fenêtre des stats, comme Essentials
                if (this.pendingStats && e.text.includes(' monte au N. ')) {
                    const stats = this.pendingStats;
                    this.pendingStats = null;
                    await this.showLevelStats(stats);
                }
                return;
            case 'send_out':
                this.hideLineups(); // les Balls des équipes disparaissent dès le premier envoi
                this.playCry(e.battler?.sprites?.cry);
                return e.side === 'player' ? this.sendOutPlayer(e) : this.sendOutFoe(e);
            case 'recall':
                this.sfx('recall');
                return this.recall(e.side);
            case 'move':
                return this.moveAnimation(e);
            case 'hp':
                // Étape 12 : le son du coup dépend de l'efficacité
                if (e.cause === 'damage') this.sfx(e.effectiveness > 1 ? 'hit_super' : e.effectiveness > 0 && e.effectiveness < 1 ? 'hit_weak' : 'hit');
                return this.hpChange(e);
            case 'faint':
                this.sfx('faint');
                return this.faint(e.side);
            case 'status':
                (e.side === 'player' ? this.playerBox : this.foeBox).setStatus(e.status);
                return;
            case 'status_anim':
                return this.tintPulse(this.spriteOf(e.side), { sleep: 0x9090ff, poison: 0xc060c0, burn: 0xf08030, paralysis: 0xf8d030, freeze: 0x98d8f8, confusion: 0xf0a0f0 }[e.status] ?? 0xffffff);
            case 'stat':
                return this.tintPulse(this.spriteOf(e.side), e.change > 0 ? 0xff7070 : 0x7070ff, 3);
            case 'weather':   // étape 23
                this.setWeather(e.weather);
                return;
            case 'exp':
                return this.expGain(e);
            case 'level_up':
                this.registry.get('audio')?.me('level_up');   // petit jingle, la musique du combat reprend
                return this.levelUp(e);
            case 'learned':
                return;
            case 'ball':
                return this.throwBall(e);
            // Étape 15 : Parc Safari
            case 'safari':
                this.safariBox.setBalls(e.balls);
                return;
            case 'safari_throw':
                return this.safariThrow(e.what);
            case 'foe_flee':
                return this.foeFlee();
            case 'flee':
                this.sfx('flee');
                return;
            case 'trainer_return':
                return this.trainerReturn();
            case 'party_hp':
                return;
            case 'end':
                return this.endMusic(e.result);
        }
    }

    /** Cri du Pokémon (si le fichier existe ; le navigateur peut refuser le son avant un premier clic/touche) */
    playCry(url) {
        this.registry.get('audio')?.cry(url);   // étape 12 : au volume des effets (Options)
    }

    /** Étape 12 : un effet sonore (config/audio.php, section se) */
    sfx(name) {
        this.registry.get('audio')?.se(name);
    }

    /** Étape 12 : fin du combat -> la musique s'arrête, jingle de victoire ou de capture */
    async endMusic(result) {
        const audio = this.registry.get('audio');
        if (!audio) return;
        audio.stopMusic();
        if (result === 'caught') return audio.me('caught', { resume: false });
        if (result !== 'win') return;
        const music = this.view?.type === 'wild' ? 'wild' : this.view?.foe?.music === 'leader' || this.view?.foe?.music === 'title' ? 'leader' : 'trainer';
        // Sans attendre : le texte de victoire défile pendant le jingle. Il est coupé en fondu au bout de
        // quelques secondes (config/audio.php : victory_seconds), ou dès le retour sur la carte.
        audio.me(`victory_${music}`, { resume: false, maxSeconds: audio.victorySeconds, interruptible: true });
    }

    spriteOf(side) {
        return side === 'player' ? this.playerSprite : this.foeSprite;
    }

    // ---- envoi des Pokémon ----

    async sendOutPlayer(e) {
        const b = e.battler;
        await loadImages(this, { [spriteKey(b.sprites.back)]: b.sprites.back });

        // Le dresseur lance la Ball (images 1 à 4 de son sprite de dos), puis sort à gauche
        if (this.playerTrainer) {
            for (let f = 1; f <= 4; f++) {
                this.playerTrainer.setFrame(f);
                await wait(this, 60);
            }
            this.tweens.add({ targets: this.playerTrainer, x: -100, duration: 400, onComplete: () => { this.playerTrainer.destroy(); this.playerTrainer = null; } });
        }

        this.playerSprite?.destroy();
        this.playerSprite = this.createPlayerSprite(b);
        await this.ballOpen(this.playerSprite, { from: { x: 60, y: 250 }, ball: 'POKEBALL' });

        this.playerBox.setBattler(b);
        this.lastMove = 0;
        await this.playerBox.slideIn();
    }

    async sendOutFoe(e) {
        const b = e.battler;
        await loadImages(this, { [spriteKey(b.sprites.front)]: b.sprites.front });

        if ((this.view.type === 'wild' || this.safari) && this.foeSprite && !this.foeSprite.fainted) {
            // Sauvage : il est déjà là (arrivé en glissant)
            this.foeBox.setBattler(b);
            await this.foeBox.slideIn();
            return;
        }

        if (this.foeTrainer) {
            // Le dresseur sort de l'écran à droite
            this.tweens.add({ targets: this.foeTrainer, x: 600, duration: 400, onComplete: () => this.foeTrainer.setVisible(false) });
        }

        this.foeSprite?.destroy();
        this.foeSprite = this.createFoeSprite(b);
        await this.ballOpen(this.foeSprite, { from: { x: 560, y: 60 }, ball: 'POKEBALL' });
        this.foeBox.setBattler(b);
        await this.foeBox.slideIn();
    }

    /** Une Ball arrive en cloche, s'ouvre, et le Pokémon apparaît (en grandissant, blanc puis normal) */
    async ballOpen(sprite, { from, ball }) {
        const target = { x: sprite.x, y: sprite.y - sprite.height / 2 };
        sprite.setVisible(false);
        const b = this.add.sprite(from.x, from.y, `ball_${ball}`, 0);
        sprite.parentContainer?.add(b);
        let frame = 0;
        const spin = this.time.addEvent({ delay: 40, loop: true, callback: () => b.setFrame((frame = (frame + 1) % 8)) });

        await tweenAsync(this, {
            targets: b, x: target.x, duration: 350,
            onUpdate: (tween) => { b.y = Phaser.Math.Linear(from.y, target.y, tween.progress) - Math.sin(tween.progress * Math.PI) * 60; },
        });
        spin.remove();
        b.setTexture(`ball_${ball}_open`);
        await wait(this, 80);
        b.destroy();

        tintFill(sprite.setVisible(true).setScale(0), 0xffffff);
        await tweenAsync(this, { targets: sprite, scale: 1, duration: 250 });
        clearTint(sprite);
    }

    async recall(side) {
        const sprite = this.spriteOf(side);
        if (!sprite) return;
        tintFill(sprite, 0xf05050);
        await tweenAsync(this, { targets: sprite, scale: 0, duration: 250 });
        sprite.destroy();
        if (side === 'player') {
            this.playerSprite = null;
            await this.playerBox.slideOut();
        } else {
            this.foeSprite = null;
            await this.foeBox.slideOut();
        }
    }

    // ---- attaques ----

    async moveAnimation(e) {
        const user = this.spriteOf(e.side);
        const target = this.spriteOf(e.side === 'player' ? 'foe' : 'player');
        if (!user) return;
        const dir = e.side === 'player' ? 1 : -1;

        if (e.category === 'physical') {
            // Le lanceur fonce vers sa cible
            await tweenAsync(this, { targets: user, x: user.x + 24 * dir, y: user.y - 12 * dir, duration: 90, yoyo: true });
        } else if (e.category === 'special' && target && !e.missed) {
            // Éclair de la couleur du type sur la cible
            await this.tintPulse(target, TYPE_COLORS[e.moveType] ?? 0xffffff, 2);
        } else {
            // Attaque de statut : petit saut
            await tweenAsync(this, { targets: user, y: user.y - 8, duration: 80, yoyo: true });
        }
    }

    async hpChange(e) {
        const box = e.side === 'player' ? this.playerBox : this.foeBox;
        const sprite = this.spriteOf(e.side);

        // Touché : le Pokémon clignote
        if ((e.cause === 'damage' || e.cause === 'confusion' || e.cause === 'recoil' || e.cause === 'residual') && sprite) {
            for (let i = 0; i < 3; i++) {
                sprite.setVisible(false);
                await wait(this, 60);
                sprite.setVisible(true);
                await wait(this, 60);
            }
        }

        if (e.side === 'player') {
            await box.animateHp(e.hp, e.maxHp);
            const p = this.view.player.party[this.view.player.active];
            if (p) p.hp = e.hp;
        } else {
            await box.animateHp(e.ratio);
        }
    }

    async faint(side) {
        const sprite = this.spriteOf(side);
        const box = side === 'player' ? this.playerBox : this.foeBox;
        if (sprite) {
            sprite.fainted = true;
            // Le Pokémon "s'enfonce" sous l'écran : il descend pendant qu'on coupe le bas de l'image
            const h = sprite.height;
            const startY = sprite.y;
            await tweenAsync(this, {
                targets: { p: 0 }, p: 1, duration: 350,
                onUpdate: (tween) => {
                    const p = tween.progress;
                    sprite.y = startY + h * p;
                    sprite.setCrop(0, 0, sprite.width, Math.max(0, h * (1 - p)));
                },
            });
            sprite.setVisible(false);
        }
        await box.slideOut();
    }

    async tintPulse(sprite, color, times = 2) {
        if (!sprite) return;
        for (let i = 0; i < times; i++) {
            blend(sprite, color, 0.6);
            await wait(this, 90);
            clearTint(sprite);
            await wait(this, 70);
        }
    }

    // ---- expérience ----

    async expGain(e) {
        // Seul le Pokémon au combat a sa barre affichée
        if (e.index !== this.view.player.active && !(this.previousView && e.index === this.previousView.player.active)) return;
        if (!this.playerBox.visible) return;
        await this.playerBox.animateExp(e.exp, e.levelExp, e.nextLevelExp);
    }

    async levelUp(e) {
        const isActive = this.playerBox.visible && this.playerBox.battler && (e.index === this.view.player.active);
        if (isActive) {
            this.playerBox.setLevel(e.level);
            this.playerBox.setHp(e.hp, e.maxHp);
            this.playerBox.setExp(e.levelExp, e.levelExp, e.nextLevelExp);
            if (this.playerSprite) await this.tintPulse(this.playerSprite, 0xffffff, 2);
        }
        this.pendingStats = e; // affichées après le message "monte au N. X"
    }

    /** Fenêtre des stats après une montée de niveau (comme Essentials : les gains, puis les totaux) */
    async showLevelStats(e) {
        const keys = Object.keys(STAT_LABELS);
        const win = new InfoWindow(this, {
            x: 512 - 236, y: 0, width: 236, height: 6 * 32 + 32,
            lines: keys.map((k) => ({ left: STAT_LABELS[k], right: `+${e.stats[k] - e.oldStats[k]}` })),
        }).setDepth(900);
        await this.waitKey();
        win.setLines(keys.map((k) => ({ left: STAT_LABELS[k], right: String(e.stats[k]) })));
        await this.waitKey();
        win.destroy();
    }

    waitKey() {
        return new Promise((resolve) => {
            this.focus = { clickAnywhere: true, handleKey: (a) => { if (a === 'ok' || a === 'cancel') { this.focus = null; resolve(); } } };
        });
    }

    // ---- Ball ----

    /** Étape 15 : le dresseur lance un Appât ou un Caillou */
    async safariThrow(what) {
        await this.trainerThrow();
        this.sfx('throw');
        const foe = this.foeSprite;
        const target = { x: foe.x, y: foe.y - foe.height / 3 };
        const from = { x: 60, y: 260 };
        const thing = this.add.circle(from.x, from.y, what === 'rock' ? 6 : 5, what === 'rock' ? 0x8c8c84 : 0xb07838).setStrokeStyle(2, 0x303030);
        await tweenAsync(this, {
            targets: thing, x: target.x, duration: 450,
            onUpdate: (t) => { thing.y = Phaser.Math.Linear(from.y, target.y, t.progress) - Math.sin(t.progress * Math.PI) * 90; },
        });
        if (what === 'rock') {
            thing.destroy();
            this.sfx('hit');
            await this.tintPulse(foe, 0xff5050, 2);
        } else {
            // L'Appât tombe devant le Pokémon
            await tweenAsync(this, { targets: thing, y: FOE_BASE.y + 4, duration: 200 });
            await wait(this, 300);
            thing.destroy();
        }
    }

    /** Étape 15 : le dresseur de dos fait le geste du lancer (images 1 à 4 de son sprite) */
    async trainerThrow() {
        if (!this.playerTrainer) return;
        for (let f = 1; f <= 4; f++) {
            this.playerTrainer.setFrame(f);
            await wait(this, 50);
        }
        this.playerTrainer.setFrame(0);
    }

    /** Étape 15 : le Pokémon du Parc Safari s'enfuit */
    async foeFlee() {
        this.sfx('flee');
        if (this.foeSprite) await tweenAsync(this, { targets: this.foeSprite, x: 600, duration: 400 });
        await this.foeBox.slideOut();
    }

    async throwBall(e) {
        if (this.safari) await this.trainerThrow();
        this.sfx('throw');
        const key = ballKey(e.ball);
        const foe = this.foeSprite;
        const target = { x: foe.x, y: foe.y - foe.height / 2 };
        const from = { x: 60, y: 260 };
        const ball = this.add.sprite(from.x, from.y, `ball_${key}`, 0);
        let frame = 0;
        const spin = this.time.addEvent({ delay: 40, loop: true, callback: () => ball.setFrame((frame = (frame + 1) % 8)) });

        await tweenAsync(this, {
            targets: ball, x: target.x, duration: 450,
            onUpdate: (t) => { ball.y = Phaser.Math.Linear(from.y, target.y, t.progress) - Math.sin(t.progress * Math.PI) * 90; },
        });

        if (e.blocked) {
            // Le dresseur dévie la Ball
            spin.remove();
            await tweenAsync(this, { targets: ball, x: target.x - 80, y: target.y + 60, alpha: 0, duration: 300 });
            ball.destroy();
            return;
        }

        // Le Pokémon est aspiré dans la Ball
        spin.remove();
        ball.setTexture(`ball_${key}_open`);
        tintFill(foe, 0xf8a8a8);
        await tweenAsync(this, { targets: foe, scale: 0, duration: 250 });
        ball.setTexture(`ball_${key}`, 0);

        // La Ball tombe au sol, puis bouge "shakes" fois
        const ground = FOE_BASE.y - 6; // la Ball se pose au milieu du socle, là où étaient les pieds du Pokémon
        await tweenAsync(this, { targets: ball, y: ground, duration: 250, ease: 'Bounce.easeOut' });
        this.sfx('ball_drop');
        for (let i = 0; i < e.shakes; i++) {
            await wait(this, 350);
            this.sfx('ball_shake');
            await tweenAsync(this, { targets: ball, angle: 20, duration: 100, yoyo: true });
            await tweenAsync(this, { targets: ball, angle: -20, duration: 100, yoyo: true });
        }
        await wait(this, 350);

        if (e.caught) {
            // Capturé : clic de la Ball, qui s'assombrit un instant
            this.sfx('catch_click');
            tint(ball, 0x808080);
            await wait(this, 400);
            this.captureBall = ball;
            return;
        }

        // Raté : la Ball s'ouvre et le Pokémon ressort
        ball.setTexture(`ball_${key}_open`);
        clearTint(foe);
        tintFill(foe, 0xffffff);
        await tweenAsync(this, { targets: foe, scale: 1, duration: 250 });
        clearTint(foe);
        ball.destroy();
    }

    async trainerReturn() {
        if (!this.foeTrainer) return;
        this.foeTrainer.setVisible(true).setPosition(600, FOE_BASE.y + 6);
        await tweenAsync(this, { targets: this.foeTrainer, x: FOE_BASE.x, duration: 400 });
    }

    // =====================================================================
    // Fin du combat
    // =====================================================================

    // =====================================================================
    // Combat entre joueurs (PvP)
    // =====================================================================

    pvpUrl(key) {
        return this.config.multiplayer[key].replace('BATTLE_ID', this.view.id);
    }

    /** Mon choix part au serveur ; le tour se joue quand l'autre joueur aura choisi aussi */
    async sendPvp(action) {
        this.busy = true;
        const r = await api.post(this.pvpUrl('pvpAction'), { ...action, since: this.view.pvp.seq });
        this.busy = false;
        if (!r.ok) {
            await this.flash(r.data.message ?? 'Action impossible.');
            return this.nextPhase();
        }
        return this.applyPvp(r.data);
    }

    /** Nouveaux événements (et nouvelle vue) reçus du serveur */
    async applyPvp(data) {
        const previous = this.view;
        this.view = data.battle;
        this.previousView = previous;
        if (data.events?.length) {
            this.hideWaiting();
            await this.playEvents(data.events);
            this.syncWithView();
        }
        return this.nextPhase();
    }

    /**
     * J'attends l'autre joueur : message "En attente...", puis on relit le combat dès que Reverb
     * prévient (notification "pvp"), ou toutes les 3 secondes sans Reverb.
     * Après un long silence de l'adversaire, on peut le déclarer forfait.
     */
    async pvpWait() {
        this.showWaiting(`En attente de ${this.view.pvp.opponent}...`);
        const seq = this.view.pvp.seq;
        let first = true;
        while (this.scene.isActive()) {
            // On relit tout de suite une première fois (l'autre a pu jouer pendant notre requête)
            if (!first) await this.waitForNotice(this.multi?.connected ? 15000 : 3000);
            first = false;
            const r = await api.get(`${this.pvpUrl('pvp')}?since=${seq}`);
            if (!r.ok) continue;
            if (r.data.battle.pvp.seq !== seq || r.data.battle.phase === 'ended') {
                return this.applyPvp(r.data);
            }
            this.view = r.data.battle;
            if (this.view.pvp.canClaim && !this.claimOffered) {
                this.claimOffered = true;
                this.hideWaiting();
                this.message.setVisible(true);
                await this.message.show(`${this.view.pvp.opponent} ne répond plus. Le déclarer forfait ?`, { keep: true });
                if (await this.yesNo()) {
                    const c = await api.post(this.pvpUrl('pvpClaim'), { since: seq });
                    if (c.ok) return this.applyPvp(c.data);
                }
                this.showWaiting(`En attente de ${this.view.pvp.opponent}...`);
            }
        }
    }

    /** Attend la notification "pvp" de ce combat (ou la fin du délai) */
    waitForNotice(ms) {
        return new Promise((resolve) => {
            const finish = () => {
                clearTimeout(timer);
                this.multi?.off('pvp', onNotice);
                resolve();
            };
            const onNotice = (d) => { if (d.battleId === this.view.id) finish(); };
            const timer = setTimeout(finish, ms);
            this.multi?.on('pvp', onNotice);
        });
    }

    showWaiting(text) {
        this.message.setVisible(true);
        this.message.show(text, { keep: true });
    }

    hideWaiting() {
        this.message.clear?.();
    }

    async forfeit() {
        this.message.setVisible(true);
        await this.message.show('Abandonner le combat ?', { keep: true });
        if (!(await this.yesNo())) return this.chooseCommand();
        const r = await api.post(this.pvpUrl('pvpForfeit'), { since: this.view.pvp.seq });
        if (!r.ok) {
            await this.flash(r.data.message ?? 'Impossible.');
            return this.chooseCommand();
        }
        return this.applyPvp(r.data);
    }

    async endBattle() {
        // Étape 19 : un Pokémon capturé : lui donner un surnom ?
        const caught = this.view.result === 'caught' ? this.view.caught : null;
        if (caught?.id && this.config.menu?.nickname) await this.nicknameCaught(caught);
        this.message.setVisible(false);
        this.cameras.main.fadeOut(400, 0, 0, 0);
        await new Promise((resolve) => this.cameras.main.once('camerafadeoutcomplete', resolve));
        const result = { result: this.view.result, ...this.view };
        this.scene.stop();
        this.onEnd?.(result);
    }
}


/** Clé de texture d'un sprite de Pokémon : son URL */
function spriteKey(url) {
    return `pkmn_${url}`;
}

