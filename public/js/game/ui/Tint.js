/**
 * Teintes compatibles avec le rendu CANVAS.
 *
 * Phaser ne sait teinter une image (setTint / setTintFill) qu'en rendu WEBGL. Notre jeu
 * tourne en CANVAS (pour des pixels nets), donc ces appels ne font rien.
 * Solution : on fabrique UNE fois une copie colorée de l'image, puis on l'affiche
 * à la place de l'originale. clearTint() remet l'image d'origine.
 *
 *   tintFill(sprite, 0xffffff);   // silhouette blanche (Pokémon qui sort de sa Ball)
 *   tint(sprite, 0x808080);       // couleur multipliée (Ball capturée qui s'assombrit)
 *   blend(sprite, 0xf08030, 0.6); // l'image "lavée" de 60 % de couleur (flash d'attaque, statut)
 *   clearTint(sprite);            // retour à la normale
 */

/** Toute l'image peinte d'une seule couleur (comme setTintFill) */
export function tintFill(obj, color) {
    return apply(obj, 'fill', color);
}

/** Les couleurs de l'image multipliées par une couleur (comme setTint) */
export function tint(obj, color) {
    return apply(obj, 'mul', color);
}

/** L'image mélangée à une couleur ; amount de 0 (rien) à 1 (silhouette), comme la propriété color d'Essentials */
export function blend(obj, color, amount = 0.5) {
    return apply(obj, `blend${Math.round(amount * 100)}`, color);
}

/** Remet l'image d'origine */
export function clearTint(obj) {
    if (!obj?.tintOriginal) return obj;
    const { key, frame } = obj.tintOriginal;
    obj.tintOriginal = null;
    obj.setTexture(key, frame);
    return obj;
}

function apply(obj, mode, color) {
    if (!obj) return obj;
    clearTint(obj);
    const key = obj.texture.key;
    const frame = obj.frame.name;
    const tintedKey = makeTexture(obj.scene, key, frame, mode, color);
    obj.setTexture(tintedKey);
    obj.tintOriginal = { key, frame };
    return obj;
}

/** Crée (une seule fois) la texture colorée "<clé>#<image>#<mode>#<couleur>" */
function makeTexture(scene, key, frameName, mode, color) {
    const tintedKey = `${key}#${frameName}#${mode}#${color.toString(16)}`;
    if (scene.textures.exists(tintedKey)) return tintedKey;

    const frame = scene.textures.getFrame(key, frameName);
    const { cutX, cutY, cutWidth: w, cutHeight: h } = frame;
    const source = frame.source.image;
    const css = `#${color.toString(16).padStart(6, '0')}`;

    const canvas = scene.textures.createCanvas(tintedKey, w, h);
    const ctx = canvas.getContext();
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(source, cutX, cutY, w, h, 0, 0, w, h);

    if (mode === 'fill') {
        // Ne peint que là où l'image est opaque
        ctx.globalCompositeOperation = 'source-in';
        ctx.fillStyle = css;
        ctx.fillRect(0, 0, w, h);
    } else if (mode.startsWith('blend')) {
        // Recouvre l'image (seulement ses pixels opaques) d'une couleur semi-transparente
        ctx.globalCompositeOperation = 'source-atop';
        ctx.globalAlpha = Number(mode.slice(5)) / 100;
        ctx.fillStyle = css;
        ctx.fillRect(0, 0, w, h);
        ctx.globalAlpha = 1;
    } else {
        // Multiplie les couleurs, puis remet la transparence de l'image d'origine
        ctx.globalCompositeOperation = 'multiply';
        ctx.fillStyle = css;
        ctx.fillRect(0, 0, w, h);
        ctx.globalCompositeOperation = 'destination-in';
        ctx.drawImage(source, cutX, cutY, w, h, 0, 0, w, h);
    }
    ctx.globalCompositeOperation = 'source-over';
    canvas.refresh();
    return tintedKey;
}
