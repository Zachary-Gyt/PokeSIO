import PixelText, { COLORS } from '../ui/PixelText.js';
import { createWindow } from '../ui/WindowSkin.js';

/**
 * Les éléments d'interface du combat, aux positions d'Essentials (écran de 512 x 384).
 * Toutes les images viennent de public/game/battle/ui (copiées depuis Graphics/UI/Battle).
 */

// Ordre des types dans types.png et cursor_fight.png (une ligne par type, comme Essentials)
export const TYPE_ROWS = {
    normal: 0, fighting: 1, flying: 2, poison: 3, ground: 4, rock: 5, bug: 6, ghost: 7, steel: 8,
    unknown: 9, fire: 10, water: 11, grass: 12, electric: 13, psychic: 14, ice: 15, dragon: 16, dark: 17, fairy: 18,
};

// Ligne de chaque statut dans icon_statuses.png
export const STATUS_ROWS = { sleep: 0, poison: 1, burn: 2, paralysis: 3, freeze: 4, bad_poison: 5 };

// Couleurs des menus d'Essentials (TEXT_BASE_COLOR / TEXT_SHADOW_COLOR)
const MENU_TEXT = { color: '#484848', shadow: '#d0d0c8' };

/** Attend qu'un tween soit fini */
export function tweenAsync(scene, config) {
    return new Promise((resolve) => scene.tweens.add({ ...config, onComplete: () => resolve() }));
}

export function wait(scene, ms) {
    return new Promise((resolve) => scene.time.delayedCall(ms, resolve));
}

// =====================================================================
// Boîte de PV ("databox")
// =====================================================================

export class DataBox extends Phaser.GameObjects.Container {
    /**
     * side = 'player' (en bas à droite, avec les PV en chiffres et la barre d'XP)
     *     ou 'foe'    (en haut à gauche)
     */
    constructor(scene, side) {
        const isPlayer = side === 'player';
        // Positions d'Essentials : joueur (Graphics.width - 244, Graphics.height - 192), adversaire (-16, 36)
        super(scene, isPlayer ? 512 - 244 : -16, isPlayer ? 384 - 192 : 36);
        this.side = side;
        this.homeX = this.x;
        const bx = isPlayer ? 34 : 16; // marge gauche du dessin de la boîte ("spriteBaseX")
        this.baseX = bx;

        this.bg = scene.add.image(0, 0, isPlayer ? 'battle_databox' : 'battle_databox_foe').setOrigin(0, 0);
        this.nameText = new PixelText(scene, bx + 8, 10, '', COLORS.databox);
        this.genderText = new PixelText(scene, bx + 126, 10, '', COLORS.male);
        this.lv = scene.add.image(bx + 140, 16, 'battle_lv').setOrigin(0, 0);
        this.levelDigits = scene.add.container(bx + 162, 16);
        this.hpBar = scene.add.image(bx + 102, 40, 'battle_hp', 0).setOrigin(0, 0);
        this.status = scene.add.image(bx + 24, 36, 'battle_statuses', 0).setOrigin(0, 0).setVisible(false);
        this.shiny = scene.add.image(bx + (isPlayer ? -6 : 206), 36, 'battle_shiny').setOrigin(0, 0).setVisible(false);
        this.add([this.bg, this.nameText, this.genderText, this.lv, this.levelDigits, this.hpBar, this.status, this.shiny]);

        if (isPlayer) {
            this.hpDigits = scene.add.container(bx + 80, 52);
            this.expBar = scene.add.image(bx + 6, 74, 'battle_exp').setOrigin(0, 0);
            this.add([this.hpDigits, this.expBar]);
        } else {
            this.owned = scene.add.image(bx + 8, 36, 'battle_own').setOrigin(0, 0).setVisible(false);
            this.add(this.owned);
        }

        this.ratio = 1;
        this.hp = 0;
        this.maxHp = 1;
        scene.add.existing(this);
    }

    /** Affiche un Pokémon (données envoyées par le serveur) */
    setBattler(b) {
        this.battler = b;
        this.nameText.setText(b.name);
        // Nom trop long : on le décale vers la gauche (comme Essentials)
        this.nameText.x = this.baseX + 8 - Math.max(0, this.nameText.textWidth - 116);

        if (b.gender === 'male' || b.gender === 'female') {
            this.genderText.setColors(COLORS[b.gender].color, COLORS[b.gender].shadow);
            this.genderText.setText(b.gender === 'male' ? '♂' : '♀').setVisible(true);
        } else {
            this.genderText.setVisible(false);
        }

        this.setLevel(b.level);
        this.setStatus(b.status);
        this.shiny.setVisible(!!b.shiny);
        if (this.owned) this.owned.setVisible(!!b.owned);

        if (this.side === 'player') {
            this.setHp(b.hp, b.maxHp);
            this.setExp(b.exp, b.levelExp, b.nextLevelExp);
        } else {
            this.setRatio(b.ratio);
        }
    }

    setLevel(level) {
        this.level = level;
        drawNumber(this.scene, this.levelDigits, level, 'left');
    }

    setStatus(status) {
        const row = STATUS_ROWS[status];
        this.status.setVisible(row !== undefined);
        if (row !== undefined) this.status.setFrame(row);
    }

    // ---- PV ----

    setHp(hp, maxHp) {
        this.hp = hp;
        this.maxHp = maxHp;
        this.setRatio(maxHp > 0 ? hp / maxHp : 0);
        drawHpNumbers(this.scene, this.hpDigits, hp, maxHp);
    }

    setRatio(ratio) {
        this.ratio = ratio;
        // Largeur arrondie aux 2 pixels près (les graphismes sont "doublés"), au moins 1 pixel si vivant
        let w = ratio > 0 ? Math.max(1, 96 * ratio) : 0;
        w = Math.round(w / 2) * 2;
        this.hpBar.setFrame(ratio <= 0.25 ? 2 : ratio <= 0.5 ? 1 : 0);
        this.hpBar.setCrop(0, 0, w, 6);
        this.hpBar.setVisible(w > 0);
    }

    /** La barre de PV descend (ou monte) doucement jusqu'à la nouvelle valeur */
    animateHp(target, maxHp = this.maxHp) {
        const isPlayer = this.side === 'player';
        const from = isPlayer ? this.hp : this.ratio;
        const to = isPlayer ? target : target; // joueur : PV ; adversaire : proportion
        if (from === to) return Promise.resolve();

        // Durée selon l'écart (comme Essentials : environ 1 seconde pour une barre entière)
        const fraction = isPlayer ? Math.abs(to - from) / Math.max(1, maxHp) : Math.abs(to - from);
        const counter = { v: from };

        return tweenAsync(this.scene, {
            targets: counter,
            v: to,
            duration: Math.max(250, 1000 * fraction),
            onUpdate: () => {
                if (isPlayer) this.setHp(Math.round(counter.v), maxHp);
                else this.setRatio(counter.v);
            },
        }).then(() => (isPlayer ? this.setHp(to, maxHp) : this.setRatio(to)));
    }

    // ---- XP (joueur seulement) ----

    setExp(exp, levelExp, nextLevelExp) {
        if (!this.expBar) return;
        this.exp = { exp, levelExp, nextLevelExp };
        const span = Math.max(1, nextLevelExp - levelExp);
        const fraction = Math.min(1, Math.max(0, (exp - levelExp) / span));
        const w = Math.round((192 * fraction) / 2) * 2;
        this.expBar.setCrop(0, 0, w, 4);
    }

    animateExp(exp, levelExp, nextLevelExp) {
        if (!this.expBar) return Promise.resolve();
        const start = this.exp && this.exp.levelExp === levelExp ? this.exp.exp : levelExp;
        const counter = { v: start };
        const span = Math.max(1, nextLevelExp - levelExp);
        return tweenAsync(this.scene, {
            targets: counter,
            v: exp,
            duration: Math.max(200, 1200 * ((exp - start) / span)),
            onUpdate: () => this.setExp(counter.v, levelExp, nextLevelExp),
        }).then(() => this.setExp(exp, levelExp, nextLevelExp));
    }

    /** Arrivée en glissant depuis le bord de l'écran */
    slideIn(duration = 300) {
        this.setVisible(true);
        this.x = this.side === 'player' ? 512 : -260;
        return tweenAsync(this.scene, { targets: this, x: this.homeX, duration });
    }

    slideOut(duration = 200) {
        return tweenAsync(this.scene, { targets: this, x: this.side === 'player' ? 512 : -260, duration })
            .then(() => this.setVisible(false));
    }
}

/** Chiffres de icon_numbers.png (11 images de 16 x 14 : 0 à 9 puis "/") */
function drawNumber(scene, container, number, align) {
    container.removeAll(true);
    const digits = String(number).split('').map(Number);
    const startX = align === 'right' ? -16 * digits.length : 0;
    digits.forEach((d, i) => container.add(scene.add.image(startX + i * 16, 0, 'battle_numbers', d).setOrigin(0, 0)));
}

/** "PV / PV max" : comme Essentials, nombre aligné à droite en x=54, "/" en 54, maximum en 70 */
function drawHpNumbers(scene, container, hp, maxHp) {
    if (!container) return;
    container.removeAll(true);
    const add = (x, frame) => container.add(scene.add.image(x, 2, 'battle_numbers', frame).setOrigin(0, 0));
    String(hp).split('').reverse().forEach((d, i) => add(54 - 16 * (i + 1), Number(d)));
    add(54, 10);
    String(maxHp).split('').forEach((d, i) => add(70 + 16 * i, Number(d)));
}

// =====================================================================
// Boîte de message (bas de l'écran)
// =====================================================================

export class MessageBox extends Phaser.GameObjects.Container {
    /**
     * background : 'battle_overlay_message' (combat) ou 'ui_dialog' (menus : le cadre des dialogues du jeu)
     */
    constructor(scene, { background = 'battle_overlay_message' } = {}) {
        super(scene, 0, 288);
        this.bg = scene.add.image(0, 0, background).setOrigin(0, 0);
        const textX = background === 'ui_dialog' ? 28 : 32;
        this.text = new PixelText(scene, textX, 16, '', { color: '#505058', shadow: '#a0a0a8' });
        this.arrow = scene.add.sprite(480, 70, 'battle_pause_arrow', 0).setOrigin(0, 0).setVisible(false);
        this.add([this.bg, this.text, this.arrow]);
        scene.add.existing(this);

        if (!scene.anims.exists('battle_pause_arrow')) {
            scene.anims.create({ key: 'battle_pause_arrow', frames: scene.anims.generateFrameNumbers('battle_pause_arrow', { frames: [0, 1, 2, 3] }), frameRate: 6.25, repeat: -1 });
        }
        this.resolve = null;
    }

    /** Coupe un texte en lignes de 448 pixels maximum */
    static wrap(text, width = 448) {
        // Espace insécable avant ! ? : ; (typographie française) : jamais de "!" seul en début de ligne
        text = text.replace(/ ([!?:;»])/g, '\u00a0$1');
        const lines = [];
        for (const paragraph of text.split('\n')) {
            let line = '';
            for (const word of paragraph.split(' ')) {
                const attempt = line ? `${line} ${word}` : word;
                if (PixelText.measure(attempt) > width && line) {
                    lines.push(line);
                    line = word;
                } else {
                    line = attempt;
                }
            }
            lines.push(line);
        }
        return lines;
    }

    /**
     * Affiche un message lettre par lettre, 2 lignes à la fois.
     * pause = true : attend une touche (flèche affichée). Sinon : se ferme seul après 1 seconde,
     * ou plus tôt si le joueur appuie (comme Essentials).
     */
    show(text, { pause = false, keep = false } = {}) {
        const lines = MessageBox.wrap(text);
        const pages = [];
        for (let i = 0; i < lines.length; i += 2) pages.push(lines.slice(i, i + 2).join('\n'));

        this.setVisible(true);
        // Un message encore en cours (ex. "En attente de..." en combat entre joueurs) : on l'arrête,
        // sinon son minuteur continuerait d'écrire par-dessus le nouveau message
        this.timer?.remove();
        this.autoClose?.remove();
        return new Promise((resolve) => {
            let page = 0;
            const showPage = () => {
                const full = pages[page];
                const last = page === pages.length - 1;
                let shown = 0;
                let timer = null;
                this.arrow.setVisible(false).stop();
                this.typing = true;

                const finishTyping = () => {
                    this.typing = false;
                    timer?.remove();
                    this.text.setText(full);
                    if (!last || pause) {
                        this.arrow.setVisible(true).play('battle_pause_arrow');
                        this.onKey = () => next();
                    } else if (keep) {
                        this.onKey = null;
                        resolve();
                    } else {
                        this.onKey = () => next();
                        this.autoClose = this.scene.time.delayedCall(1000, () => next());
                    }
                };

                const next = () => {
                    this.autoClose?.remove();
                    this.onKey = null;
                    if (!last) {
                        page++;
                        showPage();
                    } else {
                        this.arrow.setVisible(false).stop();
                        resolve();
                    }
                };

                this.onKey = () => finishTyping();
                timer = this.timer = this.scene.time.addEvent({
                    delay: 16,
                    loop: true,
                    callback: () => {
                        shown += 1;
                        this.text.setText(full.slice(0, shown));
                        if (shown >= full.length) finishTyping();
                    },
                });
            };
            showPage();
        });
    }

    clear() {
        this.timer?.remove();
        this.autoClose?.remove();
        this.text.setText('');
        this.arrow.setVisible(false).stop();
    }

    /** Touche Action ou Retour pendant un message */
    handleKey(action) {
        if ((action === 'ok' || action === 'cancel') && this.onKey) {
            this.onKey();
            return true;
        }
        return false;
    }
}

// =====================================================================
// Menu principal : ATTAQUE / SAC / POKéMON / FUITE
// =====================================================================

// Boutons de cursor_command.png (une ligne de 46 px chacun)
// Étape 15 : BALL (5), ROCK (6), BAIT (7) pour le Parc Safari
const COMMAND_ROWS = { fight: 0, pokemon: 1, bag: 2, run: 3, ball: 5, rock: 6, bait: 7 };
// Disposition d'Essentials : Attaque en haut à gauche, Sac en haut à droite, Pokémon en bas à gauche, Fuite en bas à droite
const COMMAND_LAYOUT = ['fight', 'bag', 'pokemon', 'run'];
// Étape 15 : au Parc Safari, Ball / Appât en haut, Caillou / Fuite en bas (comme Essentials)
export const SAFARI_LAYOUT = ['ball', 'bait', 'rock', 'run'];

export class CommandMenu extends Phaser.GameObjects.Container {
    constructor(scene) {
        super(scene, 0, 288);
        this.bg = scene.add.image(0, 0, 'battle_overlay_command').setOrigin(0, 0);
        this.prompt = new PixelText(scene, 32, 16, '', MENU_TEXT);
        this.add([this.bg, this.prompt]);

        // Position des boutons d'Essentials : x = 512 - 260 (+ 126), y = 6 (+ 42)
        this.layout = COMMAND_LAYOUT;
        this.buttons = COMMAND_LAYOUT.map((command, i) => {
            const x = 512 - 260 + (i % 2 === 0 ? 0 : 126);
            const y = 6 + (i < 2 ? 0 : 42);
            const button = scene.add.image(x, y, 'battle_cursor_command', `c${COMMAND_ROWS[command]}_0`).setOrigin(0, 0);
            button.command = command;
            this.add(button);
            // Souris : survol = sélection, clic = validation
            button.setInteractive({ useHandCursor: true });
            button.on('pointerover', () => {
                if (!this.visible) return;
                this.index = i;
                this.refresh();
            });
            button.on('pointerdown', (pointer) => {
                if (!this.visible || !pointer.leftButtonDown()) return;
                this.index = i;
                this.handleKey('ok');
            });
            return button;
        });
        this.index = 0;
        this.setVisible(false);
        scene.add.existing(this);
    }

    /**
     * pokemonName : "Que doit faire Pikachu ?"
     * options.layout / options.prompt : étape 15, menu du Parc Safari (SAFARI_LAYOUT, "Que doit lancer...")
     */
    open(pokemonName, index = this.index, { layout = COMMAND_LAYOUT, prompt = null } = {}) {
        this.prompt.setText(prompt ?? `Que doit faire\n${pokemonName} ?`);
        if (layout !== this.layout) {
            this.layout = layout;
            this.buttons.forEach((b, i) => (b.command = layout[i]));
        }
        this.index = index;
        this.refresh();
        this.setVisible(true);
        return new Promise((resolve) => (this.resolve = resolve));
    }

    refresh() {
        // Le bouton sélectionné utilise la colonne de droite (plus claire) de cursor_command.png
        this.buttons.forEach((b, i) => b.setFrame(`c${COMMAND_ROWS[b.command]}_${i === this.index ? 1 : 0}`));
        // Le bouton sélectionné passe devant ses voisins (ils se chevauchent de 4 pixels)
        this.bringToTop(this.buttons[this.index]);
    }

    handleKey(action) {
        const moves = { left: -1, right: 1, up: -2, down: 2 };
        if (moves[action] !== undefined) {
            const next = this.index + moves[action];
            if (next >= 0 && next < 4 && !(action === 'left' && this.index % 2 === 0) && !(action === 'right' && this.index % 2 === 1)) {
                this.index = next;
                this.refresh();
            }
        } else if (action === 'ok') {
            this.setVisible(false);
            this.resolve?.(this.layout[this.index]);
        }
        return true;
    }
}

// =====================================================================
// Menu des attaques
// =====================================================================

export class FightMenu extends Phaser.GameObjects.Container {
    constructor(scene) {
        super(scene, 0, 288);
        this.bg = scene.add.image(0, 0, 'battle_overlay_fight').setOrigin(0, 0);
        this.typeIcon = scene.add.image(416, 20, 'battle_types', 't0').setOrigin(0, 0);
        this.ppText = new PixelText(scene, 448, 44, '', { ...MENU_TEXT, font: 'narrow', align: 'center' });
        this.add([this.bg, this.typeIcon, this.ppText]);
        this.buttons = [];
        this.index = 0;
        this.setVisible(false);
        scene.add.existing(this);
    }

    open(battler, index = 0) {
        this.moves = battler.moves;
        this.index = Math.min(index, this.moves.length - 1);
        this.buttons.forEach(({ button, name }) => { button.destroy(); name.destroy(); });
        this.buttons = [];

        this.moves.forEach((move, i) => {
            const row = TYPE_ROWS[move.type] ?? 0;
            const x = 4 + (i % 2 === 0 ? 0 : 188);
            const y = 6 + (i < 2 ? 0 : 42);
            const button = this.scene.add.image(x, y, 'battle_cursor_fight', `f${row}_0`).setOrigin(0, 0);
            button.row = row;
            // Couleur du nom : le pixel (10, 34) du bouton, comme Essentials
            const pixel = this.scene.textures.getPixel(10, row * 46 + 34, 'battle_cursor_fight');
            const color = pixel ? `#${pixel.color.toString(16).padStart(6, '0')}` : MENU_TEXT.color;
            const name = new PixelText(this.scene, x + 96, y + 8, move.name, { color, shadow: MENU_TEXT.shadow, font: 'narrow', align: 'center' });
            this.add([button, name]);
            this.buttons.push({ button, name });
            button.setInteractive({ useHandCursor: true });
            button.on('pointerover', () => {
                if (!this.visible) return;
                this.index = i;
                this.refresh();
            });
            button.on('pointerdown', (pointer) => {
                if (!this.visible || !pointer.leftButtonDown()) return;
                this.index = i;
                this.handleKey('ok');
            });
        });
        this.refresh();
        this.setVisible(true);
        return new Promise((resolve) => (this.resolve = resolve));
    }

    refresh() {
        this.buttons.forEach(({ button, name }, i) => {
            button.setFrame(`f${button.row}_${i === this.index ? 1 : 0}`);
            if (i === this.index) {
                this.bringToTop(button);
                this.bringToTop(name);
            }
        });

        const move = this.moves[this.index];
        this.typeIcon.setFrame(`t${TYPE_ROWS[move.type] ?? 0}`);

        // Couleur des PP selon ce qu'il reste (rouge à 0, orange à 1/4, jaune à 1/2)
        const fraction = move.maxPp > 0 ? Math.min(Math.ceil((4 * move.pp) / move.maxPp), 3) : 3;
        const colors = [['#f84848', '#883030'], ['#f88820', '#904818'], ['#f8c000', '#906800'], [MENU_TEXT.color, MENU_TEXT.shadow]];
        this.ppText.setColors(...colors[fraction]);
        this.ppText.setText(`PP: ${move.pp}/${move.maxPp}`);
    }

    handleKey(action) {
        const moves = { left: -1, right: 1, up: -2, down: 2 };
        if (moves[action] !== undefined) {
            const next = this.index + moves[action];
            if (next >= 0 && next < this.moves.length && !(action === 'left' && this.index % 2 === 0) && !(action === 'right' && this.index % 2 === 1)) {
                this.index = next;
                this.refresh();
            }
        } else if (action === 'ok') {
            this.setVisible(false);
            this.resolve?.(this.index);
        } else if (action === 'cancel') {
            this.setVisible(false);
            this.resolve?.(null);
        }
        return true;
    }
}

// =====================================================================
// Fenêtre de choix (Oui / Non, liste d'objets, attaques à oublier...)
// =====================================================================

export class ChoiceWindow extends Phaser.GameObjects.Container {
    /**
     * items : [{ label, right? (texte aligné à droite), disabled? }]
     * options : { x, y, width, cancelValue (null = Retour interdit), rowHeight }
     */
    constructor(scene, items, { x, y, width = 160, cancelValue = null, allowCancel = true, rowHeight = 32, maxRows = 8, onMove = null } = {}) {
        super(scene, x, y);
        this.items = items;
        this.rowHeight = rowHeight;
        this.maxRows = Math.min(maxRows, items.length);
        this.allowCancel = allowCancel;
        this.cancelValue = cancelValue;
        this.onMove = onMove;
        const height = this.maxRows * rowHeight + 32;

        this.frame = createWindow(scene, 'ui_choice', width, height);
        this.arrow = scene.add.image(14, 0, 'ui_sel_arrow').setOrigin(0, 0);
        this.add([this.frame, this.arrow]);

        // Souris : une zone invisible par ligne affichée (survol = sélection, clic = validation)
        this.wheel = items.length > this.maxRows; // la molette fait défiler les longues listes
        for (let r = 0; r < this.maxRows; r++) {
            const zone = scene.add.zone(8, 12 + r * rowHeight - 2, width - 16, rowHeight).setOrigin(0, 0)
                .setInteractive({ useHandCursor: true });
            zone.on('pointerover', () => {
                if (!this.items[this.top + r]) return;
                this.index = this.top + r;
                this.drawRows();
                this.onMove?.(this.index);
            });
            zone.on('pointerdown', (pointer) => {
                if (!pointer.leftButtonDown() || !this.items[this.top + r]) return;
                this.index = this.top + r;
                this.close(this.index);
            });
            this.add(zone);
        }
        this.rows = [];
        this.width = width;
        this.top = 0;
        this.index = 0;
        this.drawRows();
        scene.add.existing(this);
        this.promise = new Promise((resolve) => (this.resolve = resolve));
        this.onMove?.(this.index);
    }

    drawRows() {
        this.rows.forEach((r) => r.destroy());
        this.rows = [];
        for (let r = 0; r < this.maxRows; r++) {
            const item = this.items[this.top + r];
            if (!item) break;
            const y = 12 + r * this.rowHeight;
            const color = item.disabled ? { color: '#a8a8a8', shadow: '#d8d8d8' } : { color: '#505058', shadow: '#a0a0a8' };
            const label = new PixelText(this.scene, 32, y, item.label, color);
            this.add(label);
            this.rows.push(label);
            if (item.right) {
                const right = new PixelText(this.scene, this.width - 20, y, item.right, { ...color, align: 'right' });
                this.add(right);
                this.rows.push(right);
            }
        }
        this.arrow.y = 12 + (this.index - this.top) * this.rowHeight + 2;
    }

    handleKey(action) {
        if (action === 'up' || action === 'down') {
            const n = this.items.length;
            this.index = (this.index + (action === 'up' ? n - 1 : 1)) % n;
            if (this.index < this.top) this.top = this.index;
            if (this.index >= this.top + this.maxRows) this.top = this.index - this.maxRows + 1;
            this.drawRows();
            this.onMove?.(this.index);
        } else if (action === 'ok') {
            this.close(this.index);
        } else if (action === 'cancel' && this.allowCancel) {
            this.close(this.cancelValue);
        }
        return true;
    }

    close(value) {
        this.destroy();
        this.resolve(value);
    }
}

/** Fenêtre de texte simple (stats de la montée de niveau, description d'objet...) */
export class InfoWindow extends Phaser.GameObjects.Container {
    constructor(scene, { x, y, width, height, lines }) {
        super(scene, x, y);
        this.frame = createWindow(scene, 'ui_choice', width, height);
        this.add(this.frame);
        this.texts = [];
        this.width = width;
        this.setLines(lines);
        scene.add.existing(this);
    }

    /** lines : [{ left, right }] ou des chaînes */
    setLines(lines) {
        this.texts.forEach((t) => t.destroy());
        this.texts = [];
        lines.forEach((line, i) => {
            const y = 12 + i * 32;
            const left = typeof line === 'string' ? line : line.left;
            const l = new PixelText(this.scene, 20, y, left, { color: '#505058', shadow: '#a0a0a8' });
            this.add(l);
            this.texts.push(l);
            if (line.right !== undefined) {
                const r = new PixelText(this.scene, this.width - 20, y, line.right, { color: '#505058', shadow: '#a0a0a8', align: 'right' });
                this.add(r);
                this.texts.push(r);
            }
        });
    }
}

// =====================================================================
// Étape 15 : la boîte du Parc Safari (databox_safari.png), à la place de celle du Pokémon du joueur
// =====================================================================

export class SafariBox extends Phaser.GameObjects.Container {
    constructor(scene) {
        // Position d'Essentials (SafariDataBox) : Graphics.width - 232, Graphics.height - 184
        super(scene, 512 - 232, 384 - 184);
        const colors = { color: '#484848', shadow: '#b8b8b8' };
        this.add(scene.add.image(0, 0, 'battle_databox_safari').setOrigin(0, 0));
        this.add(new PixelText(scene, 30, 8, 'Safari Balls', colors));
        this.left = new PixelText(scene, 30, 38, '', colors);
        this.add(this.left);
        this.homeX = this.x;
        this.setVisible(false);
        scene.add.existing(this);
    }

    setBalls(n) {
        this.left.setText(`Reste : ${n}`);
        return this;
    }

    async slideIn() {
        this.setVisible(true);
        this.x = 512;
        await tweenAsync(this.scene, { targets: this, x: this.homeX, duration: 300 });
    }
}
