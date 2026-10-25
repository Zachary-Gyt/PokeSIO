/**
 * Texte "pixel" net, dessiné DANS le canvas du jeu (comme dans Essentials), avec son ombre.
 *
 * Le navigateur dessine toujours le texte avec un léger flou sur les bords (anticrénelage).
 * Pour retrouver des pixels nets, on dessine le texte en blanc dans un petit canvas, puis on
 * garde chaque pixel "plutôt plein" et on jette les "plutôt vides" (seuil). On colore ensuite
 * le texte et son ombre, décalée de 2 pixels à droite et en bas, comme Essentials.
 *
 *   const name = new PixelText(scene, 40, 12, 'Bulbizarre', { color: '#484848', shadow: '#b8b8b8' });
 *   name.setText('Herbizarre');
 */

// Couleurs d'Essentials
export const COLORS = {
    dark: { color: '#505058', shadow: '#a0a0a8' },     // messages, menus
    databox: { color: '#484848', shadow: '#b8b8b8' },  // nom dans la boîte de PV
    white: { color: '#f8f8f8', shadow: '#282828' },    // texte clair (écran d'équipe)
    male: { color: '#3060d8', shadow: '#b8b8b8' },
    female: { color: '#f85828', shadow: '#b8b8b8' },
};

// baseline : distance (en pixels) entre le haut de la ligne et la ligne de base des lettres.
// Les polices Power Green ont des mesures verticales mal remplies (descente positive) : avec
// textBaseline = 'top', chaque système (Windows, Linux, macOS) plaçait le texte à une hauteur
// différente. On écrit donc sur la ligne de base des lettres, qui est la même partout.
const FONTS = {
    normal: { family: 'Power Green', size: 27, baseline: 22 },
    small: { family: 'Power Green Small', size: 21, baseline: 18 },
    narrow: { family: 'Power Green Narrow', size: 27, baseline: 22 },
    // Noms au-dessus des joueurs : Power Green (pas Small : à cette taille, ses "ill" se collent), ombre de 1 pixel
    tiny: { family: 'Power Green', size: 16, threshold: 60, baseline: 13 },
};

let counter = 0;

export default class PixelText extends Phaser.GameObjects.Image {
    /**
     * options : { color, shadow, font ('normal' | 'small' | 'narrow'), align ('left' | 'center' | 'right'), lineHeight }
     * (x, y) = coin haut-gauche de la ligne (ou haut-milieu / haut-droit selon align)
     */
    constructor(scene, x, y, text = '', options = {}) {
        const key = `__pixeltext_${counter++}`;
        const texture = scene.textures.createCanvas(key, 2, 2);
        super(scene, x, y, key);

        this.canvasTexture = texture;
        this.options = { color: COLORS.dark.color, shadow: COLORS.dark.shadow, font: 'normal', align: 'left', lineHeight: 32, ...options };
        this.text = null;

        scene.add.existing(this);
        this.setText(text);

        // Le canvas est libéré avec l'objet
        this.once('destroy', () => scene.textures.remove(key));
    }

    setColors(color, shadow) {
        this.options.color = color;
        this.options.shadow = shadow;
        const text = this.text;
        this.text = null;
        return this.setText(text);
    }

    setText(text) {
        // Dans les polices d'Essentials, le caractère "$" est dessiné comme le symbole Pokédollar
        text = String(text ?? '').replace(/₽/g, '$');
        if (text === this.text) return this;
        this.text = text;

        const { family, size, threshold, baseline: fontBaseline } = { ...FONTS[this.options.font], ...(this.options.family ? { family: this.options.family } : {}), ...(this.options.size ? { size: this.options.size } : {}) };
        const lines = text.split('\n');
        const lineHeight = this.options.lineHeight;

        // 1) Mesure
        const measure = PixelText.measureContext();
        measure.font = `${size}px '${family}'`;
        const widths = lines.map((l) => Math.ceil(measure.measureText(l).width));
        const width = Math.max(1, ...widths) + 4;              // +2 pour l'ombre, +2 de marge
        // PAD : marge en haut pour les accents des majuscules (É, À...) qui dépassent la ligne
        const PAD = 8;
        const height = lineHeight * (lines.length - 1) + size + 6 + PAD;

        // 2) Texte blanc sur fond transparent, dans un canvas de travail
        const work = PixelText.workCanvas(width, height);
        const wctx = work.getContext('2d', { willReadFrequently: true });
        wctx.clearRect(0, 0, width, height);
        wctx.font = `${size}px '${family}'`;
        // Ligne de base des lettres (voir FONTS) : même position sur tous les navigateurs et systèmes
        wctx.textBaseline = 'alphabetic';
        const baseline = this.options.size ? Math.round(size * 22 / 27) : fontBaseline;
        wctx.fillStyle = '#ffffff';
        lines.forEach((line, i) => {
            const lx = this.options.align === 'right' ? width - 4 - widths[i]
                : this.options.align === 'center' ? Math.floor((width - 4 - widths[i]) / 2) : 0;
            wctx.fillText(line, lx, i * lineHeight + PAD + baseline);
        });

        // 3) Seuil : chaque pixel est plein ou vide
        const src = wctx.getImageData(0, 0, width, height).data;
        const mask = new Uint8Array(width * height);
        for (let i = 0; i < mask.length; i++) {
            mask[i] = src[i * 4 + 3] >= (this.options.threshold ?? threshold ?? 110) ? 1 : 0;
        }

        // 4) Couleurs : texte, sinon ombre (le texte décalé de 2 px vers la droite / le bas)
        this.canvasTexture.setSize(width, height);
        const ctx = this.canvasTexture.getContext();
        const out = ctx.createImageData(width, height);
        const main = PixelText.rgb(this.options.color);
        const shadow = this.options.shadow ? PixelText.rgb(this.options.shadow) : null;
        const so = this.options.font === 'tiny' ? 1 : 2; // décalage de l'ombre
        const at = (x, y) => (x >= 0 && y >= 0 ? mask[y * width + x] : 0);

        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                let c = null;
                if (mask[y * width + x]) c = main;
                else if (shadow && (at(x - so, y) || at(x, y - so) || at(x - so, y - so))) c = shadow;
                if (!c) continue;
                const o = (y * width + x) * 4;
                out.data[o] = c[0];
                out.data[o + 1] = c[1];
                out.data[o + 2] = c[2];
                out.data[o + 3] = 255;
            }
        }
        ctx.putImageData(out, 0, 0);
        this.canvasTexture.refresh();

        this.setSize(width, height);
        const originX = this.options.align === 'right' ? 1 : this.options.align === 'center' ? 0.5 : 0;
        this.setDisplayOrigin(originX * width, PAD); // le texte reste à la même place malgré la marge
        this.textWidth = Math.max(0, ...widths);
        return this;
    }

    static rgb(hex) {
        const n = parseInt(hex.replace('#', ''), 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    static measureContext() {
        PixelText._measure ??= document.createElement('canvas').getContext('2d');
        return PixelText._measure;
    }

    static workCanvas(width, height) {
        PixelText._work ??= document.createElement('canvas');
        const c = PixelText._work;
        if (c.width < width) c.width = width;
        if (c.height < height) c.height = height;
        return c;
    }

    /** Largeur d'un texte (pour couper une phrase en lignes) */
    /**
     * Coupe un texte en lignes de largeur max. Si le texte ne tient pas en maxLines lignes,
     * on essaie la police étroite, puis la petite police (avec une ligne de plus).
     * Renvoie { lines, font, lineHeight }.
     */
    static fit(text, width, maxLines = 3, lineHeight = 32) {
        for (const [font, lines, height] of [['normal', maxLines, lineHeight], ['narrow', maxLines, lineHeight], ['small', maxLines + 1, Math.round(lineHeight * 0.75)]]) {
            const wrapped = PixelText.wrap(text, width, font);
            if (wrapped.length <= lines || font === 'small') return { lines: wrapped.slice(0, lines), font, lineHeight: height };
        }
    }

    /** Coupe un texte en lignes (aux espaces) pour qu'aucune ne dépasse width pixels */
    static wrap(text, width, font = 'normal') {
        const lines = [];
        let line = '';
        for (const word of String(text).split(' ')) {
            const attempt = line ? `${line} ${word}` : word;
            if (PixelText.measure(attempt, font) > width && line) {
                lines.push(line);
                line = word;
            } else {
                line = attempt;
            }
        }
        if (line) lines.push(line);
        return lines;
    }

    static measure(text, font = 'normal') {
        const { family, size } = FONTS[font];
        const ctx = PixelText.measureContext();
        ctx.font = `${size}px '${family}'`;
        return ctx.measureText(text).width;
    }
}
