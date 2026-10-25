/**
 * Clavier + souris pour les écrans de menu et de combat (une seule écoute par scène).
 *
 * La scène doit avoir :
 *   scene.message : une boîte de message (handleKey) ou null
 *   scene.focus   : l'élément d'interface actif (handleKey(action)) ou null
 *
 * Actions : up, down, left, right, ok, cancel.
 * On lit event.key (la lettre ÉCRITE sur la touche) : ZQSD fonctionne en AZERTY.
 */
const KEYS = {
    arrowup: 'up', arrowdown: 'down', arrowleft: 'left', arrowright: 'right',
    z: 'up', s: 'down', q: 'left', d: 'right',
    ' ': 'ok', enter: 'ok', e: 'ok',
    escape: 'cancel', backspace: 'cancel', x: 'cancel',
};

/**
 * Étape 19 : une saisie de texte est en cours (surnom) : les touches servent à écrire,
 * les menus ne doivent pas les lire (sinon "z", "s", "e", "x" ou Espace seraient avalés).
 */
// Étape 29 : touch(action) = ce que font A ('ok') et B ('cancel') de la manette tactile pendant la saisie
export const textEntry = { active: false, touch: null };

// Étape 12 : le petit son d'Essentials pour chaque action dans un menu
const SOUNDS = { up: 'cursor', down: 'cursor', left: 'cursor', right: 'cursor', ok: 'decision', cancel: 'cancel' };

export function attachControls(scene) {
    const dispatch = (action) => {
        if (scene.message?.handleKey?.(action)) return;
        const focus = scene.focus;   // handleKey peut fermer la fenêtre (et remettre scene.focus à null)
        if (!focus) return;
        if (focus.handleKey(action) !== false && !focus.silent) scene.registry.get('audio')?.se(SOUNDS[action]);
    };

    // Écoute directe du navigateur (et non de la file d'attente de Phaser) : chaque appui est traité
    // une seule fois, tout de suite. Retirée quand la scène s'arrête.
    const onKeyDown = (event) => {
        if (textEntry.active) return;
        const action = KEYS[event.key.toLowerCase()];
        if (!action || !scene.sys.isActive()) return;
        event.preventDefault();
        dispatch(action);
    };
    window.addEventListener('keydown', onKeyDown);
    scene.events.once('shutdown', () => window.removeEventListener('keydown', onKeyDown));
    scene.events.once('destroy', () => window.removeEventListener('keydown', onKeyDown));

    // Souris : les boutons gèrent eux-mêmes survol et clic. Ici, ce qui ne vise aucun bouton :
    //   clic gauche dans le vide = Action (fait avancer un message), clic droit = Retour
    scene.input.mouse?.disableContextMenu();
    scene.input.on('pointerdown', (pointer, over) => {
        if (pointer.rightButtonDown()) {
            dispatch('cancel');
            return;
        }
        if (over.some((o) => isShown(o))) return;
        if (scene.message?.handleKey?.('ok')) return;
        if (scene.focus?.clickAnywhere) scene.focus.handleKey('ok');
    });

    // Molette : fait défiler les listes
    scene.input.on('wheel', (pointer, over, dx, dy) => {
        if (scene.focus?.wheel) scene.focus.handleKey(dy > 0 ? 'down' : 'up');
    });

    return dispatch;
}

/** L'objet est-il vraiment affiché (lui ET tous ses conteneurs parents) ? */
export function isShown(object) {
    for (let o = object; o; o = o.parentContainer) {
        if (!o.visible || !o.active) return false;
    }
    return true;
}

/**
 * Rend un objet cliquable : survol -> onHover(), clic gauche -> onClick().
 * enabled() : condition supplémentaire (ex. le menu est-il ouvert ?)
 */
export function clickable(object, { onHover, onClick, enabled = () => true }) {
    object.setInteractive({ useHandCursor: true });
    object.on('pointerover', () => {
        if (enabled() && isShown(object)) onHover?.();
    });
    object.on('pointerdown', (pointer) => {
        if (pointer.leftButtonDown() && enabled() && isShown(object)) onClick?.();
    });
    return object;
}
