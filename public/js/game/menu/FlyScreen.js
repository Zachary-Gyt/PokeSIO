import { ChoiceWindow } from '../battle/BattleUI.js';
import { createWindow } from '../ui/WindowSkin.js';
import PixelText from '../ui/PixelText.js';

/**
 * VOL : la liste des villes déjà visitées, en grand au milieu de l'écran (pas de carte de la région).
 *
 * Une ville apparaît dans la liste quand le joueur y est passé (propriété de carte Tiled "fly" = true).
 *
 *   const to = await new FlyScreen(scene, destinations).open();   // clé de carte, ou null (Retour)
 */
const WIDTH = 400;
const ROW = 32;
const MAX_ROWS = 8;   // au-delà, la liste défile (flèches ou molette)

export default class FlyScreen extends Phaser.GameObjects.Container {
    constructor(scene, destinations) {
        super(scene, 0, 0);
        this.destinations = destinations;
        this.setDepth(1500);
        scene.add.existing(this);
    }

    open() {
        const s = this.scene;
        const rows = Math.min(MAX_ROWS, this.destinations.length + 1);
        const listHeight = rows * ROW + 32;
        const top = Math.max(8, Math.round((384 - 64 - 8 - listHeight) / 2));
        const x = (512 - WIDTH) / 2;

        // Le fond de la carte est assombri, puis le titre et la liste
        this.add(s.add.rectangle(0, 0, 512, 384, 0x000000, 0.55).setOrigin(0, 0));
        this.add(createWindow(s, 'ui_choice', WIDTH, 64).setPosition(x, top));
        this.add(new PixelText(s, 256, top + 16, 'Où voler ?', { color: '#505058', shadow: '#a0a0a8', align: 'center' }));

        this.choices = new ChoiceWindow(s, [...this.destinations.map((d) => ({ label: d.name })), { label: 'Retour' }], {
            x, y: top + 64 + 8, width: WIDTH, cancelValue: this.destinations.length, maxRows: MAX_ROWS,
        }).setDepth(1501);

        return this.choices.promise.then((choice) => {
            this.choices.destroy();
            this.destroy();
            return choice === null || choice >= this.destinations.length ? null : this.destinations[choice].map;
        });
    }

    /** Les touches vont à la liste */
    handleKey(action) {
        return this.choices?.handleKey(action);
    }
}
