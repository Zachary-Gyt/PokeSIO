/**
 * Étape 23 : la TEINTE du jour et de la nuit sur les cartes extérieures (propriété de carte Tiled "outdoor").
 *
 * Comme Pokémon Essentials (PBDayNight) : une teinte par heure, mélangée minute par minute avec la suivante.
 * Une teinte d'Essentials (Tone) est [rouge, vert, bleu, gris] de -255 à 255. On la reproduit avec deux calques
 * collés à l'écran :
 *   - un calque en mode MULTIPLY pour assombrir (valeurs négatives : la nuit bleutée, le soir orangé),
 *     réglé pour qu'un pixel moyen perde la même quantité qu'avec Essentials ;
 *   - un calque en mode ADD pour éclaircir (valeurs positives, le bleu de la nuit).
 *
 * L'heure vient du serveur (fuseau du jeu, ou moment forcé par un administrateur avec /heure).
 */
const HOURLY_TONES = [
    [-70, -90, 15, 55], [-70, -90, 15, 55], [-70, -90, 15, 55], [-70, -90, 15, 55], // minuit -> 3 h : nuit
    [-60, -70, -5, 50],                                                             // 4 h
    [-40, -50, -35, 50], [-40, -50, -35, 50], [-40, -50, -35, 50], [-40, -50, -35, 50], // 5 h -> 8 h : matin
    [-20, -25, -15, 20],                                                            // 9 h
    [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0],                          // 10 h -> 13 h : jour
    [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0],                          // 14 h -> 17 h
    [-5, -30, -20, 0],                                                              // 18 h : soir
    [-15, -60, -10, 20], [-15, -60, -10, 20],                                        // 19 h, 20 h
    [-40, -75, 5, 40],                                                              // 21 h
    [-70, -90, 15, 55], [-70, -90, 15, 55],                                          // 22 h, 23 h : nuit
];

const DEPTH_TINT = 100005;   // devant la carte et les personnages, derrière l'obscurité des grottes et les bulles du HUD

/** La teinte d'Essentials à cette heure (mélange des deux heures qui l'entourent) */
export function toneAt(hour, minute) {
    const a = HOURLY_TONES[hour % 24];
    const b = HOURLY_TONES[(hour + 1) % 24];
    const t = minute / 60;
    return a.map((v, i) => v + (b[i] - v) * t);
}

/**
 * Un calque de couleur unie (texture "canvas" peinte à la main). On n'utilise pas un Rectangle de Phaser :
 * en mode Canvas, un Rectangle avec un mode de fusion le "laisse" actif pour les objets dessinés après lui.
 */
export function colorLayer(scene, key, width, height, blend) {
    const texture = scene.textures.exists(key) ? scene.textures.get(key) : scene.textures.createCanvas(key, width, height);
    const image = scene.add.image(0, 0, key).setOrigin(0, 0).setBlendMode(blend);
    image.paint = (color, alpha = 1) => {
        const ctx = texture.getContext();
        ctx.clearRect(0, 0, width, height);
        ctx.globalAlpha = alpha;
        ctx.fillStyle = `#${color.toString(16).padStart(6, '0')}`;
        ctx.fillRect(0, 0, width, height);
        ctx.globalAlpha = 1;
        texture.refresh();
        return image;
    };
    return image;
}

export default class DayNight {
    /** clock : { hour, minute } du serveur au moment où on l'a reçue */
    constructor(scene, clock) {
        this.scene = scene;
        this.clock = clock;
        this.received = Date.now();
        const { width, height } = scene.scale;
        this.dark = colorLayer(scene, 'daynight_dark', width, height, Phaser.BlendModes.MULTIPLY).setScrollFactor(0).setDepth(DEPTH_TINT);
        this.light = colorLayer(scene, 'daynight_light', width, height, Phaser.BlendModes.ADD).setScrollFactor(0).setDepth(DEPTH_TINT + 1);
        this.apply();
        this.timer = scene.time.addEvent({ delay: 30000, loop: true, callback: () => this.apply() });
    }

    /** L'heure du jeu maintenant (l'heure reçue + le temps passé depuis) */
    now() {
        const minutes = this.clock.hour * 60 + this.clock.minute + (this.clock.forced ? 0 : Math.floor((Date.now() - this.received) / 60000));
        return { hour: Math.floor(minutes / 60) % 24, minute: minutes % 60 };
    }

    apply() {
        const { hour, minute } = this.now();
        const [r, g, b, gray] = toneAt(hour, minute);
        // Le gris d'Essentials désature : on l'approche en rapprochant les canaux de leur moyenne
        const mix = (v) => v + ((r + g + b) / 3 - v) * (gray / 255);
        // Essentials RETIRE la valeur à chaque pixel : sur un pixel moyen (128), -70 laisse 58 / 128 de la couleur
        const channel = (v) => Math.max(0, Math.min(255, Math.round(255 * (128 + Math.min(0, mix(v))) / 128)));
        const plus = (v) => Math.max(0, Math.min(255, Math.round(Math.max(0, mix(v)))));
        this.darkColor = (channel(r) << 16) | (channel(g) << 8) | channel(b);
        this.dark.paint(this.darkColor).setVisible(r < 0 || g < 0 || b < 0);
        this.light.paint((plus(r) << 16) | (plus(g) << 8) | plus(b)).setVisible(r > 0 || g > 0 || b > 0);
    }

    destroy() {
        this.timer?.remove();
        this.dark?.destroy();
        this.light?.destroy();
    }
}
