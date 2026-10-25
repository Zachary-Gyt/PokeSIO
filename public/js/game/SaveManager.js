/**
 * Sauvegarde automatique de la partie (position, carte, direction) vers Laravel.
 *
 * Quand ?
 *   - toutes les N secondes (30 par défaut) ;
 *   - à l'arrivée sur une nouvelle carte (appelé par WorldScene) ;
 *   - quand l'onglet est masqué ou fermé.
 *
 * Le TEMPS DE JEU n'est pas envoyé : c'est Laravel qui le calcule, pour qu'on ne puisse pas tricher.
 */
export default class SaveManager {
    constructor({ url, csrfToken, intervalSeconds = 30, onSessionExpired = null }) {
        this.url = url;
        this.csrfToken = csrfToken;
        this.onSessionExpired = onSessionExpired;

        this.scene = null;       // la scène du monde en cours (null pendant un changement de carte)
        this.inFlight = false;   // une sauvegarde est-elle en cours d'envoi ?
        this.stopped = false;    // session expirée : on arrête d'essayer

        // Sauvegarde régulière
        setInterval(() => this.save(), intervalSeconds * 1000);

        // L'onglet est masqué (changement d'onglet, téléphone mis en veille...) ou fermé :
        // "keepalive" permet à la requête de partir même si la page est en train de se fermer.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') this.save({ keepalive: true });
        });
        window.addEventListener('pagehide', () => this.save({ keepalive: true }));
    }

    /** La scène du monde se déclare ici à chaque création (et se retire avant un changement de carte) */
    attach(scene) {
        this.scene = scene;
    }

    /** Ce qu'on enregistre : la carte, la case et la direction du joueur */
    currentState() {
        const scene = this.scene;
        if (!scene?.tile) return null;

        return {
            map: scene.mapKey,
            x: scene.tile.x,
            y: scene.tile.y,
            facing: scene.facing,
        };
    }

    async save({ keepalive = false, from = null } = {}) {
        if (this.stopped) return;

        const state = this.currentState();
        if (!state) return;
        // Étape 19 : la sortie qu'on vient d'emprunter (le serveur s'en sert pour la Corde Sortie)
        if (from) state.from = from;

        // Une sauvegarde part déjà : inutile d'en empiler une deuxième (sauf fermeture de la page)
        if (this.inFlight && !keepalive) return;
        this.inFlight = true;

        try {
            const response = await fetch(this.url, {
                method: 'POST',
                keepalive,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',          // Laravel répond en JSON, même en cas d'erreur
                    'X-CSRF-TOKEN': this.csrfToken,         // jeton anti-CSRF, comme @csrf dans un formulaire
                },
                body: JSON.stringify(state),
            });

            // 419 = jeton CSRF expiré, 401 = déconnecté : inutile de réessayer, il faut recharger la page
            if (response.status === 419 || response.status === 401) {
                this.stopped = true;
                this.onSessionExpired?.();
                return;
            }
            if (!response.ok) {
                console.warn('Sauvegarde refusée :', response.status, await response.text());
            }
        } catch (error) {
            // Coupure réseau : pas grave, la prochaine sauvegarde automatique réessaiera
            console.warn('Sauvegarde impossible (réseau) :', error);
        } finally {
            this.inFlight = false;
        }
    }
}
