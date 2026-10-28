import { toast } from '../ui/Toast.js';

/**
 * DÉCONNEXION POUR INACTIVITÉ : sans aucune touche, clic ou toucher pendant config/inactivity.php (minutes),
 * le joueur est renvoyé à l'écran de sélection des personnages (comme un /kick), après un avertissement.
 * La partie est déjà sauvegardée (sauvegarde automatique) ; les autres joueurs le voient se déconnecter.
 *
 * Pas de déconnexion pendant un combat contre un autre joueur ni pendant un échange (on attend l'autre).
 *
 *   watchIdle(game, { minutes: 5, warningSeconds: 60 });   // minutes = 0 : jamais
 */
export function watchIdle(game, { minutes = 5, warningSeconds = 60 } = {}) {
    if (!minutes || minutes <= 0) return;
    const limit = minutes * 60 * 1000;
    const warnAt = Math.max(0, limit - warningSeconds * 1000);
    let last = Date.now();
    let warned = false;
    let done = false;

    const activity = () => {
        last = Date.now();
        warned = false;
    };
    for (const type of ['keydown', 'pointerdown', 'touchstart', 'wheel']) {
        window.addEventListener(type, activity, { passive: true, capture: true });
    }

    // Combat entre joueurs ou échange en cours : l'autre joueur décide du rythme, on ne compte pas
    const busyWithAPlayer = () => {
        const battle = game.scene.isActive('Battle') ? game.scene.getScene('Battle') : null;
        const menu = game.scene.isActive('Menu') ? game.scene.getScene('Menu') : null;
        return Boolean(battle?.pvp) || menu?.task?.type === 'trade';
    };

    setInterval(() => {
        if (done) return;
        if (busyWithAPlayer()) {
            activity();
            return;
        }
        const idle = Date.now() - last;
        if (!warned && idle >= warnAt) {
            warned = true;
            toast(`Vous êtes inactif. Sans action, vous serez déconnecté dans ${Math.round((limit - idle) / 1000)} secondes.`, 8);
        }
        if (idle >= limit) {
            done = true;
            const message = `Vous avez été déconnecté après ${minutes} minute${minutes > 1 ? 's' : ''} d'inactivité.`;
            const world = game.scene.getScene('World');
            if (world?.kicked) world.kicked(message);
            else window.location.assign(window.GAME_CONFIG?.dashboardUrl ?? '/');
        }
    }, 5000);
}
