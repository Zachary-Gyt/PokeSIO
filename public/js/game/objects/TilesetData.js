/**
 * Les PROPRIÉTÉS DES TUILES partagées par toutes les cartes : public/game/tilesets/<tileset>.tsj
 * (collisions, hautes herbes, eau, rebords, comptoirs... créés par "php artisan game:tilesets", ou édités dans Tiled).
 *
 * Une carte n'a donc plus besoin de cocher "collides" elle-même. Si elle le fait quand même pour une tuile,
 * c'est sa propriété qui gagne (pour cette carte seulement).
 *
 *   queue(scene, mapData)  : charge les .tsj des tilesets de la carte (pendant le preload)
 *   apply(scene, mapKey)   : les recopie dans la carte (avant scene.make.tilemap)
 *   hideTiles(layer)       : cache les tuiles "hidden" (le « X rouge » : un mur invisible)
 */
export function queue(scene, data) {
    const config = scene.registry.get('config');
    const v = `?v=${config.version}`;
    for (const ts of data?.tilesets ?? []) {
        const key = `tsj_${ts.name}`;
        if (ts.name && (config.tilesetData ?? []).includes(ts.name) && !scene.cache.json.exists(key)) {
            scene.load.json(key, `${config.basePath}/tilesets/${encodeURIComponent(ts.name)}.tsj${v}`);
        }
    }
}

export function apply(scene, mapKey) {
    const data = scene.cache.tilemap.get(mapKey)?.data;
    if (!data || data.__tilesetData) return;
    for (const ts of data.tilesets ?? []) {
        const shared = scene.cache.json.get(`tsj_${ts.name}`);
        if (!shared?.tiles) continue;
        // tuiles du .tsj, puis celles de la carte par-dessus (propriété par propriété)
        const byId = new Map(shared.tiles.map((t) => [t.id, { id: t.id, properties: [...(t.properties ?? [])] }]));
        for (const t of ts.tiles ?? []) {
            const base = byId.get(t.id) ?? { id: t.id, properties: [] };
            const names = new Set((t.properties ?? []).map((p) => p.name));
            base.properties = [...base.properties.filter((p) => !names.has(p.name)), ...(t.properties ?? [])];
            byId.set(t.id, { ...t, properties: base.properties });
        }
        ts.tiles = [...byId.values()];
    }
    data.__tilesetData = true;
}

export function hideTiles(layer) {
    layer.forEachTile((tile) => {
        if (tile.properties?.hidden) tile.visible = false;
    });
}

/**
 * Une case bloque-t-elle ? Comme RPG Maker XP : on regarde les calques du HAUT vers le BAS.
 * La première tuile rencontrée décide (bloquante, ou praticable), sauf une tuile "over" (dessinée
 * devant le joueur), qui ne compte pas. Ainsi un pont posé sur l'eau se traverse.
 */
export function blocked(layers, x, y) {
    for (let i = layers.length - 1; i >= 0; i--) {
        const tile = layers[i].getTileAt(x, y);
        if (!tile) continue;
        if (tile.properties?.collides === true) return true;
        if (tile.properties?.over === true) continue;
        return false;
    }
    return false;
}
