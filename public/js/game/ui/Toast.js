/**
 * Message d'information qui ne bloque pas le jeu ("Vous proposez un échange...", "X vous a pris le titre !"),
 * avec le MÊME cadre que les boîtes de dialogue (classe .dialog), mais en HAUT de l'écran,
 * et qui disparaît tout seul.
 *
 *   toast('Ondine a refusé votre invitation.');
 */
export function toast(text, seconds = 4) {
    const ui = document.getElementById('game-ui');
    if (!ui) return;
    let box = document.getElementById('game-toast');
    if (!box) {
        box = document.createElement('div');
        box.id = 'game-toast';
        box.className = 'dialog game-toast';
        box.appendChild(document.createElement('p'));
        ui.appendChild(box);
    }
    box.firstChild.textContent = text;
    box.hidden = false;
    clearTimeout(box.hideTimer);
    box.hideTimer = setTimeout(() => (box.hidden = true), seconds * 1000);
}
