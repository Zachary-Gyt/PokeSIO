import * as TilesetData from './TilesetData.js';
import Npc from './Npc.js';
import ItemBalls from './ItemBalls.js';
import { api } from '../menu/Api.js';

/**
 * Étape 25 : les CARTES VOISINES, dessinées autour de la carte où se trouve le joueur
 * (cartes collées d'un monde Tiled, voir App\Services\WorldMap).
 *
 * Tout est en coordonnées de la carte ACTUELLE : la voisine de gauche commence par exemple à la case
 * x = -30. Le joueur peut marcher sur ses cases ; quand il y arrive, la scène passe sur cette carte
 * (WorldScene.crossTo), sans fondu.
 *
 * Ce qu'on voit d'une voisine : ses calques de tuiles, ses PNJ (immobiles, on ne peut pas leur parler
 * de loin) et ses Poké Balls par terre. Ses PNJ s'animent dès qu'on entre sur la carte.
 *
 *   const neighbors = new NeighborMaps(scene);
 *   NeighborMaps.around(config, 'cremieu')   // [{ key, dx, dy, w, h }] : les cartes à charger
 *   neighbors.create();                       // une fois les cartes chargées (WorldScene.create)
 *   neighbors.at(x, y)                        // la voisine qui couvre cette case, ou undefined
 */
const TILE = 32;
const DEPTH_ABOVE = 100000;

export default class NeighborMaps {
    constructor(scene) {
        this.scene = scene;
        this.list = [];
    }

    /** Les cartes du même monde assez proches pour être vues depuis mapKey (même règle que le serveur) */
    static around(config, mapKey) {
        const maps = config.world?.maps ?? {};
        const me = maps[mapKey];
        if (!me) return [];
        const margin = config.world?.margin ?? 10;
        return Object.entries(maps)
            .filter(([key, m]) => key !== mapKey && m.world === me.world && (config.maps ?? []).includes(key)
                && m.x < me.x + me.w + margin && m.x + m.w > me.x - margin
                && m.y < me.y + me.h + margin && m.y + m.h > me.y - margin)
            .map(([key, m]) => ({ key, dx: m.x - me.x, dy: m.y - me.y, w: m.w, h: m.h }));
    }

    create() {
        const scene = this.scene;
        const config = scene.registry.get('config');
        for (const n of NeighborMaps.around(config, scene.mapKey)) {
            if (!scene.cache.tilemap.exists(n.key)) continue;
            TilesetData.apply(scene, n.key);
            const map = scene.make.tilemap({ key: n.key });
            const tilesets = map.tilesets.map((ts) => map.addTilesetImage(ts.name, ts.name));
            const entry = { ...n, map, solidLayers: [], npcs: [], balls: new ItemBalls(scene) };
            for (const name of ['ground', 'decor', 'above']) {
                if (!map.getLayer(name)) continue;
                const layer = map.createLayer(name, tilesets, n.dx * TILE, n.dy * TILE);
                TilesetData.hideTiles(layer);
                if (name === 'above') layer.setDepth(DEPTH_ABOVE);
                else entry.solidLayers.push(layer);
            }
            this.createNpcs(entry, map);
            this.list.push(entry);
            this.loadDetails(entry);
        }
    }

    /** Les PNJ de la voisine, immobiles (les gardiens déjà passés et les PNJ cachés par le scénario n'apparaissent pas) */
    createNpcs(entry, map) {
        const scene = this.scene;
        const trainers = scene.registry.get('config').trainers ?? {};
        for (const obj of map.getObjectLayer('npcs')?.objects ?? []) {
            const props = Object.fromEntries((obj.properties ?? []).map((p) => [p.name, p.value]));
            if (scene.gateOpen(props)) continue;
            const x = Math.floor(obj.x / TILE) + entry.dx;
            const y = Math.floor((obj.gid ? obj.y - obj.height : obj.y) / TILE) + entry.dy;
            const npc = new Npc(scene, { ...props, x, y, behavior: 'static', trainerData: props.trainer ? trainers[props.trainer] : null });
            npc.objectId = obj.id;
            npc.conditional = props.show_if !== undefined || props.hide_if !== undefined;
            if (npc.conditional) npc.setHidden(true);   // en attendant la réponse du serveur
            entry.npcs.push(npc);
        }
    }

    /** Ce que le serveur sait de la voisine pour CE joueur : PNJ cachés (scénario) et Balls encore par terre */
    async loadDetails(entry) {
        const urls = this.scene.registry.get('config').menu;
        const [events, balls] = await Promise.all([
            urls.events ? api.get(`${urls.events}?map=${encodeURIComponent(entry.key)}`) : null,
            urls.itemBalls ? api.get(`${urls.itemBalls}?map=${encodeURIComponent(entry.key)}`) : null,
        ]);
        if (this.destroyed) return;
        const hidden = new Set(events?.ok ? events.data.hidden ?? [] : []);
        for (const npc of entry.npcs) {
            if (npc.conditional) npc.setHidden(hidden.has(npc.objectId));
        }
        if (balls?.ok) entry.balls.set((balls.data.balls ?? []).map((b) => ({ ...b, x: b.x + entry.dx, y: b.y + entry.dy })));
    }

    /** La voisine qui couvre la case (x, y) de la carte actuelle */
    at(x, y) {
        return this.list.find((n) => x >= n.dx && x < n.dx + n.w && y >= n.dy && y < n.dy + n.h);
    }

    /** Un PNJ ou une Poké Ball d'une voisine sur cette case ? */
    blocks(x, y) {
        const n = this.at(x, y);
        return Boolean(n && (n.npcs.some((npc) => npc.occupies(x, y)) || n.balls.blocks(x, y)));
    }

    /** Le rectangle (en pixels) qui contient la carte actuelle et ses voisines : les limites de la caméra */
    bounds(width, height) {
        let left = 0;
        let top = 0;
        let right = width;
        let bottom = height;
        for (const n of this.list) {
            left = Math.min(left, n.dx * TILE);
            top = Math.min(top, n.dy * TILE);
            right = Math.max(right, (n.dx + n.w) * TILE);
            bottom = Math.max(bottom, (n.dy + n.h) * TILE);
        }
        return { x: left, y: top, width: right - left, height: bottom - top };
    }

    destroy() {
        this.destroyed = true;
        for (const n of this.list) n.balls.destroy();
        this.list = [];
    }
}
