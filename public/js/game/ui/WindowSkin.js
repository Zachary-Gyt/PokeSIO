/**
 * Fenêtre "9-slice" dessinée avec un "windowskin" d'Essentials (Graphics/Windowskins/choice 1.png) :
 * les 4 coins sont recopiés tels quels, les bords et le centre sont étirés à la taille voulue.
 *
 * (Phaser a un objet NineSlice, mais il ne fonctionne qu'en WebGL ; le jeu est en mode Canvas.)
 *
 *   const win = createWindow(scene, 'choice', 200, 120);   // une Image Phaser
 */
let counter = 0;

export function createWindow(scene, skinKey, width, height, corner = 16) {
    const key = `__window_${counter++}`;
    const texture = scene.textures.createCanvas(key, width, height);
    drawWindow(texture, scene.textures.get(skinKey).getSourceImage(), width, height, corner);

    const image = scene.add.image(0, 0, key).setOrigin(0, 0);
    image.once('destroy', () => scene.textures.remove(key));
    return image;
}

function drawWindow(texture, skin, w, h, c) {
    const ctx = texture.getContext();
    ctx.imageSmoothingEnabled = false;
    const sw = skin.width;
    const sh = skin.height;
    const mw = sw - 2 * c; // taille du milieu dans l'image source
    const mh = sh - 2 * c;

    const piece = (sx, sy, swid, shei, dx, dy, dwid, dhei) => {
        if (dwid > 0 && dhei > 0) ctx.drawImage(skin, sx, sy, swid, shei, dx, dy, dwid, dhei);
    };

    piece(c, c, mw, mh, c, c, w - 2 * c, h - 2 * c);       // centre
    piece(c, 0, mw, c, c, 0, w - 2 * c, c);                // haut
    piece(c, sh - c, mw, c, c, h - c, w - 2 * c, c);       // bas
    piece(0, c, c, mh, 0, c, c, h - 2 * c);                // gauche
    piece(sw - c, c, c, mh, w - c, c, c, h - 2 * c);       // droite
    piece(0, 0, c, c, 0, 0, c, c);                         // coins
    piece(sw - c, 0, c, c, w - c, 0, c, c);
    piece(0, sh - c, c, c, 0, h - c, c, c);
    piece(sw - c, sh - c, c, c, w - c, h - c, c, c);
    texture.refresh();
}
