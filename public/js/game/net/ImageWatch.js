/**
 * Images qui ne se chargent pas : on le note dans la console ET dans le journal du serveur
 * (storage/logs/laravel-*.log, « Image non chargée »), pour comprendre ce qui se passe chez les joueurs.
 *
 *   watchLoader(scene);   // une fois par scène : chaque erreur de son chargeur est signalée
 */
let reported = 0;
const MAX_REPORTS = 20;   // pas plus de 20 signalements par page ouverte

export function watchLoader(scene) {
    const loader = scene.load;
    if (!loader) return;
    // Phaser retire les écouteurs du chargeur quand la scène s'arrête : on le rebranche à chaque fois (sans doublon)
    loader.__onImageError ??= (file) => {
        const url = file?.src ?? file?.url ?? '';
        console.warn(`Image non chargée : ${file?.key} (${url})`);
        report({ key: String(file?.key ?? ''), url: String(url), status: file?.xhrLoader?.status ?? null, scene: scene.sys.settings.key });
    };
    loader.off('loaderror', loader.__onImageError);
    loader.on('loaderror', loader.__onImageError);
}

function report(data) {
    const url = window.GAME_CONFIG?.diagnosticUrl;
    if (!url || reported >= MAX_REPORTS) return;
    reported++;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    fetch(url, {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '' },
        body: JSON.stringify({ ...data, memory: performance?.memory ? Math.round(performance.memory.usedJSHeapSize / 1e6) : null }),
    }).catch(() => {});
}

/**
 * Une texture dont l'image est cassée (chargement raté, image vide) : on l'enlève pour qu'elle soit rechargée.
 * Renvoie true si la texture est utilisable.
 */
export function textureUsable(scene, key) {
    if (!scene.textures.exists(key)) return false;
    const source = scene.textures.get(key).getSourceImage();
    if (source instanceof HTMLImageElement && (!source.complete || source.naturalWidth === 0)) {
        scene.textures.remove(key);
        return false;
    }
    return true;
}
