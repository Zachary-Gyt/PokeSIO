import PixelText, { COLORS } from '../ui/PixelText.js';
import { createWindow } from '../ui/WindowSkin.js';
import { ChoiceWindow } from './BattleUI.js';

/**
 * L'écran d'équipe d'Essentials (Graphics/UI/Party) : 6 cases, icônes animées, PV, statut.
 *
 *   const index = await new PartyScreen(scene, party, { prompt: 'Choisissez un Pokémon.' }).open();
 *   // index dans l'équipe, ou null si le joueur annule
 *
 * Pour l'instant utilisé en combat (changer de Pokémon, donner une Potion) ;
 * l'étape 7 s'en servira aussi depuis le menu.
 */
const STATUS_ROWS = { sleep: 0, poison: 1, bad_poison: 1, burn: 2, paralysis: 3, freeze: 4 };
const FAINTED_ROW = 5;

export default class PartyScreen extends Phaser.GameObjects.Container {
    /**
     * party   : Pokémon du joueur (données du serveur)
     * options : { prompt, canCancel, confirmLabel, activeIndex, mode ('switch' | 'item'), depth,
     *             commands (liste, ou fonction du Pokémon qui renvoie la liste) }
     *   depth : 1000 par défaut ; 1450 pour passer devant le Sac resté ouvert (menu, Boutique)
     */
    constructor(scene, party, options = {}) {
        super(scene, 0, 0);
        this.party = party;
        this.options = { prompt: 'Choisissez un Pokémon.', canCancel: true, confirmLabel: 'Envoyer', mode: 'switch', ...options };
        this.index = 0;
        this.panels = [];
        this.setDepth(this.options.depth ?? 1000);

        this.add(scene.add.image(0, 0, 'party_bg').setOrigin(0, 0));

        for (let i = 0; i < 6; i++) {
            this.panels.push(this.createPanel(i, party[i]));
        }

        // Bouton Retour, et fenêtre d'aide en bas à gauche (398 x 64, comme Essentials)
        this.cancel = scene.add.image(398, 328, 'party_icon_cancel').setOrigin(0, 0);
        this.cancelText = new PixelText(scene, 398 + 56, 328 + 8, 'RETOUR', { ...COLORS.white, align: 'center' });
        const help = createWindow(scene, 'ui_choice', 398, 64).setPosition(0, 320);
        this.helpText = new PixelText(scene, 20, 320 + 14, '', COLORS.dark);
        this.setHelp(this.options.prompt);
        this.add([this.cancel, this.cancelText, help, this.helpText]);
        if (!this.options.canCancel) {
            this.cancel.setVisible(false);
            this.cancelText.setVisible(false);
        } else {
            this.cancel.setInteractive({ useHandCursor: true });
            this.cancel.on('pointerover', () => {
                if (this.subMenu) return;
                this.index = 6;
                this.refresh();
            });
            this.cancel.on('pointerdown', (pointer) => {
                if (!this.subMenu && pointer.leftButtonDown()) this.close(null);
            });
        }

        // Animation des icônes : 2 images, plus rapide pour le Pokémon sélectionné
        this.iconTimer = scene.time.addEvent({ delay: 100, loop: true, callback: () => this.animateIcons() });
        this.tick = 0;

        scene.add.existing(this);
        this.refresh();
    }

    createPanel(i, p) {
        const scene = this.scene;
        const x = (i % 2) * 256;
        const y = 16 * (i % 2) + 96 * Math.floor(i / 2);
        const panel = scene.add.container(x, y);
        const round = i === 0 ? 'round' : 'rect';

        if (!p) {
            panel.add(scene.add.image(0, 0, 'party_panel_blank').setOrigin(0, 0));
            this.add(panel);
            return { panel, empty: true };
        }

        // Étape 18 : un Œuf n'est jamais "K.O." : pas de PV, de niveau ni de sexe (comme Essentials)
        const fainted = p.hp <= 0 && !p.egg;
        const bg = scene.add.image(0, 0, `party_panel_${round}${fainted ? '_faint' : ''}`).setOrigin(0, 0);
        const ball = scene.add.image(10, 0, 'party_icon_ball').setOrigin(0, 0);
        const iconKey = `pkmn_icon_${p.sprites.icon}`;
        const icon = scene.textures.exists(iconKey)
            ? scene.add.image(60, 40, iconKey).setCrop(0, 0, 64, 64).setOrigin(0.25, 0.5)
            : scene.add.rectangle(60, 40, 1, 1, 0, 0);
        const hpBack = scene.add.image(96, 50, fainted ? 'party_overlay_hp_back_faint' : 'party_overlay_hp_back').setOrigin(0, 0);
        const name = new PixelText(scene, 96, 16, p.name, COLORS.white);
        panel.add([bg, ball, icon, hpBack, name]);

        if (p.egg) {
            hpBack.setVisible(false);
            this.add(panel);
            this.makeClickable(bg, i);
            return { panel, bg, ball, icon, round, fainted: false };
        }

        if (p.gender === 'male' || p.gender === 'female') {
            panel.add(new PixelText(scene, 224, 16, p.gender === 'male' ? '♂' : '♀',
                p.gender === 'male' ? { color: '#0070f8', shadow: '#78b8e8' } : { color: '#e82010', shadow: '#f8a8b8' }));
        }

        panel.add(scene.add.image(20, 70, 'party_overlay_lv').setOrigin(0, 0));
        panel.add(new PixelText(scene, 42, 64, String(p.level), { ...COLORS.white, font: 'small' }));

        // CT : "APTE / PAS APTE / APPRIS" à la place des PV (comme Essentials)
        if (p.able) {
            hpBack.setVisible(false);
            panel.add(new PixelText(scene, 96, 50, { able: 'APTE', unable: 'PAS APTE', learned: 'APPRIS' }[p.able], COLORS.white));
        } else {
            panel.add(new PixelText(scene, 224, 60, `${p.hp}/ ${p.maxHp}`, { ...COLORS.white, align: 'right' }));
        }

        if (!fainted && !p.able) {
            let w = Math.max(1, (96 * p.hp) / p.maxHp);
            w = Math.round(w / 2) * 2;
            const zone = p.hp <= Math.floor(p.maxHp / 4) ? 2 : p.hp <= Math.floor(p.maxHp / 2) ? 1 : 0;
            panel.add(scene.add.image(128, 52, 'party_hp', zone).setOrigin(0, 0).setCrop(0, 0, w, 8));
        }

        // Étape 14 : petite icône quand le Pokémon tient un objet (comme Essentials)
        if (p.item && scene.textures.exists('party_icon_item')) {
            panel.add(scene.add.image(62, 48, 'party_icon_item').setOrigin(0, 0));
        }

        const statusRow = fainted ? FAINTED_ROW : STATUS_ROWS[p.status];
        if (statusRow !== undefined) {
            panel.add(scene.add.image(78, 68, 'party_statuses', statusRow).setOrigin(0, 0));
        }

        this.add(panel);
        this.makeClickable(bg, i);
        return { panel, bg, ball, icon, round, fainted };
    }

    /** Souris : survol = sélection, clic = choisir ce Pokémon */
    makeClickable(bg, i) {
        bg.setInteractive({ useHandCursor: true });
        bg.on('pointerover', () => {
            if (this.subMenu) return;
            this.index = i;
            this.refresh();
        });
        bg.on('pointerdown', (pointer) => {
            if (this.subMenu || !pointer.leftButtonDown()) return;
            this.index = i;
            this.refresh();
            this.handleKey('ok');
        });
    }

    animateIcons() {
        this.tick++;
        this.panels.forEach((p, i) => {
            if (p.empty || !p.icon.setCrop) return;
            // Sélectionné : change d'image toutes les 100 ms, sinon toutes les 200 ms ; un K.O. ne bouge pas
            const speed = i === this.index ? 1 : 2;
            const frame = p.fainted ? 0 : Math.floor(this.tick / speed) % 2;
            p.icon.setCrop(frame * 64, 0, 64, 64);
            p.icon.setOrigin(0.25 + frame * 0.5, 0.5);
        });
    }

    refresh() {
        this.panels.forEach((p, i) => {
            if (p.empty) return;
            const selected = i === this.index;
            if (this.swapFrom !== undefined && this.swapFrom !== null && (i === this.swapFrom || selected)) {
                // Échange en cours : case de départ et case visée en "swap"
                p.bg.setTexture(`party_panel_${p.round}_swap${selected ? '_sel' : ''}`);
            } else {
                p.bg.setTexture(`party_panel_${p.round}${p.fainted ? '_faint' : ''}${selected ? '_sel' : ''}`);
            }
            p.ball.setTexture(selected ? 'party_icon_ball_sel' : 'party_icon_ball');
        });
        this.cancel.setTexture(this.index === 6 ? 'party_icon_cancel_sel' : 'party_icon_cancel');
    }

    /** Texte d'aide en bas ; police étroite s'il est trop long pour passer à côté du bouton RETOUR */
    setHelp(text) {
        const font = PixelText.measure(text) > 360 ? 'narrow' : 'normal';
        if (this.helpText.options.font !== font) {
            this.helpText.options.font = font;
            this.helpText.text = null; // force le redessin
        }
        this.helpText.setText(text);
    }

    open() {
        return new Promise((resolve) => (this.resolve = resolve));
    }

    /** Déplacement façon Essentials : gauche/droite = précédent/suivant, haut/bas = 2 cases */
    handleKey(action) {
        if (this.subMenu) return this.subMenu.handleKey(action);

        const count = this.party.length;
        const last = this.options.canCancel ? 6 : count - 1;
        const order = [...Array(count).keys(), ...(this.options.canCancel ? [6] : [])];
        const pos = order.indexOf(this.index);

        if (action === 'left' || action === 'right') {
            this.index = order[(pos + (action === 'right' ? 1 : order.length - 1)) % order.length];
        } else if (action === 'up' || action === 'down') {
            if (this.index === 6) {
                this.index = action === 'up' ? count - 1 : 0;
            } else {
                const next = this.index + (action === 'down' ? 2 : -2);
                this.index = next >= count ? last : next < 0 ? last : next;
            }
        } else if (action === 'cancel' && this.swapFrom !== undefined && this.swapFrom !== null) {
            this.swapFrom = null; // annule l'échange
            this.setHelp(this.options.prompt);
        } else if (action === 'cancel' && this.options.canCancel) {
            this.close(null);
            return true;
        } else if (action === 'ok') {
            if (this.index === 6) {
                this.close(null);
            } else {
                this.confirm();
            }
            return true;
        }
        this.refresh();
        return true;
    }

    /** Petit menu "Envoyer / Annuler" au-dessus de la fenêtre d'aide */
    async confirm() {
        const p = this.party[this.index];
        if (this.options.mode === 'item') {
            this.close(this.index);
            return;
        }
        // 2e appui pendant un échange : on a choisi la place d'arrivée
        if (this.swapFrom !== undefined && this.swapFrom !== null) {
            const from = this.swapFrom;
            this.swapFrom = null;
            this.close({ index: from, command: 'Échanger', to: this.index });
            return;
        }

        // Menu du Pokémon : par défaut [Envoyer, Annuler] ; options.commands pour en mettre d'autres
        // options.commands peut être une fonction du Pokémon (étape 11 : ses capacités de terrain en plus)
        const commands = typeof this.options.commands === 'function'
            ? this.options.commands(p)
            : this.options.commands ?? [this.options.confirmLabel, 'Annuler'];
        this.setHelp(`Que faire avec ${p.name} ?`);
        const height = commands.length * 32 + 32;
        this.subMenu = new ChoiceWindow(this.scene, commands.map((label) => ({ label })), {
            x: 512 - 192, y: 384 - 64 - height + 64, width: 192, cancelValue: commands.length - 1,
        });
        this.subMenu.setDepth(this.depth + 1);
        const choice = await this.subMenu.promise;
        this.subMenu = null;
        this.setHelp(this.options.prompt);
        const command = commands[choice];
        if (choice === null || command === 'Annuler') return;

        if (command === 'Échanger') {
            this.swapFrom = this.index;
            this.setHelp('Déplacer vers où ?');
            this.refresh();
            return;
        }
        // Sans options.commands (combat) : on renvoie juste l'index ; sinon l'index et la commande
        this.close(this.options.commands ? { index: this.index, command } : this.index);
    }

    /** Message dans la fenêtre d'aide (ex. "Bulbizarre est déjà au combat !") */
    say(text) {
        this.setHelp(text);
    }

    close(value) {
        this.iconTimer.remove();
        this.destroy();
        this.resolve?.(value);
    }
}
