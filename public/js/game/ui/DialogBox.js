/**
 * Boîte de dialogue façon Pokémon (cadre et police d'Essentials), en HTML par-dessus le jeu.
 *
 *   dialog.open(texte, nom, quandFermé) : affiche un texte
 *   dialog.advance()                    : touche Action -> finit d'écrire, page suivante, ou ferme
 *   dialog.isOpen()                     : vrai tant que la boîte est affichée
 *
 * Découpage en pages, comme dans les jeux :
 *   - une LIGNE VIDE dans le texte force une nouvelle page ;
 *   - une page fait au maximum 2 lignes : un texte trop long est découpé AUTOMATIQUEMENT.
 */
const LINES_PER_PAGE = 2;
const LETTER_MS = 25; // vitesse d'écriture : une lettre toutes les 25 ms

export default class DialogBox {
    constructor(root) {
        // <div class="dialog"> <p/> <span class="dialog-next"/> </div>  +  <div class="dialog-name"/>
        this.box = document.createElement('div');
        this.box.className = 'dialog';
        this.box.hidden = true;

        this.textEl = document.createElement('p');

        this.nextEl = document.createElement('span');
        this.nextEl.className = 'dialog-next';

        this.box.append(this.textEl, this.nextEl);

        // Le nom est une petite fenêtre séparée, posée au-dessus de la boîte (comme dans Essentials)
        this.nameEl = document.createElement('div');
        this.nameEl.className = 'dialog-name';
        this.nameEl.hidden = true;

        root.append(this.box, this.nameEl);

        this.pages = [];
        this.timer = null;
        this.onClose = null;
    }

    open(text, name = '', onClose = null) {
        this.nameEl.textContent = name;
        this.nameEl.hidden = !name;
        this.onClose = onClose;

        // La boîte doit être affichée pour pouvoir MESURER le texte (découpage automatique)
        this.box.hidden = false;

        this.pages = String(text ?? '')
            // Police d'Essentials : le caractère "$" y est dessiné comme le symbole Pokédollar
            .replace(/₽/g, '$')
            // Typographie française : espace insécable avant ? ! : ; (le "?" ne part jamais seul à la ligne)
            .replace(/ ([?!:;»])/g, '\u00A0$1')
            .split(/\n\s*\n/)                       // pages forcées par une ligne vide
            .map((part) => part.trim())
            .filter((part) => part !== '')
            .flatMap((part) => this.splitToFit(part)); // puis découpage en pages de 2 lignes

        if (this.pages.length === 0) {
            this.pages = ['...'];
        }

        this.showPage(this.pages.shift());
    }

    isOpen() {
        return !this.box.hidden;
    }

    advance() {
        // 1. Le texte s'écrit encore : on l'affiche en entier d'un coup
        if (this.timer) {
            this.finishPage();
            return;
        }

        // 2. Il reste des pages : page suivante
        if (this.pages.length > 0) {
            this.showPage(this.pages.shift());
            return;
        }

        // 3. Plus rien : on ferme
        this.box.hidden = true;
        this.nameEl.hidden = true;
        const callback = this.onClose;
        this.onClose = null;
        callback?.();
    }

    /**
     * Découpe un texte en pages qui tiennent dans la boîte (LINES_PER_PAGE lignes max).
     * Principe : on ajoute les mots un par un dans la boîte (invisible), et dès que le texte
     * dépasse la hauteur autorisée, on commence une nouvelle page avec le mot qui ne rentrait pas.
     */
    splitToFit(text) {
        const lineHeight = parseFloat(getComputedStyle(this.textEl).lineHeight);
        const maxHeight = lineHeight * LINES_PER_PAGE + 1;

        // Les mots, en gardant les retours à la ligne voulus ("\n") comme des "mots" à part
        const tokens = text.split(/( +|\n)/).filter((t) => t !== '' && t !== ' ');

        const pages = [];
        let current = '';

        this.textEl.style.visibility = 'hidden';
        for (const token of tokens) {
            const separator = current === '' || token === '\n' || current.endsWith('\n') ? '' : ' ';
            const candidate = current + separator + token;

            this.textEl.textContent = candidate;
            if (current !== '' && this.textEl.scrollHeight > maxHeight) {
                pages.push(current.trim());
                current = token === '\n' ? '' : token;
            } else {
                current = candidate;
            }
        }
        if (current.trim() !== '') {
            pages.push(current.trim());
        }
        this.textEl.textContent = '';
        this.textEl.style.visibility = '';

        return pages;
    }

    showPage(text) {
        this.currentText = text;
        this.textEl.textContent = '';
        this.nextEl.style.visibility = 'hidden';

        // Effet machine à écrire
        let shown = 0;
        clearInterval(this.timer);
        this.timer = setInterval(() => {
            shown++;
            this.textEl.textContent = text.slice(0, shown);
            if (shown >= text.length) {
                this.finishPage();
            }
        }, LETTER_MS);
    }

    finishPage() {
        clearInterval(this.timer);
        this.timer = null;
        this.textEl.textContent = this.currentText;
        this.nextEl.style.visibility = 'visible'; // flèche : "appuie pour continuer"
    }
}
