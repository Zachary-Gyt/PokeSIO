import { ChoiceWindow, InfoWindow, MessageBox } from '../battle/BattleUI.js';
import PartyScreen from '../battle/PartyScreen.js';
import { loadImages } from '../battle/BattleAssets.js';
import { attachControls } from '../ui/Controls.js';
import { createWindow } from '../ui/WindowSkin.js';
import PixelText from '../ui/PixelText.js';
import SummaryScreen from './SummaryScreen.js';
import BagScreen from './BagScreen.js';
import StorageScreen from './StorageScreen.js';
import EvolutionScreen from './EvolutionScreen.js';
import TrainerCardScreen, { formatMoney } from './TrainerCardScreen.js';
import MartScreen from './MartScreen.js';
import TradeScreen from './TradeScreen.js';
import FlyScreen from './FlyScreen.js';
import PokedexScreen from './PokedexScreen.js';
import HatchScreen from './HatchScreen.js';
import HallOfFameScreen from './HallOfFameScreen.js';
import NamingScreen from './NamingScreen.js';
import SlotMachineScreen from './SlotMachineScreen.js';
import RouletteScreen from './RouletteScreen.js';
import { toast } from '../ui/Toast.js';
import { api } from './Api.js';

/**
 * La scène des MENUS, ouverte par-dessus la carte (qui est mise en pause) :
 *   { type: 'menu' }                      menu pause : Pokémon, Sac, Sauvegarder
 *   { type: 'pc' }                        PC de stockage
 *   { type: 'evolutions', list: [...] }   évolutions après un combat
 *   { type: 'shop', shop, map }           Boutique (vendeur : PNJ action = shop)
 *   { type: 'title', slug }               Tenant du titre : présentation, puis "Veux-tu me défier ?"
 *   { type: 'titleBoard', slug }          Tableau d'honneur des tenants
 *   { type: 'player', target }            un autre joueur : Échanger / Combattre (multijoueur)
 *   { type: 'invitation', invitation }    un joueur m'invite : accepter ou refuser
 *   { type: 'trade', tradeId }            l'écran d'échange
 *   { type: 'ask', text }                 une question Oui / Non (étape 11 : "Voulez-vous utiliser Coupe ?")
 *   { type: 'choose', text, labels, cancelIndex } étape 24 : un choix dans une liste (menu d'un événement) -> index
 *   { type: 'nickname', pokemon }         étape 24 : "Donner un surnom à X ?" (Pokémon reçu d'un événement)
 *   { type: 'hallOfFame', entry }         étape 27 : la cérémonie du Panthéon (après la victoire contre le Maître)
 *   { type: 'hallOfFamePc' }              étape 27 : le PC du Panthéon (revoir ses entrées)
 *   { type: 'tower', map, after }         étape 28 : le comptoir de la Tour de Combat (after = résultat du dernier combat)
 *                                         -> { towerBattle: true } pour lancer le combat suivant
 *   { type: 'towerBoard' }                étape 28 : le tableau des meilleures séries
 *   { type: 'bank', map, atm }            la Banque (atm = distributeur : pas de banquier qui parle)
 *   { type: 'daycare', map, name }        étape 18 : la responsable de la Pension (confier / reprendre)
 *   { type: 'hatch', list: [...] }        étape 18 : des Œufs qui éclosent
 *   { type: 'coins', map, name }          étape 20 : le guichet des jetons du Casino
 *   { type: 'prizes', map, counter, name } étape 20 : un comptoir des lots
 *   { type: 'slots', map, difficulty }    étape 20 : une machine à sous
 *   { type: 'roulette', map, wager, color } étape 20 : une table de roulette
 *
 * onClose(result) : result = { challenge: true } si le joueur veut défier le tenant,
 *                   { tradeId } ou { battleId } après une invitation acceptée
 *
 *   this.scene.launch('Menu', { type: 'menu', onClose: () => ... })
 */
// Étape 11 : capacités utilisables hors combat (slugs de la table moves)
const FIELD_MOVES = ['cut', 'fly', 'surf', 'strength', 'flash', 'rock-smash'];

const STAT_LABELS = { hp: 'PV Max.', attack: 'Attaque', defense: 'Défense', sp_attack: 'Atq. Spé.', sp_defense: 'Déf. Spé.', speed: 'Vitesse' };

export default class MenuScene extends Phaser.Scene {
    constructor() {
        super('Menu');
    }

    init(data) {
        this.task = data;
        this.config = this.registry.get('config');
        this.urls = this.config.menu;
        this.multiUrls = this.config.multiplayer ?? {};
        this.multi = this.registry.get('multi');
        this.focus = null;
        this.menuIndex = 0;
    }

    create() {
        this.message = new MessageBox(this, { background: 'ui_dialog' }).setDepth(2000).setVisible(false);
        attachControls(this);
        this.run();
    }

    async run() {
        let result = null;
        switch (this.task.type) {
            case 'pc':
                await this.openStorage();
                break;
            case 'evolutions':
                for (const preview of this.task.list ?? []) await this.evolve(preview);
                break;
            case 'shop':
                await this.openShop(this.task.shop, this.task.map, this.task.name);
                break;
            case 'title':
                result = await this.titleHolder(this.task.slug, this.task.name);
                break;
            case 'titleBoard':
                await this.titleBoard(this.task.slug);
                break;
            case 'player':
                await this.playerMenu(this.task.target);
                break;
            case 'invitation':
                result = await this.invitation(this.task.invitation);
                break;
            case 'trade':
                await this.trade(this.task.tradeId);
                break;
            case 'ask':
                result = await this.yesNo(this.task.text);
                break;
            case 'choose':
                result = await this.choose(this.task.text, this.task.labels ?? [], { cancelIndex: this.task.cancelIndex ?? (this.task.labels ?? []).length - 1 });
                if (result === null) result = this.task.cancelIndex ?? (this.task.labels ?? []).length - 1;
                break;
            case 'nickname':
                await this.offerNickname(this.task.pokemon);
                break;
            case 'hallOfFame':
                await new HallOfFameScreen(this, this.task.entry, this.ui, { ceremony: true }).run();
                break;
            case 'hallOfFamePc':
                await this.hallOfFamePc();
                break;
            case 'tower':
                result = await this.battleTower(this.task.map, this.task.after ?? null);
                break;
            case 'towerBoard':
                await this.towerBoard();
                break;
            case 'bank':
                await this.bank(this.task.map, this.task.atm ?? false);
                break;
            case 'daycare':
                result = await this.daycare(this.task.map, this.task.name);
                break;
            case 'hatch':
                for (const hatched of this.task.list ?? []) {
                    await new HatchScreen(this, hatched, this.ui).run();
                    // Étape 19 : un surnom pour le bébé, comme dans les jeux
                    await this.offerNickname(hatched.pokemon);
                }
                break;
            case 'coins':
                await this.coinSeller(this.task.map);
                break;
            case 'prizes':
                await this.prizeCounter(this.task.map, this.task.counter ?? 'pokemon');
                break;
            case 'slots':
                await this.slotMachine(this.task.map, this.task.difficulty ?? 1);
                break;
            case 'roulette':
                await this.roulette(this.task.map, this.task.wager ?? 1, this.task.color ?? 'red');
                break;
            default:
                // Étape 11 : une capacité choisie dans l'équipe ({ field: 'cut' }) est renvoyée à la carte
                result = await this.pauseMenu();
        }
        this.close(result);
    }

    close(result = null) {
        this.scene.stop();
        this.task.onClose?.(result);
    }

    // =====================================================================
    // Boîtes de dialogue (message en bas, choix, oui / non)
    // =====================================================================

    /** Un message ; pause = attendre une touche */
    async say(text, { pause = true } = {}) {
        this.message.setVisible(true);
        await this.message.show(text, { pause });
        this.message.setVisible(false);
    }

    /** Un message + une liste de choix à droite ; renvoie l'index choisi, ou null (Retour) */
    async choose(text, labels, { cancelIndex = labels.length - 1 } = {}) {
        const previousFocus = this.focus;
        this.message.setVisible(true);
        if (text) await this.message.show(text, { keep: true });
        const height = labels.length * 32 + 32;
        const width = Math.max(200, ...labels.map((l) => Math.ceil(PixelText.measure(l)) + 52));
        const win = new ChoiceWindow(this, labels.map((label) => ({ label })), {
            x: 512 - width, y: 288 - height, width, cancelValue: cancelIndex,
        }).setDepth(2001);
        this.focus = win;
        this.currentChoice = win;   // un événement extérieur (échange modifié...) peut la fermer : close('external')
        const choice = await win.promise;
        this.currentChoice = null;
        this.focus = previousFocus;
        this.message.clear();
        this.message.setVisible(false);
        return choice === cancelIndex && labels[cancelIndex] === 'Annuler' ? null : choice;
    }

    async yesNo(text) {
        return (await this.choose(text, ['Oui', 'Non'], { cancelIndex: 1 })) === 0;
    }

    get ui() {
        return {
            say: (text, options) => this.say(text, options),
            choose: (text, labels) => this.choose(text, labels),
            yesNo: (text) => this.yesNo(text),
        };
    }

    // =====================================================================
    // Menu pause (fenêtre en haut à droite, comme Essentials)
    // =====================================================================

    async pauseMenu() {
        while (true) {
            // Comme Essentials : le nom du joueur ouvre sa Carte Dresseur
            // Étape 12 : "Options" (volumes de la musique et des effets)
            // Étape 13 : "Pokédex" en tête, comme dans Essentials
            // "Groupe" : les groupes de joueurs (membres, inviter, quitter)
            const commands = ['Pokédex', 'Pokémon', 'Sac', this.config.character.name, 'Groupe', 'Sauvegarder', 'Options', 'Retour'];
            const win = new ChoiceWindow(this, commands.map((label) => ({ label })), {
                x: 512 - 200, y: 0, width: 200, cancelValue: 7,
            }).setDepth(1000);
            win.index = this.menuIndex;
            win.drawRows();
            this.focus = win;
            const choice = await win.promise;
            this.focus = null;
            if (choice === null || choice === 7) return null;
            this.menuIndex = choice;

            if (choice === 0) await this.openPokedex();
            if (choice === 1) {
                const fieldMove = await this.openParty();
                if (fieldMove) return fieldMove;
            }
            if (choice === 2) {
                // Étape 14 : la Bicyclette utilisée depuis le Sac ferme le menu ({ bike: true })
                // Étape 15 : une canne aussi ({ fish: id de la canne })
                const used = await this.openBag();
                if (used) return used;
            }
            if (choice === 3) await this.trainerCard();
            if (choice === 4) await this.groupMenu();
            if (choice === 5) await this.save();
            if (choice === 6) await this.options();
        }
    }

    /**
     * Étape 12 : les OPTIONS. Haut / bas : choisir la ligne ; gauche / droite : -10 % / +10 % ;
     * Action ou Retour : fermer. Les volumes sont gardés dans ce navigateur.
     */
    options() {
        const audio = this.registry.get('audio');
        if (!audio) return Promise.resolve();
        return new Promise((resolve) => {
            const width = 320;
            const rows = [
                { label: 'Musique', get: () => audio.musicVolume, set: (v) => audio.setMusicVolume(v) },
                { label: 'Effets', get: () => audio.effectsVolume, set: (v) => { audio.setEffectsVolume(v); audio.se('decision'); } },
            ];
            const box = this.add.container(512 - width, 0).setDepth(1100);
            box.add(createWindow(this, 'ui_choice', width, rows.length * 32 + 32));
            const arrow = this.add.image(14, 0, 'ui_sel_arrow').setOrigin(0, 0);
            box.add(arrow);
            const texts = rows.map((row, i) => {
                const label = new PixelText(this, 32, 12 + i * 32, row.label, { color: '#505058', shadow: '#a0a0a8' });
                const value = new PixelText(this, width - 20, 12 + i * 32, '', { color: '#505058', shadow: '#a0a0a8', align: 'right' });
                box.add([label, value]);
                return value;
            });
            let index = 0;
            const draw = () => {
                arrow.y = 14 + index * 32;
                rows.forEach((row, i) => texts[i].setText(`< ${Math.round(row.get() * 100)} % >`));
            };
            draw();
            const previous = this.focus;
            this.focus = {
                silent: true,   // pas de son de curseur : on entend déjà le nouveau volume
                handleKey: (a) => {
                    if (a === 'up' || a === 'down') index = (index + 1) % rows.length;
                    if (a === 'left' || a === 'right') {
                        const row = rows[index];
                        row.set(Math.round((row.get() + (a === 'right' ? 0.1 : -0.1)) * 10) / 10);
                    }
                    if (a === 'ok' || a === 'cancel') {
                        box.destroy(true);
                        this.focus = previous;
                        this.registry.get('audio')?.se('cancel');
                        resolve();
                        return true;
                    }
                    draw();
                    return true;
                },
            };
        });
    }

    /** Étape 13 : le Pokédex */
    async openPokedex() {
        if (!this.config.pokedex) return;
        await new PokedexScreen(this).open();
        this.focus = null;
    }

    async trainerCard() {
        const r = await api.get(this.urls.trainerCard);
        if (!r.ok) return;
        const screen = new TrainerCardScreen(this, r.data, this.config.basePath);
        const previous = this.focus;
        const promise = screen.open();
        this.focus = screen;
        await promise;
        this.focus = previous;
    }

    /**
     * Choisir une quantité (Boutique, jeter) : fenêtre "x 1   200 ₽" au-dessus du message.
     * Haut / bas : +1 / -1 ; droite / gauche : +10 / -10. Renvoie le nombre, ou null (Retour).
     */
    chooseNumber(text, max, unitPrice = null) {
        return new Promise(async (resolve) => {
            this.message.setVisible(true);
            await this.message.show(text, { keep: true });
            const width = unitPrice === null ? 120 : 224;
            const win = this.add.container(512 - width, 288 - 64).setDepth(2001);
            const frame = createWindow(this, 'ui_choice', width, 64);
            const count = new PixelText(this, 20, 14, '', { color: '#505058', shadow: '#a0a0a8' });
            const price = new PixelText(this, width - 20, 14, '', { color: '#505058', shadow: '#a0a0a8', align: 'right' });
            win.add([frame, count, price]);
            let n = 1;
            const draw = () => {
                count.setText(`x${String(n).padStart(2, '0')}`);
                if (unitPrice !== null) price.setText(`${formatMoney(n * unitPrice)} ₽`);
            };
            draw();
            const previous = this.focus;
            const finish = (value) => {
                win.destroy(true);
                this.message.clear();
                this.message.setVisible(false);
                this.focus = previous;
                resolve(value);
            };
            this.focus = {
                wheel: true,
                handleKey: (a) => {
                    if (a === 'up') n = n >= max ? 1 : n + 1;
                    if (a === 'down') n = n <= 1 ? max : n - 1;
                    if (a === 'right') n = Math.min(max, n + 10);
                    if (a === 'left') n = Math.max(1, n - 10);
                    if (a === 'ok') return finish(n), true;
                    if (a === 'cancel') return finish(null), true;
                    draw();
                    return true;
                },
            };
        });
    }

    async save() {
        this.registry.get('save')?.save();
        this.registry.get('audio')?.se('save');
        await this.say(`${this.config.character.name} a sauvegardé la partie.`);
    }

    // =====================================================================
    // Équipe
    // =====================================================================

    async fetchParty() {
        const result = await api.get(this.urls.party);
        const party = result.data.party ?? [];
        await loadImages(this, Object.fromEntries(party.map((p) => [`pkmn_icon_${p.sprites.icon}`, p.sprites.icon])));
        return party;
    }

    async openParty() {
        let party = await this.fetchParty();
        let index = 0;
        // Étape 11 : les capacités de terrain que connaît chaque Pokémon (Coupe, Vol...) s'ajoutent à son menu
        const fieldMovesOf = (p) => (p.moves ?? []).filter((m) => FIELD_MOVES.includes(m.slug));
        while (true) {
            const screen = new PartyScreen(this, party, {
                prompt: 'Choisissez un Pokémon.',
                // Étape 14 : "Objet" (Donner / Prendre), comme Essentials
                // Étape 18 : un Œuf n'a ni capacités ni objet
                // Étape 19 : "Surnom"
                commands: (p) => (p.egg ? ['Résumé', 'Échanger', 'Annuler'] : ['Résumé', 'Surnom', ...fieldMovesOf(p).map((m) => m.name), 'Échanger', 'Objet', 'Annuler']),
            });
            screen.index = index;
            screen.refresh();
            this.focus = screen;
            const result = await screen.open();
            this.focus = null;
            if (!result) return;
            index = result.index;

            const fieldMove = fieldMovesOf(party[result.index]).find((m) => m.name === result.command);
            if (fieldMove) {
                const chosen = await this.chooseFieldMove(fieldMove.slug);
                if (chosen) return chosen;
            } else if (result.command === 'Résumé') {
                // Page Capacités : on peut y changer les capacités des Pokémon de l'équipe
                await this.showSummary(party, result.index, { moves: this.moveEditor() });
            } else if (result.command === 'Surnom') {
                const updated = await this.rename(party[result.index]);
                if (updated) party = updated.party;
            } else if (result.command === 'Objet') {
                // L'écran d'équipe reste affiché derrière les questions (il ne reçoit pas les touches)
                const backdrop = new PartyScreen(this, party, { prompt: 'Choisissez un Pokémon.' });
                backdrop.index = index;
                backdrop.refresh();
                party = (await this.heldItemMenu(party[result.index])) ?? party;
                backdrop.close(null);
            } else if (result.command === 'Échanger' && result.to !== result.index && result.to < party.length) {
                const r = await api.post(this.urls.swap, { a: result.index + 1, b: result.to + 1 });
                if (r.ok) party = r.data.party;
                index = result.to;
            }
        }
    }

    /**
     * Une capacité de terrain choisie dans l'équipe. Badge manquant : message. Vol : la carte des villes.
     * Renvoie { field, to? } (le menu se ferme et la carte s'en occupe), ou null pour rester dans l'équipe.
     */
    async chooseFieldMove(slug) {
        const info = this.task.field?.moves?.[slug];
        if (info && !info.allowed) {
            await this.say(info.reason);
            return null;
        }
        if (slug !== 'fly') return { field: slug };

        const destinations = this.task.field?.fly ?? [];
        if (!destinations.length) {
            await this.say('Vous n\'avez encore visité aucune ville où vous poser.');
            return null;
        }
        const screen = new FlyScreen(this, destinations);
        const promise = screen.open();
        const previous = this.focus;
        this.focus = screen.choices;
        const to = await promise;
        this.focus = previous;
        return to ? { field: 'fly', to } : null;
    }

    // =====================================================================
    // Étape 14 : objets tenus
    // =====================================================================

    /** Équipe > Objet : Donner / Prendre. Renvoie la nouvelle équipe, ou null si rien n'a changé */
    async heldItemMenu(pokemon) {
        const choice = await this.choose(`Que faire avec l'objet de ${pokemon.name} ?`, ['Donner', 'Prendre', 'Annuler']);
        if (choice === 0) {
            const bag = (await api.get(this.urls.bag)).data;
            const screen = new BagScreen(this, bag, { mode: 'give', basePath: this.config.basePath });
            this.focus = screen;
            const item = await screen.open();
            this.focus = null;
            if (!item) return null;
            return this.giveItem(pokemon, item);
        }
        if (choice === 1) {
            if (!pokemon.item) {
                await this.say(`${pokemon.name} ne tient aucun objet.`);
                return null;
            }
            const r = await api.post(this.urls.take, { pokemon_id: pokemon.id });
            await this.say(r.data.message ?? 'Impossible.');
            return r.ok ? r.data.party : null;
        }
        return null;
    }

    /** Donne un objet du Sac ; s'il tient déjà quelque chose, on demande avant d'échanger. Renvoie l'équipe */
    async giveItem(pokemon, item) {
        if (!item.holdable) {
            await this.say(`${item.name} ne peut pas être tenu par un Pokémon.`);
            return null;
        }
        if (pokemon.item && !(await this.yesNo(`${pokemon.name} tient déjà ${pokemon.item.name}. Échanger les objets ?`))) {
            return null;
        }
        const r = await api.post(this.urls.give, { pokemon_id: pokemon.id, item_id: item.id });
        await this.say(r.data.message ?? 'Impossible.');
        return r.ok ? r.data.party : null;
    }

    /** Sac > Donner : choisir le Pokémon qui tiendra l'objet ; renvoie le nouveau contenu du Sac */
    async giveFromBag(item) {
        const party = await this.fetchParty();
        const screen = new PartyScreen(this, party, { prompt: `Donner ${item.name} à quel Pokémon ?`, mode: 'item', depth: 1450 });
        this.focus = screen;
        const index = await screen.open();
        this.focus = null;
        if (index === null || index === undefined) return null;
        const updated = await this.giveItem(party[index], item);
        return updated ? (await api.get(this.urls.bag)).data : null;
    }

    /**
     * Ce qu'il faut au Résumé pour changer les capacités : la liste des capacités possibles d'un Pokémon,
     * et le remplacement d'une capacité (renvoie le Pokémon à jour, ou null). Les erreurs s'affichent en bandeau.
     */
    moveEditor() {
        return {
            load: async (pokemon) => {
                const r = await api.get(`${this.urls.partyMoves}?pokemon_id=${pokemon.id}`);
                if (!r.ok) toast(r.data.message ?? 'Impossible.');
                return r.ok ? r.data.moves : null;
            },
            replace: async (pokemon, slot, move) => {
                const r = await api.post(this.urls.partyMovesReplace, { pokemon_id: pokemon.id, slot, move_id: move.id });
                if (!r.ok) {
                    toast(r.data.message ?? 'Impossible.');
                    return null;
                }
                this.registry.get('audio')?.se('save');
                toast(`${pokemon.name} apprend ${move.name} !`);
                return r.data.pokemon;
            },
        };
    }

    async showSummary(list, index, options = {}) {
        const summary = new SummaryScreen(this, list, index, options);
        const previous = this.focus;
        this.focus = summary;
        await summary.open();
        this.focus = previous;
    }

    // =====================================================================
    // Sac
    // =====================================================================

    async openBag() {
        let bag = (await api.get(this.urls.bag)).data;
        while (true) {
            const screen = new BagScreen(this, bag, { mode: 'field', basePath: this.config.basePath, keepOpen: true });
            this.focus = screen;
            const item = await screen.open();
            this.focus = null;
            if (!item) return;

            // Le Sac reste affiché derrière le menu de l'objet, comme dans Essentials
            // Étape 14 : "Donner" pour les objets qu'un Pokémon peut tenir
            const commands = item.key ? ['Utiliser', 'Annuler']
                : item.holdable ? ['Utiliser', 'Donner', 'Jeter', 'Annuler'] : ['Utiliser', 'Jeter', 'Annuler'];
            const choice = await this.choose(`${item.name} est sélectionné.`, commands);
            if (choice === null) {
                screen.destroy();
                continue;
            }

            if (commands[choice] === 'Utiliser' && item.ride) {
                // Étape 14 : la Bicyclette, la carte s'en occupe
                screen.destroy();
                return { bike: true };
            }
            // Étape 26 : le Cherche VS (la carte s'en occupe : il faut savoir quels dresseurs sont à l'écran)
            if (commands[choice] === 'Utiliser' && item.effect === 'vs_seeker') {
                screen.destroy();
                return { vsSeeker: true };
            }
            // Étape 19 : Repousse (tout de suite) et Corde Sortie (la carte s'en occupe)
            if (commands[choice] === 'Utiliser' && item.fieldTool && item.effect === 'escape') {
                screen.destroy();
                return { escape: item.id };
            }
            if (commands[choice] === 'Utiliser' && item.fieldTool) {
                const r = await api.post(this.urls.fieldItem, { item_id: item.id, map: this.task.map ?? '' });
                await this.say(r.data.message ?? 'Impossible.');
                if (r.ok) {
                    if (item.effect === 'repel') this.registry.get('audio')?.se('repel');
                    // Étape 22 : Flûte Noire / Blanche, la carte change le taux de rencontres jusqu'à la prochaine carte
                    if (r.data.encounterRate) this.registry.set('encounterRate', r.data.encounterRate);
                    bag = r.data.bag;
                }
                screen.destroy();
                continue;
            }
            if (commands[choice] === 'Utiliser' && item.effect === 'fish') {
                // Étape 15 : une canne à pêche, la carte s'en occupe (il faut être face à l'eau)
                screen.destroy();
                return { fish: item.id };
            }
            if (commands[choice] === 'Donner') {
                const updated = await this.giveFromBag(item);
                if (updated) bag = updated;
            } else if (commands[choice] === 'Utiliser') {
                const updated = await this.useItem(item);
                if (updated) bag = updated;
            } else if (commands[choice] === 'Jeter') {
                const updated = await this.tossItem(item);
                if (updated) bag = updated;
            }
            screen.destroy();
        }
    }

    /** Utiliser un objet sur un Pokémon de l'équipe ; renvoie le nouveau contenu du Sac */
    async useItem(item) {
        if (!item.useField) {
            await this.say('Impossible d\'utiliser cet objet ici.');
            return null;
        }
        if (item.effect === 'teach') return this.useMachine(item);

        const party = await this.fetchParty();
        const screen = new PartyScreen(this, party, { prompt: `Utiliser sur quel Pokémon ?`, mode: 'item', depth: 1450 }); // devant le Sac, resté ouvert derrière
        this.focus = screen;
        const index = await screen.open();
        this.focus = null;
        if (index === null || index === undefined) return null;
        const pokemon = party[index];

        // Huile, PP Plus : sur quelle capacité ?
        let moveSlot = null;
        if (item.needsMove) {
            const question = item.effect === 'pp_up' ? 'Augmenter les PP de quelle capacité ?' : 'Restaurer les PP de quelle capacité ?';
            const choice = await this.choose(question, [...pokemon.moves.map((m) => `${m.name} ${m.pp}/${m.maxPp}`), 'Annuler']);
            if (choice === null) return null;
            moveSlot = pokemon.moves[choice].slot;
        }

        const r = await api.post(this.urls.bagUse, { item_id: item.id, pokemon_id: pokemon.id, move_slot: moveSlot });
        if (!r.ok) {
            await this.say(r.data.message ?? 'Cela n\'aura aucun effet.');
            return null;
        }

        for (const text of r.data.messages ?? []) await this.say(text);
        if (r.data.levelUp) await this.levelStats(r.data.levelUp);
        for (const move of r.data.learn ?? []) await this.learnMove(r.data.pokemon, move);
        if (r.data.evolution) await this.evolve(r.data.evolution);

        return (await api.get(this.urls.bag)).data;
    }

    /** CT / CS : l'écran d'équipe indique APTE / PAS APTE / APPRIS, puis le Pokémon apprend la capacité */
    async useMachine(item) {
        const moveName = item.name.replace(/^C[TS]\d+\s*/, '');
        await this.say(`${item.name.split(' ')[0]} contient ${moveName}.`);
        const result = await api.get(`${this.urls.party}?item_id=${item.id}`);
        const party = result.data.party ?? [];
        await loadImages(this, Object.fromEntries(party.map((p) => [`pkmn_icon_${p.sprites.icon}`, p.sprites.icon])));

        const screen = new PartyScreen(this, party, { prompt: 'Apprendre à quel Pokémon ?', mode: 'item', depth: 1450 }); // devant le Sac, resté ouvert derrière
        this.focus = screen;
        const index = await screen.open();
        this.focus = null;
        if (index === null || index === undefined) return null;
        const pokemon = party[index];

        if (pokemon.able === 'unable') {
            await this.say(`${pokemon.name} ne peut pas apprendre ${moveName}.`);
            return null;
        }
        if (pokemon.able === 'learned') {
            await this.say(`${pokemon.name} connaît déjà ${moveName}.`);
            return null;
        }

        const r = await api.post(this.urls.bagUse, { item_id: item.id, pokemon_id: pokemon.id });
        if (!r.ok) {
            await this.say(r.data.message ?? 'Cela n\'aura aucun effet.');
            return null;
        }
        for (const text of r.data.messages ?? []) await this.say(text);
        for (const move of r.data.learn ?? []) await this.learnMove(r.data.pokemon, move, item.id);

        return (await api.get(this.urls.bag)).data;
    }

    async tossItem(item) {
        const amounts = [1, 5, 10, item.quantity].filter((n, i, all) => n <= item.quantity && all.indexOf(n) === i);
        const choice = await this.choose('Jeter combien ?', [...amounts.map((n) => (n === item.quantity && n > 1 ? `Tout (${n})` : String(n))), 'Annuler']);
        if (choice === null) return null;
        const quantity = amounts[choice];
        if (!(await this.yesNo(`Jeter ${quantity} ${item.name} ?`))) return null;

        const r = await api.post(this.urls.bagToss, { item_id: item.id, quantity });
        if (!r.ok) {
            await this.say(r.data.message ?? 'Impossible.');
            return null;
        }
        await this.say(`Vous jetez ${quantity} ${item.name}.`);
        return r.data.bag;
    }

    /** Fenêtre des stats après une montée de niveau : les gains, puis les totaux */
    async levelStats({ oldStats, stats }) {
        const keys = Object.keys(STAT_LABELS);
        const win = new InfoWindow(this, {
            x: 512 - 236, y: 0, width: 236, height: 6 * 32 + 32,
            lines: keys.map((k) => ({ left: STAT_LABELS[k], right: `+${stats[k] - oldStats[k]}` })),
        }).setDepth(2002);
        await this.waitKey();
        win.setLines(keys.map((k) => ({ left: STAT_LABELS[k], right: String(stats[k]) })));
        await this.waitKey();
        win.destroy();
    }

    waitKey() {
        return new Promise((resolve) => {
            const previous = this.focus;
            this.focus = { clickAnywhere: true, handleKey: (a) => { if (a === 'ok' || a === 'cancel') { this.focus = previous; resolve(); } return true; } };
        });
    }

    // =====================================================================
    // Apprendre une capacité (5e capacité) et évoluer
    // =====================================================================

    async learnMove(pokemon, move, machineId = null) {
        const name = pokemon.name;
        await this.say(`${name} veut apprendre ${move.name}, mais ${name} connaît déjà quatre capacités.`);
        while (true) {
            if (await this.yesNo(`Faut-il oublier une capacité pour apprendre ${move.name} ?`)) {
                const summary = new SummaryScreen(this, [pokemon], 0, { learn: move });
                this.focus = summary;
                const slot = await summary.open();
                this.focus = null;
                // Étape 19 : une CS ne s'oublie que depuis le Résumé (page Capacités)
                if (slot !== null && slot < 4 && pokemon.moves[slot]?.hm) {
                    await this.say('Une CS ne peut pas être oubliée ainsi. Change-la depuis le Résumé du Pokémon (page Capacités).');
                    continue;
                }
                if (slot !== null && slot < 4) {
                    const old = pokemon.moves[slot];
                    const r = await api.post(this.urls.learn, { pokemon_id: pokemon.id, move_id: move.id, forget: old.slot, item_id: machineId });
                    if (!r.ok) {
                        await this.say(r.data.message ?? 'Impossible.');
                        return pokemon;
                    }
                    await this.say('1, 2 et... ... ... Tadaaa !');
                    await this.say(`${name} oublie ${old.name}.\nEt...`);
                    await this.say(`${name} apprend ${move.name} !`);
                    return r.data.pokemon;
                }
            }
            if (await this.yesNo(`Abandonner l'apprentissage de ${move.name} ?`)) {
                await this.say(`${name} n'a pas appris ${move.name}.`);
                return pokemon;
            }
        }
    }

    async evolve(preview) {
        const screen = new EvolutionScreen(this, preview, this.ui);
        const evolved = await screen.run();
        if (evolved) {
            const r = await api.post(this.urls.evolve, { pokemon_id: preview.pokemonId, item_id: preview.itemId });
            if (!r.ok) {
                await this.say(r.data.message ?? 'L\'évolution a échoué.');
            } else {
                this.registry.get('audio')?.me('evolved', { resume: false });
                await this.say(`Félicitations ! Votre ${preview.name} a évolué en ${preview.to.speciesName} !`);
                for (const moveName of r.data.learned ?? []) await this.say(`${r.data.pokemon.name} apprend ${moveName} !`);
                let pokemon = r.data.pokemon;
                for (const move of r.data.learn ?? []) pokemon = await this.learnMove(pokemon, move);
                for (const text of r.data.messages ?? []) await this.say(text);   // étape 23 : Munja
            }
            screen.destroy();
        }
    }

    // =====================================================================
    // Étape 20 : le Casino
    // =====================================================================

    /** Une liste de choix avec un texte à droite (prix) : items = [{ label, right }] ; renvoie l'index ou null */
    async chooseRows(text, items) {
        this.message.setVisible(true);
        await this.message.show(text, { keep: true });
        const width = Math.max(220, ...items.map((i) => Math.ceil(PixelText.measure(i.label) + PixelText.measure(i.right ?? '')) + 80));
        const height = items.length * 32 + 32;
        const win = new ChoiceWindow(this, items, { x: 512 - width, y: Math.max(0, 288 - height), width, cancelValue: items.length - 1 }).setDepth(2001);
        const previous = this.focus;
        this.focus = win;
        const choice = await win.promise;
        this.focus = previous;
        this.message.clear();
        this.message.setVisible(false);
        return choice === null || choice === items.length - 1 ? null : choice;
    }

    /** La petite fenêtre en haut à gauche : argent et jetons (Essentials : \G et \CN) */
    showWallet(money, coins) {
        this.wallet?.destroy(true);
        const box = this.add.container(0, 0).setDepth(2002);
        box.add(createWindow(this, 'ui_choice', 200, 96));
        box.add(new PixelText(this, 184, 14, `${formatMoney(money)} ₽`, { color: '#505058', shadow: '#a0a0a8', align: 'right' }));
        box.add(new PixelText(this, 16, 46, 'Jetons', { color: '#505058', shadow: '#a0a0a8' }));
        box.add(new PixelText(this, 184, 46, formatMoney(coins), { color: '#505058', shadow: '#a0a0a8', align: 'right' }));
        this.wallet = box;
    }

    /**
     * Le guichet : acheter ou revendre des jetons, au nombre près (comme à la Banque).
     * Prix : config/game_corner.php (coin_price, coin_sell_price). Il faut la Boîte Jetons.
     */
    async coinSeller(map) {
        let r = await api.get(this.urls.casino);
        if (!r.ok) return;
        let state = r.data;
        const money = (n) => `${formatMoney(n)} ₽`;
        await this.say('Bienvenue au Casino !');
        if (!state.coinCase) {
            await this.say('Il vous faut une Boîte Jetons pour garder vos jetons.');
            return;
        }
        this.showWallet(state.money, state.coins);
        while (true) {
            const actions = ['Acheter des jetons', ...(state.sellPrice > 0 ? ['Vendre des jetons'] : []), 'Quitter'];
            const choice = await this.choose(`Un jeton coûte ${money(state.price)}.${state.sellPrice > 0 ? `\nNous le rachetons ${money(state.sellPrice)}.` : ''}`, actions);
            const action = actions[choice] ?? 'Quitter';
            if (action === 'Quitter') break;
            const buying = action === 'Acheter des jetons';
            const max = buying ? state.canBuy : state.canSell;
            if (max <= 0) {
                await this.say(buying
                    ? (state.coins >= state.max ? 'Votre Boîte Jetons est pleine !' : 'Vous n\'avez pas assez d\'argent.')
                    : 'Vous n\'avez aucun jeton.');
                continue;
            }
            const amount = await this.chooseAmount(buying ? 'Combien de jetons acheter ?' : 'Combien de jetons vendre ?', max, 'jetons');
            if (!amount) continue;
            const total = amount * (buying ? state.price : state.sellPrice);
            const question = buying
                ? `${formatMoney(amount)} jetons pour ${money(total)}. D'accord ?`
                : `${formatMoney(amount)} jetons contre ${money(total)}. D'accord ?`;
            if (!(await this.yesNo(question))) continue;
            r = await api.post(buying ? this.urls.casinoBuy : this.urls.casinoSell, { map, amount });
            if (!r.ok) {
                await this.say(r.data.message ?? 'Impossible.');
                continue;
            }
            state = { ...state, ...r.data };
            this.registry.get('audio')?.se('buy');
            this.showWallet(state.money, state.coins);
            await this.say(r.data.message);
        }
        await this.say('Bonne chance !');
        this.wallet?.destroy(true);
    }

    /** Un comptoir des lots : échanger des jetons contre des Pokémon, des CT ou des objets */
    async prizeCounter(map, counter) {
        const query = `?map=${encodeURIComponent(map)}&counter=${encodeURIComponent(counter)}`;
        let r = await api.get(this.urls.casinoPrizes + query);
        if (!r.ok) {
            await this.say(r.data.message ?? 'Le comptoir est fermé.');
            return;
        }
        let { prizes, coins } = r.data;
        await this.say('Bienvenue ! Nous échangeons vos jetons contre de superbes lots !');
        if (!r.data.coinCase) {
            await this.say('Il vous faut une Boîte Jetons pour participer.');
            return;
        }
        const money = (await api.get(this.urls.casino)).data.money ?? 0;
        this.showWallet(money, coins);
        while (true) {
            const items = [...prizes.map((p) => ({ label: p.kind === 'pokemon' ? `${p.name} N. ${p.level}` : p.name, right: formatMoney(p.price) })), { label: 'Non merci' }];
            const choice = await this.chooseRows('Quel lot voulez-vous ?', items);
            if (choice === null) break;
            const prize = prizes[choice];
            if (coins < prize.price) {
                await this.say('Vous n\'avez pas assez de jetons.');
                continue;
            }
            if (!(await this.yesNo(`Vous voulez donc ${prize.name} pour ${formatMoney(prize.price)} jetons ?`))) continue;
            r = await api.post(this.urls.casinoExchange, { map, counter, index: prize.index });
            if (!r.ok) {
                await this.say(r.data.message ?? 'Impossible.');
                continue;
            }
            coins = r.data.coins;
            this.showWallet(money, coins);
            this.registry.get('audio')?.me(r.data.me ?? 'item');
            for (const m of r.data.messages ?? []) await this.say(m);
            if (r.data.pokemon) await this.offerNickname(r.data.pokemon);
        }
        await this.say('Revenez quand vous voulez !');
        this.wallet?.destroy(true);
    }

    /** Une machine à sous (Essentials : pbSlotMachine) */
    async slotMachine(map, difficulty) {
        const state = (await api.get(this.urls.casino)).data ?? {};
        if (!state.coinCase) return this.say('C\'est une machine à sous.');
        if (state.coins <= 0) return this.say('Vous n\'avez pas de jetons pour jouer !');
        if (state.coins >= state.max) return this.say('Votre Boîte Jetons est pleine !');
        const r = await api.post(this.urls.slotsOpen, { map, difficulty });
        if (!r.ok) return this.say(r.data.message ?? 'La machine est en panne.');
        this.registry.get('audio')?.se('slots_coin');
        const screen = new SlotMachineScreen(this, r.data, this.urls);
        const promise = screen.open();
        this.focus = screen;
        await promise;
        this.focus = null;
    }

    /** Une table de roulette (script de FL : Roulette.start) */
    async roulette(map, wager, color) {
        const state = (await api.get(this.urls.casino)).data ?? {};
        if (!state.coinCase) return this.say('C\'est une roulette.');
        if (!(await this.yesNo(`La mise minimum à cette table est de ${wager} jeton${wager > 1 ? 's' : ''}. Voulez-vous jouer ?`))) return null;
        if (state.coins < wager) return this.say('Vous n\'avez pas assez de jetons pour jouer !');
        if (state.coins >= state.max) return this.say('Votre Boîte Jetons est pleine !');
        const r = await api.post(this.urls.rouletteOpen, { map, wager });
        if (!r.ok) return this.say(r.data.message ?? 'La table est fermée.');
        const screen = new RouletteScreen(this, r.data, this.urls, color);
        const promise = screen.open();
        this.focus = screen;
        await promise;
        this.focus = null;
    }

    // =====================================================================
    // Étape 19 : surnoms
    // =====================================================================

    /** L'écran de surnom, puis le serveur. Renvoie la réponse ({ pokemon, party }) ou null */
    async rename(pokemon) {
        const name = await new NamingScreen(this, pokemon).open();
        if (name === null) return null;
        const r = await api.post(this.urls.nickname, { pokemon_id: pokemon.id, nickname: name });
        if (!r.ok) {
            await this.say(r.data.message ?? 'Impossible de changer son nom.');
            return null;
        }
        if (r.data.pokemon.nickname) await this.say(`${r.data.pokemon.speciesName} s'appelle maintenant ${r.data.pokemon.nickname} !`);
        return r.data;
    }

    // =====================================================================
    // Étape 28 : la Tour de Combat
    // =====================================================================

    /** Le comptoir : commencer une série, la reprendre, ou (after) la suite d'un combat. Renvoie { towerBattle } ou null. */
    async battleTower(map, after) {
        const urls = this.urls;
        if (after) {
            if (after.ended) {
                await this.say(`Votre série s'arrête à ${after.streak} victoire${after.streak > 1 ? 's' : ''}.`);
                if (after.best) await this.say(`Votre record : ${after.best} victoire${after.best > 1 ? 's' : ''} d'affilée.`);
                await this.say('Merci d\'avoir relevé le défi de la Tour de Combat !');
                return null;
            }
            await this.say(`Félicitations ! Vous avez gagné ${after.streak} combat${after.streak > 1 ? 's' : ''} d'affilée !`);
            if (after.setDone) await this.say('Vous avez remporté une série complète ! Bravo !');
            if (after.levelUp) await this.say(`Les combats montent au niveau ${after.nextLevel} !`);
            if (after.nextTycoon) await this.say('Attention... Le prochain adversaire est le Maître de la Tour en personne !');
            for (;;) {
                const choice = await this.choose(`Combat n° ${after.streak + 1} : que faire ?`, ['Combattre', 'Faire une pause', 'Abandonner'], { cancelIndex: 1 });
                if (choice === 0) return { towerBattle: true };
                if (choice === 1 || choice === null) {
                    await this.say('Votre série est sauvegardée. Revenez quand vous voulez !');
                    return null;
                }
                if (await this.yesNo(`Abandonner ? Votre série de ${after.streak} victoire${after.streak > 1 ? 's' : ''} s'arrêtera.`)) {
                    await api.post(urls.towerQuit, {});
                    await this.say('Votre série est terminée.');
                    return null;
                }
            }
        }

        const r = await api.get(urls.tower);
        if (!r.ok) return null;
        const status = r.data;
        await this.say('Bienvenue à la Tour de Combat ! Les meilleurs dresseurs viennent ici enchaîner les victoires.');
        if (status.run) {
            const n = status.run.streak;
            await this.say(`Vous avez une série en cours : ${n} victoire${n > 1 ? 's' : ''} d'affilée, avec ${status.run.team.map((p) => p.name).join(', ')}.`);
            const choice = await this.choose('Que voulez-vous faire ?', ['Reprendre', 'Abandonner', 'Plus tard']);
            if (choice === 0) return { towerBattle: true };
            if (choice === 1 && await this.yesNo('Abandonner votre série ?')) {
                await api.post(urls.towerQuit, {});
                await this.say('Votre série est terminée.');
            }
            return null;
        }
        if (status.best) await this.say(`Votre record : ${status.best} victoire${status.best > 1 ? 's' : ''} d'affilée.`);
        if (!(await this.yesNo('Voulez-vous relever le défi ?'))) return null;
        const rules = status.rules;
        await this.say(`Choisissez ${rules.size} Pokémon de votre équipe. Les combats commencent au niveau ${rules.startLevel} : vos Pokémon plus forts y sont ramenés le temps du combat.`);
        if (rules.levelStep > 0) await this.say(`Toutes les ${rules.set} victoires, le niveau monte de ${rules.levelStep}, jusqu'au niveau ${rules.maxLevel}. Les adversaires aussi !`);
        await this.say('Pas de Pokémon légendaire, pas deux fois la même espèce ni le même objet tenu. Les objets du Sac sont interdits.');

        // Choisir les Pokémon un par un
        const chosen = [];
        while (chosen.length < rules.size) {
            const party = await this.fetchParty();
            const screen = new PartyScreen(this, party, { prompt: `Pokémon n° ${chosen.length + 1} sur ${rules.size} ?`, mode: 'item' });
            this.focus = screen;
            const index = await screen.open();
            this.focus = null;
            if (index === null || index === undefined) {
                if (chosen.length === 0 || await this.yesNo('Annuler votre choix ?')) return null;
                continue;
            }
            const pokemon = party[index];
            const info = status.eligible.find((p) => p.id === pokemon.id);
            if (pokemon.egg || !info) await this.say('Un Œuf ne peut pas combattre !');
            else if (info.banned) await this.say(`${pokemon.name} ne peut pas participer à la Tour de Combat.`);
            else if (chosen.includes(pokemon.id)) await this.say(`${pokemon.name} est déjà choisi.`);
            else chosen.push(pokemon.id);
        }
        const start = await api.post(urls.towerStart, { map, pokemon_ids: chosen });
        if (!start.ok) {
            await this.say(start.data.message ?? 'Impossible de commencer.');
            return null;
        }
        await this.say('Parfait ! Votre défi commence. Bonne chance !');
        return { towerBattle: true };
    }

    /** Le tableau des meilleures séries (dans le hall) */
    async towerBoard() {
        const r = await api.get(this.urls.towerBoard);
        const records = r.ok ? r.data.records ?? [] : [];
        if (!records.length) {
            await this.say('TOUR DE COMBAT — MEILLEURES SÉRIES\nAucune série pour l\'instant.');
            return;
        }
        await this.say('TOUR DE COMBAT — MEILLEURES SÉRIES');
        for (let i = 0; i < records.length; i += 2) {
            const lines = records.slice(i, i + 2).map((rec, j) => `${i + j + 1}. ${rec.name} : ${rec.streak} victoire${rec.streak > 1 ? 's' : ''}`);
            await this.say(lines.join('\n'));
        }
    }

    // =====================================================================
    // La Banque
    // =====================================================================

    /** Le guichet (banquier) ou le distributeur : déposer, retirer, voir son solde */
    async bank(map, atm) {
        const r = await api.get(`${this.urls.bank}?map=${encodeURIComponent(map)}`);
        if (!r.ok) {
            await this.say(r.data.message ?? 'La banque est fermée...');
            return;
        }
        let account = r.data;
        const money = (n) => `${formatMoney(n)} ₽`;
        await this.say(atm ? 'BANQUE POKÉMON — Distributeur automatique.' : 'Bienvenue à la Banque Pokémon !');
        if (account.earned > 0) {
            await this.say(`Bonne nouvelle : votre épargne vous a rapporté ${money(account.earned)} depuis votre dernière visite !`);
        }
        for (;;) {
            const choice = await this.choose(
                `Solde : ${money(account.balance)}\nSur vous : ${money(account.money)}`,
                ['Déposer', 'Retirer', 'Infos', 'Quitter'],
            );
            if (choice === 0 || choice === 1) {
                const deposit = choice === 0;
                const max = deposit ? account.canDeposit : account.canWithdraw;
                if (max <= 0) {
                    await this.say(deposit
                        ? (account.money <= 0 ? 'Vous n\'avez pas d\'argent sur vous.' : 'Votre compte est plein !')
                        : (account.balance <= 0 ? 'Votre compte est vide.' : 'Votre porte-monnaie est plein !'));
                    continue;
                }
                const amount = await this.chooseAmount(deposit ? 'Combien voulez-vous déposer ?' : 'Combien voulez-vous retirer ?', max);
                if (!amount) continue;
                const result = await api.post(deposit ? this.urls.bankDeposit : this.urls.bankWithdraw, { map, amount });
                if (!result.ok) {
                    await this.say(result.data.message ?? 'Opération impossible.');
                    continue;
                }
                account = result.data;
                this.registry.get('audio')?.se('buy');
                await this.say(deposit ? `Vous déposez ${money(amount)}.` : `Vous retirez ${money(amount)}.`);
                continue;
            }
            if (choice === 2) {
                await this.say(`L'argent déposé rapporte ${String(account.rate).replace('.', ',')} % par jour, même quand vous n'êtes pas là. Les intérêts rapportent aussi des intérêts !`);
                await this.say(`Un compte ne peut pas dépasser ${money(account.maxBalance)}. Une fois plein, il ne rapporte plus rien.`);
                continue;
            }
            break;
        }
        await this.say(atm ? 'Merci de votre visite.' : 'Merci de votre confiance. À bientôt !');
    }

    /**
     * Choisir une somme d'argent (comme Essentials) : un chiffre à la fois.
     * Gauche / droite : changer de chiffre ; haut / bas : +1 / -1 sur ce chiffre. On ne dépasse jamais max.
     * Raccourci : un premier choix "Tout" ou "Autre somme". Renvoie la somme, ou null.
     * unit : ce qu'on compte (₽ par défaut ; 'jetons' au Casino).
     */
    async chooseAmount(text, max, unit = '₽') {
        const quick = await this.choose(text, [`Tout (${formatMoney(max)} ${unit})`, unit === '₽' ? 'Autre somme' : 'Autre nombre', 'Annuler']);
        if (quick === 0) return max;
        if (quick !== 1) return null;
        return new Promise(async (resolve) => {
            this.message.setVisible(true);
            await this.message.show(`${text}\n(max. ${formatMoney(max)} ${unit})`, { keep: true });
            const digits = String(max).length;
            const charW = 18;
            const unitW = Math.ceil(PixelText.measure(unit));
            const width = Math.max(140, digits * charW + 58 + unitW);
            const win = this.add.container(512 - width, 288 - 72).setDepth(2001);
            const frame = createWindow(this, 'ui_choice', width, 72);
            const cells = [];
            win.add(frame);
            const left = width - 22 - unitW - digits * charW;
            for (let i = 0; i < digits; i++) {
                const t = new PixelText(this, left + i * charW, 22, '0', { color: '#505058', shadow: '#a0a0a8' });
                cells.push(t);
                win.add(t);
            }
            win.add(new PixelText(this, width - 16 - unitW, 22, unit, { color: '#505058', shadow: '#a0a0a8' }));
            const arrowUp = this.add.text(0, 5, '▲', { fontSize: '13px', color: '#e05050' });
            const arrowDown = this.add.text(0, 51, '▼', { fontSize: '13px', color: '#e05050' });
            win.add([arrowUp, arrowDown]);
            let value = 0;
            let pos = digits - 1;   // le chiffre des unités
            const draw = () => {
                const text = String(value).padStart(digits, '0');
                cells.forEach((c, i) => c.setText(text[i]));
                const x = left + pos * charW + 3;
                arrowUp.setX(x);
                arrowDown.setX(x);
            };
            draw();
            const previous = this.focus;
            const finish = (v) => {
                win.destroy(true);
                this.message.clear();
                this.message.setVisible(false);
                this.focus = previous;
                resolve(v);
            };
            this.focus = {
                wheel: true,
                handleKey: (a) => {
                    const step = 10 ** (digits - 1 - pos);
                    if (a === 'up') value = value + step > max ? (value === max ? 0 : max) : value + step;
                    if (a === 'down') value = value - step < 0 ? (value === 0 ? max : 0) : value - step;
                    if (a === 'left') pos = Math.max(0, pos - 1);
                    if (a === 'right') pos = Math.min(digits - 1, pos + 1);
                    if (a === 'ok') return finish(value > 0 ? value : null), true;
                    if (a === 'cancel') return finish(null), true;
                    draw();
                    return true;
                },
            };
        });
    }

    /** Étape 27 : le PC du Panthéon. On choisit une entrée (la plus récente d'abord), puis on la revoit. */
    async hallOfFamePc() {
        const r = await api.get(this.config.league?.hallOfFame);
        const entries = r.ok ? r.data.entries ?? [] : [];
        if (!entries.length) {
            await this.say('Aucune donnée au Panthéon.');
            return;
        }
        for (;;) {
            const labels = [...entries.slice(0, 8).map((e) => `N° ${e.number} — ${e.date}`), 'Retour'];
            const choice = await this.choose('Quelle entrée du Panthéon voir ?', labels, { cancelIndex: labels.length - 1 });
            if (choice === null || choice >= labels.length - 1) return;
            await new HallOfFameScreen(this, entries[choice], this.ui).run();
        }
    }

    /** "Donner un surnom à X ?" (après une éclosion) */
    async offerNickname(pokemon) {
        if (!pokemon || !this.urls.nickname) return;
        if (await this.yesNo(`Voulez-vous donner un surnom à ${pokemon.speciesName} ?`)) await this.rename(pokemon);
    }

    // =====================================================================
    // Étape 18 : la Pension
    // =====================================================================

    /**
     * La responsable de la Pension : confier un Pokémon de l'équipe (2 places), le reprendre en payant
     * (niveaux gagnés + 1) x 100 ₽. Le serveur vérifie tout (carte, place, argent).
     */
    async daycare(map, name = '') {
        const url = this.urls.daycare + `?map=${encodeURIComponent(map)}`;
        let r = await api.get(url);
        if (!r.ok) {
            await this.say(r.data.message ?? 'La Pension est fermée.');
            return null;
        }
        let state = r.data;
        await this.say('Bonjour ! Ici la Pension Pokémon.\nNous gardons vos Pokémon pour vous.');

        while (true) {
            const names = state.slots.map((s) => s.name);
            const intro = names.length === 0 ? 'Voulez-vous nous confier un Pokémon ?'
                : names.length === 1 ? `Nous gardons votre ${names[0]}. Que puis-je faire pour vous ?`
                    : `Nous gardons vos ${names[0]} et ${names[1]}.`;
            const commands = [];
            if (state.slots.length < state.max) commands.push('Confier');
            if (state.slots.length > 0) commands.push('Reprendre');
            commands.push('Au revoir');
            const choice = await this.choose(intro, commands, { cancelIndex: commands.length - 1 });
            const command = commands[choice];
            if (choice === null || command === 'Au revoir') break;

            if (command === 'Confier') {
                const party = await this.fetchParty();
                const screen = new PartyScreen(this, party, { prompt: 'Confier quel Pokémon ?', mode: 'item' });
                this.focus = screen;
                const index = await screen.open();
                this.focus = null;
                if (index === null || index === undefined) continue;
                const pokemon = party[index];
                if (pokemon.egg) {
                    await this.say('Nous ne pouvons pas garder un Œuf !');
                    continue;
                }
                r = await api.post(this.urls.daycareDeposit, { map, pokemon_id: pokemon.id });
                if (!r.ok) {
                    await this.say(r.data.message ?? 'Impossible.');
                    continue;
                }
                state = r.data.state;
                await this.say(`${r.data.message}\nRevenez le chercher quand vous voulez.`);
            } else {
                const labels = [...state.slots.map((s) => `${s.name} N. ${s.level}`), 'Annuler'];
                const which = await this.choose('Quel Pokémon voulez-vous reprendre ?', labels);
                if (which === null || which >= state.slots.length) continue;
                const slot = state.slots[which];
                const grew = slot.gained > 0
                    ? `${slot.name} a gagné ${slot.gained} niveau${slot.gained > 1 ? 'x' : ''} depuis son arrivée.\n`
                    : '';
                if (!(await this.yesNo(`${grew}Cela vous coûtera ${formatMoney(slot.price)} ₽. D'accord ?`))) continue;
                r = await api.post(this.urls.daycareWithdraw, { map, pokemon_id: slot.id });
                if (!r.ok) {
                    await this.say(r.data.message ?? 'Impossible.');
                    continue;
                }
                state = r.data.state;
                this.registry.get('audio')?.se('buy');
                await this.say(`Vous récupérez ${r.data.name} !`);
            }
        }
        await this.say('Revenez quand vous voulez !');
        return { breeding: true };
    }

    // =====================================================================
    // Boutique
    // =====================================================================

    async openShop(shop, map, clerk = '') {
        const query = `?map=${encodeURIComponent(map)}&shop=${encodeURIComponent(shop ?? 'default')}`;
        let r = await api.get(this.urls.shop + query);
        if (!r.ok) {
            await this.say(r.data.message ?? 'La Boutique est fermée.');
            return;
        }
        let state = r.data;
        const body = { map, shop: shop ?? 'default' };

        let choice = await this.choose('Bonjour ! Puis-je vous aider ?', ['Acheter', 'Vendre', 'Au revoir'], { cancelIndex: 2 });
        while (choice === 0 || choice === 1) {
            if (choice === 0) state = await this.buyScreen(state, body);
            if (choice === 1) state = await this.sellScreen(state, body);
            choice = await this.choose('Puis-je faire autre chose pour vous ?', ['Acheter', 'Vendre', 'Au revoir'], { cancelIndex: 2 });
        }
        await this.say('Merci de votre visite !\nÀ bientôt !');
    }

    /** Acheter : l'écran de la Boutique reste affiché pendant les questions */
    async buyScreen(state, body) {
        const screen = new MartScreen(this, { stock: state.stock, money: state.money, basePath: this.config.basePath });
        await screen.build();
        while (true) {
            this.focus = screen;
            const item = await screen.choose();
            this.focus = null;
            if (!item) break;

            screen.showDescription(false);
            const max = Math.max(1, Math.min(99, Math.floor(state.money / item.price)));
            if (state.money < item.price) {
                await this.say('Vous n\'avez pas assez d\'argent.');
            } else {
                const quantity = await this.chooseNumber(`${item.name} ? Bien sûr.\nCombien en voulez-vous ?`, max, item.price);
                if (quantity && await this.yesNo(`${quantity} ${item.name}, ça fera ${formatMoney(quantity * item.price)} ₽. D'accord ?`)) {
                    const r = await api.post(this.urls.shopBuy, { ...body, item_id: item.id, quantity });
                    if (!r.ok) {
                        await this.say(r.data.message ?? 'Impossible.');
                    } else {
                        state = r.data;
                        this.registry.get('audio')?.se('buy');
                        screen.update({ stock: state.stock, money: state.money });
                        for (const text of r.data.messages ?? []) await this.say(text);
                    }
                }
            }
            screen.showDescription(true);
        }
        screen.destroy();
        return state;
    }

    /** Vendre : on choisit dans le Sac, la Boutique paie la moitié du prix */
    async sellScreen(state, body) {
        while (true) {
            const bag = new BagScreen(this, state.bag, { mode: 'field', basePath: this.config.basePath, keepOpen: true });
            this.focus = bag;
            const item = await bag.open();
            this.focus = null;
            if (!item) return state;

            const sellPrice = item.sellPrice ?? 0;
            if (item.key || sellPrice <= 0) {
                await this.say(`Oh, non, je ne peux pas acheter ça.`);
                bag.destroy();
                continue;
            }
            const quantity = item.quantity > 1
                ? await this.chooseNumber(`${item.name} ? Combien voulez-vous en vendre ?`, item.quantity, sellPrice)
                : 1;
            if (quantity && await this.yesNo(`Je peux vous en donner ${formatMoney(quantity * sellPrice)} ₽. D'accord ?`)) {
                const r = await api.post(this.urls.shopSell, { ...body, item_id: item.id, quantity });
                if (!r.ok) {
                    await this.say(r.data.message ?? 'Impossible.');
                } else {
                    state = r.data;
                    await this.say(`Vous vendez ${quantity > 1 ? `${quantity} ` : ''}${item.name} et recevez ${formatMoney(r.data.earned)} ₽.`);
                }
            }
            bag.destroy();
        }
    }

    // =====================================================================
    // Tenant du titre
    // =====================================================================

    /** Présentation du tenant ; renvoie { challenge: true } si le joueur accepte le défi */
    async titleHolder(slug, npcName) {
        const r = await api.get(this.urls.title.replace('SLUG', slug));
        if (!r.ok) return null;
        const t = r.data;
        const team = t.team.map((p) => `${p.speciesName} N. ${p.level}`).join(', ');

        if (t.isMe) {
            await this.say(`Vous êtes ${t.class} depuis le ${t.since}.\nDéfis repoussés : ${t.defenses}.`);
            await this.say('Votre équipe défend votre titre pendant votre absence !');
            return null;
        }

        if (t.since) {
            await this.say(`Je suis ${t.name}, ${t.class} depuis le ${t.since} !\nJ'ai repoussé ${t.defenses} défi${t.defenses > 1 ? 's' : ''}.`);
        } else {
            await this.say(`Je suis ${t.name}, ${t.class}.\nPersonne ne m'a encore battu !`);
        }
        await this.say(`Mon équipe : ${team}.`);
        if (t.levelCap) await this.say(`Règle : seuls les Pokémon de niveau ${t.levelCap} ou moins sont autorisés.`);

        if (await this.yesNo('Veux-tu me défier ? Si tu gagnes, le titre est à toi !')) {
            return { challenge: true };
        }
        await this.say('Reviens quand tu te sentiras prêt.');
        return null;
    }

    /** Le tableau d'honneur : les 5 derniers tenants */
    async titleBoard(slug) {
        const r = await api.get(this.urls.title.replace('SLUG', slug));
        if (!r.ok) return;
        const history = r.data.history ?? [];
        if (!history.length) {
            await this.say(`TABLEAU D'HONNEUR\nAucun dresseur n'a encore pris le titre à ${r.data.name}.`);
            return;
        }
        await this.say(`TABLEAU D'HONNEUR\nLes derniers titulaires du titre${r.data.class ? ` de ${r.data.class}` : ''} :`);
        for (const h of history) {
            await this.say(`${h.name}${h.current ? ' (actuel)' : ''}\nTitre pris le ${h.won}, ${h.defenses} défense${h.defenses > 1 ? 's' : ''}.`);
        }
    }

    // =====================================================================
    // Les groupes de joueurs (aussi en commandes de chat : /inviter, /quitter, /g...)
    // =====================================================================

    /** Menu > Groupe : les membres (chef, carte), inviter, gérer un membre (chef), quitter */
    async groupMenu() {
        const u = this.multiUrls;
        while (true) {
            const r = await api.get(u.groupShow);
            if (!r.ok) {
                await this.say(r.data.message ?? 'Impossible.');
                return;
            }
            const { group, candidates, max } = r.data;
            if (!group) {
                const choice = await this.choose(`Vous n'avez pas de groupe.\nInvitez un joueur (${max} au plus) pour en créer un.`, ['Inviter un joueur', 'Annuler']);
                if (choice !== 0) return;
                await this.chooseInvitee(candidates);
                return;
            }

            const me = this.config.character.id;
            const leader = group.leaderId === me;
            const short = (text, n = 14) => (text.length > n ? `${text.slice(0, n - 1)}.` : text);
            const rows = group.members.map((m) => ({ label: `${m.leader ? '★' : ' '}${short(m.name, 12)}`, right: m.online ? short(m.map ?? '?') : 'absent' }));
            const actions = leader ? ['Inviter un joueur', 'Quitter le groupe', 'Retour'] : ['Quitter le groupe', 'Retour'];
            const choice = await this.chooseRows(`Groupe (${group.members.length}/${max}). ★ : le chef.\nChoisissez un membre ou une action.`, [...rows, ...actions.map((label) => ({ label }))]);
            if (choice === null) return;

            if (choice < rows.length) {
                const member = group.members[choice];
                if (!leader || member.id === me) {
                    await this.say(`${member.name}${member.leader ? ' (chef)' : ''}\n${member.online ? `Se trouve : ${member.map}` : 'N\'est pas connecté.'}`);
                    continue;
                }
                const action = await this.choose(`Que faire avec ${member.name} ?`, ['Nommer chef', 'Exclure', 'Annuler']);
                if (action === 0 || action === 1) {
                    const done = await api.post(action === 0 ? u.groupPromote : u.groupKick, { target: member.id });
                    if (!done.ok) await this.say(done.data.message ?? 'Impossible.');
                }
                continue;
            }

            const label = actions[choice - rows.length];
            if (label === 'Inviter un joueur') {
                if (group.members.length >= max) await this.say(`Le groupe est complet (${max} joueurs).`);
                else await this.chooseInvitee(candidates);
            } else if (label === 'Quitter le groupe') {
                if (!(await this.yesNo('Quitter le groupe ?'))) continue;
                const done = await api.post(u.groupLeave, {});
                if (!done.ok) await this.say(done.data.message ?? 'Impossible.');
                return;
            } else {
                return;
            }
        }
    }

    /** La liste des joueurs connectés sans groupe (ceux de ma carte d'abord) */
    async chooseInvitee(candidates) {
        if (!candidates?.length) {
            await this.say('Aucun joueur connecté à inviter pour le moment.\nAstuce : /inviter Pseudo dans le chat.');
            return;
        }
        const short = (text, n = 14) => (text.length > n ? `${text.slice(0, n - 1)}.` : text);
        const choice = await this.chooseRows('Inviter qui ?', [...candidates.map((c) => ({ label: short(c.name, 12), right: short(c.map ?? '') })), { label: 'Annuler' }]);
        if (choice === null) return;
        await this.inviteToGroup(candidates[choice]);
    }

    async inviteToGroup(target) {
        const r = await api.post(this.multiUrls.groupInvite, { target: target.id });
        if (!r.ok) {
            await this.say(r.data.message ?? 'Invitation impossible.');
            return;
        }
        toast(`Vous invitez ${target.name} dans votre groupe. En attente de sa réponse...`);
    }

    /** Un joueur m'invite dans son groupe */
    async groupInvitation(inv) {
        const accept = await this.yesNo(`${inv.from.name} vous invite dans son groupe. Accepter ?`);
        const r = await api.post(this.multiUrls.groupAnswer, { id: inv.id, accept });
        if (!r.ok) await this.say(r.data.message ?? 'Cette invitation n\'est plus valable.');
        return null;
    }

    // =====================================================================
    // Multijoueur : invitations et échanges
    // =====================================================================

    /** J'ai parlé à un autre joueur : Échanger / Combattre */
    async playerMenu(target) {
        const choice = await this.choose(`Que voulez-vous faire avec ${target.name} ?`, ['Échanger', 'Combattre', 'Inviter dans le groupe', 'Annuler']);
        if (choice === null || choice === 3) return;
        if (choice === 2) {
            await this.inviteToGroup(target);
            return;
        }
        const kind = choice === 0 ? 'trade' : 'battle';
        const r = await api.post(this.multiUrls.invite, { target: target.id, kind });
        if (!r.ok) {
            await this.say(r.data.message ?? 'Invitation impossible.');
            return;
        }
        // Message qui se ferme tout seul : le joueur retrouve la carte, la réponse arrivera en direct
        toast(kind === 'trade'
            ? `Vous proposez un échange à ${target.name}. En attente de sa réponse...`
            : `Vous défiez ${target.name} ! En attente de sa réponse...`);
    }

    /** Un joueur m'invite. Renvoie { tradeId } ou { battleId } si j'accepte. */
    async invitation(inv) {
        if (inv.kind === 'group') return this.groupInvitation(inv);
        const text = inv.kind === 'trade'
            ? `${inv.from.name} veut échanger des Pokémon avec vous. Accepter ?`
            : `${inv.from.name} vous défie dans un combat amical ! Accepter ?`;
        const accept = await this.yesNo(text);
        const r = await api.post(this.multiUrls.answer, { id: inv.id, kind: inv.kind, accept });
        if (!r.ok) {
            await this.say(r.data.message ?? 'Cette invitation n\'est plus valable.');
            return null;
        }
        if (!accept) return null;

        return inv.kind === 'trade' ? { tradeId: r.data.tradeId } : { battleId: r.data.battleId };
    }

    /** L'échange : chacun propose un Pokémon, regarde celui de l'autre, puis confirme */
    async trade(tradeId) {
        const url = (key) => this.multiUrls[key].replace('TRADE_ID', tradeId);
        let view = (await api.get(url('trade'))).data;
        if (!view?.id) {
            await this.say('Cet échange n\'existe plus.');
            return;
        }
        const screen = new TradeScreen(this, view);
        await screen.update(view);

        // L'autre joueur a changé quelque chose : on relit l'échange, et on ferme le menu en cours
        // pour afficher la nouvelle situation
        let refreshing = false;
        const refresh = async () => {
            if (refreshing) return;
            refreshing = true;
            const r = await api.get(url('trade'));
            refreshing = false;
            if (!r.ok || !screen.active) return;
            const changed = JSON.stringify(r.data) !== JSON.stringify(view);
            view = r.data;
            if (changed) {
                await screen.update(view);
                this.currentChoice?.close('external');
            }
        };
        const onNotice = (data) => { if (data.tradeId === tradeId) refresh(); };
        this.multi?.on('trade', onNotice);
        // Sans Reverb : on relit l'échange toutes les 3 secondes
        const poll = this.time.addEvent({ delay: 3000, loop: true, callback: () => { if (!this.multi?.connected) refresh(); } });

        while (true) {
            if (view.status === 'completed') {
                await screen.playTrade(view, this.ui);
                if (view.evolution) {
                    const evo = new EvolutionScreen(this, view.evolution, this.ui, { forced: true });
                    await evo.run();
                    this.registry.get('audio')?.me('evolved', { resume: false });
                    await this.say(`Félicitations ! Votre ${view.evolution.name} a évolué en ${view.evolution.to.speciesName} !`);
                    evo.destroy();
                }
                break;
            }
            if (view.status !== 'negotiating') {
                await this.say(`L'échange avec ${view.partner.name} a été annulé.`);
                break;
            }

            const mine = view.mine;
            const theirs = view.theirs;
            const text = !mine.offer ? 'Choisissez un Pokémon à proposer.'
                : !theirs.offer ? `En attente de l'offre de ${view.partner.name}...`
                    : mine.confirmed ? `En attente de la confirmation de ${view.partner.name}...`
                        : `${view.partner.name} propose ${theirs.offer.name}. Échanger ?`;
            const labels = [mine.offer ? 'Changer' : 'Proposer', 'Son Pokémon', 'Confirmer', 'Annuler'];
            const choice = await this.choose(text, labels, { cancelIndex: 3 });
            if (choice === 'external') continue;

            if (choice === 0) {
                const party = view.party ?? [];
                await loadImages(this, Object.fromEntries(party.map((p) => [`pkmn_icon_${p.sprites.icon}`, p.sprites.icon])));
                const picker = new PartyScreen(this, party, { prompt: 'Proposer quel Pokémon ?', mode: 'item', depth: 1450 });
                this.focus = picker;
                const index = await picker.open();
                this.focus = null;
                if (index === null || index === undefined) continue;
                const r = await api.post(url('tradeOffer'), { pokemon_id: party[index].id });
                if (!r.ok) await this.say(r.data.message ?? 'Impossible.');
                else { view = r.data; await screen.update(view); }
            } else if (choice === 1) {
                if (!theirs.offer) {
                    await this.say(`${view.partner.name} n'a encore rien proposé.`);
                    continue;
                }
                await this.showSummary([theirs.offer], 0);
            } else if (choice === 2) {
                if (!mine.offer || !theirs.offer) {
                    await this.say('Les deux dresseurs doivent proposer un Pokémon.');
                    continue;
                }
                const r = await api.post(url('tradeConfirm'), {});
                if (!r.ok) await this.say(r.data.message ?? 'Impossible.');
                else { view = r.data; await screen.update(view); }
            } else if (await this.yesNo('Annuler l\'échange ?')) {
                await api.post(url('tradeCancel'), {});
                await this.say('Vous avez annulé l\'échange.');
                break;
            }
        }

        this.multi?.off('trade', onNotice);
        poll.remove();
        screen.destroy();
    }

    // =====================================================================
    // PC
    // =====================================================================

    async openStorage() {
        const r = await api.get(this.urls.pc);
        if (!r.ok) {
            await this.say(r.data.message ?? 'Le PC ne répond pas.');
            return;
        }
        const screen = new StorageScreen(this, r.data, { urls: this.urls, ui: this.ui });
        this.focus = screen;
        await screen.open();
        this.focus = null;
    }
}
