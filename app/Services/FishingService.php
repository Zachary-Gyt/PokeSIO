<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Character;
use App\Models\Item;
use App\Models\WildEncounter;
use Illuminate\Support\Facades\Cache;

/**
 * Étape 15 : la PÊCHE (Canne, Super Canne, Méga Canne).
 *
 *   1. cast() : le joueur lance sa ligne devant lui. Le serveur décide tout de suite si ça va mordre,
 *      et au bout de combien de temps. Il garde la "touche" en cache quelques secondes.
 *   2. Le jeu affiche "..." puis "!" : le joueur doit appuyer sur Action à temps.
 *   3. hook() : si le joueur a ferré dans les temps, le combat commence.
 *
 * Le navigateur ne choisit ni le Pokémon, ni s'il mord : il dit seulement quand le joueur a appuyé.
 */
class FishingService
{
    public function __construct(private BattleService $battles)
    {
    }

    /** Lance la ligne vers la case d'eau (x, y). Renvoie ['bite' => bool, 'wait' => ms, 'reaction' => ms] */
    public function cast(Character $character, string $mapKey, int $x, int $y, Item $rod): array
    {
        $config = config("fishing.rods.{$rod->slug}");
        if (! $config) {
            throw new InvalidAction('Ce n\'est pas une canne à pêche.');
        }
        if (! $character->hasItem($rod)) {
            throw new InvalidAction("Vous n'avez pas de {$rod->name}.");
        }
        $map = MapInfo::load($mapKey);
        if (! $map || ! $map->tileHasProperty($x, $y, 'surf')) {
            throw new InvalidAction('Il n\'y a pas d\'eau ici.');
        }
        if ($this->battles->activeBattle($character)) {
            throw new InvalidAction('Vous êtes déjà en plein combat.');
        }

        // Pas de Pokémon pour cette canne dans cette eau : ça ne mordra jamais
        $hasFish = WildEncounter::at($mapKey, $map->encounterZone($x, $y, $config['method']), $config['method'])->exists();
        $bite = $hasFish && random_int(1, 100) <= (int) $config['bite'];
        $wait = random_int((int) config('fishing.wait_min', 1500), (int) config('fishing.wait_max', 4000));
        $reaction = (int) config('fishing.reaction', 1200);

        $key = "fishing:{$character->id}";
        if ($bite) {
            $now = (int) floor(microtime(true) * 1000);
            Cache::put($key, [
                'map' => $mapKey, 'x' => $x, 'y' => $y, 'method' => $config['method'],
                'from' => $now + $wait - 500,
                'until' => $now + $wait + $reaction + (int) config('fishing.network_margin', 1500),
            ], now()->addSeconds(30));
        } else {
            Cache::forget($key);
        }

        return ['bite' => $bite, 'wait' => $wait, 'reaction' => $reaction];
    }

    /** Le joueur a ferré. Renvoie le combat, ou null (trop tôt, trop tard, rien n'a mordu) */
    public function hook(Character $character): ?Battle
    {
        $catch = Cache::pull("fishing:{$character->id}");
        $now = (int) floor(microtime(true) * 1000);
        if (! $catch || $now < $catch['from'] || $now > $catch['until']) {
            return null;
        }

        return $this->battles->startFieldEncounter($character, $catch['map'], $catch['x'], $catch['y'], $catch['method'], 'water');
    }

    /** Les cannes du joueur, de la meilleure à la moins bonne (touche F, menu) */
    public function rods(Character $character): array
    {
        $order = array_keys(config('fishing.rods', []));

        return $character->items()->whereIn('slug', $order)->get()
            ->sortByDesc(fn (Item $i) => array_search($i->slug, $order, true))
            ->map(fn (Item $i) => ['id' => $i->id, 'slug' => $i->slug, 'name' => $i->name])
            ->values()->all();
    }
}
