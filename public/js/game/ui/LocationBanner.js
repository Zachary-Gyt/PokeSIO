import PixelText from './PixelText.js';
import { createWindow } from './WindowSkin.js';

/**
 * Étape 25 : le PANNEAU DU NOM DU LIEU, comme dans Rubis / Saphir / Émeraude : en entrant dans une autre
 * zone (ville, route), son nom descend du haut de l'écran, reste un instant, puis remonte.
 *
 * Le nom est la propriété de carte Tiled "displayName". Il s'affiche sur les cartes placées dans un monde
 * (cartes collées) ; ailleurs, seulement si la carte a la propriété "banner" cochée. "banner" décochée : jamais.
 *
 *   showLocation(scene, 'Route 1');
 */
const DEPTH_BANNER = 100030;
const SHOW_MS = 2200;
const SLIDE_MS = 300;

export function showLocation(scene, name) {
    // Un seul panneau à la fois : le précédent disparaît
    scene.registry.get('locationBanner')?.destroy();
    if (!scene.textures.exists('ui_choice')) return;

    const text = new PixelText(scene, 0, 0, name, { color: '#505058', shadow: '#a0a0a8' });
    const width = Math.max(160, Math.ceil(text.width) + 40);
    const height = 48;
    const panel = scene.add.container(8, -height).setScrollFactor(0).setDepth(DEPTH_BANNER);
    const frame = createWindow(scene, 'ui_choice', width, height);
    text.setPosition(Math.round((width - text.width) / 2), 10);
    panel.add([frame, text]);
    scene.registry.set('locationBanner', panel);

    scene.tweens.add({
        targets: panel, y: 4, duration: SLIDE_MS, ease: 'Sine.easeOut',
        onComplete: () => scene.tweens.add({
            targets: panel, y: -height, delay: SHOW_MS, duration: SLIDE_MS, ease: 'Sine.easeIn',
            onComplete: () => panel.destroy(),
        }),
    });
    panel.once('destroy', () => {
        if (scene.registry.get('locationBanner') === panel) scene.registry.set('locationBanner', null);
    });
}
