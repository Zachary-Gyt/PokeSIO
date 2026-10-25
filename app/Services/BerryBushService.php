<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Console\Commands\ImportEssentialsSprites;
use App\Models\BerryPick;
use App\Models\Character;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Étape 22 : les BUISSONS À BAIES (calque Tiled "berries", voir config/berries.php).
 *
 * Chaque joueur a ses buissons : un buisson jamais cueilli porte des Baies. Après la cueillette, il repousse
 * en 5 étapes (comme Rubis / Saphir / Émeraude), sur le temps réel :
 *   0 plantée   1 a germé   2 grandit   3 en fleurs   4 des Baies !
 * Le nombre de Baies d'une récolte est tiré à l'avance (le même quand on regarde et quand on cueille).
 */
class BerryBushService
{
    public const RIPE = 4;

    /** Les buissons de la carte pour ce joueur : [['key', 'x', 'y', 'berry', 'sprite', 'stage', 'name']] */
    public function bushes(Character $character, string $mapKey): array
    {
        $map = MapInfo::load($mapKey);
        if (! $map || ! $map->berryBushes()) {
            return [];
        }
        $picks = BerryPick::where('character_id', $character->id)->where('map_key', $mapKey)->pluck('picked_at', 'bush');
        $items = Item::whereIn('slug', collect($map->berryBushes())->pluck('berry')->unique())->get()->keyBy('slug');

        return collect($map->berryBushes())
            ->filter(fn ($b) => isset($items[$b['berry']]))
            ->map(fn ($b) => [
                'key' => $b['key'], 'x' => $b['x'], 'y' => $b['y'],
                'berry' => $b['berry'],
                'name' => $items[$b['berry']]->name,
                // Planche d'Essentials : Graphics/Characters/berrytree_ORANBERRY.png (copiée dans sprites/berries)
                'sprite' => 'berrytree_' . ImportEssentialsSprites::essentialsKey($b['berry']),
                'stage' => $this->stage($b, $picks[$b['key']] ?? null),
            ])->values()->all();
    }

    /** Regarder un buisson : ce que le jeu doit dire (et combien de Baies il porte s'il est mûr) */
    public function inspect(Character $character, string $mapKey, string $key): array
    {
        [$bush, $item, $pickedAt] = $this->find($character, $mapKey, $key);
        $stage = $this->stage($bush, $pickedAt);
        $name = $item->name;
        $plural = self::plural($name);

        return [
            'stage' => $stage,
            'quantity' => $stage === self::RIPE ? $this->quantity($character, $mapKey, $bush, $pickedAt) : 0,
            'text' => match ($stage) {
                0 => "Une {$name} a été plantée ici.",
                1 => "La {$name} a germé.",
                2 => "Le plant de {$name} grandit.",
                3 => "Le plant de {$name} est en fleurs !",
                default => null,
            },
            'name' => $name,
            'plural' => $plural,
        ];
    }

    /** Cueillir : les Baies vont dans le Sac, le buisson repart de zéro */
    public function pick(Character $character, string $mapKey, string $key): array
    {
        [$bush, $item] = $this->find($character, $mapKey, $key);

        return DB::transaction(function () use ($character, $mapKey, $bush, $item) {
            // Ligne verrouillée : deux appuis très rapides ne donnent pas deux récoltes
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $pickedAt = BerryPick::where('character_id', $character->id)->where('map_key', $mapKey)->where('bush', $bush['key'])->first()?->picked_at;
            if ($this->stage($bush, $pickedAt) !== self::RIPE) {
                throw new InvalidAction('Il n\'y a pas encore de Baies.');
            }
            $quantity = $this->quantity($character, $mapKey, $bush, $pickedAt);
            $room = \App\Models\InventoryItem::MAX_QUANTITY - $character->quantityOf($item);
            if ($room < $quantity) {
                throw new InvalidAction('Votre Sac est plein de ces Baies !');
            }
            $character->addItem($item, $quantity);
            BerryPick::updateOrCreate(
                ['character_id' => $character->id, 'map_key' => $mapKey, 'bush' => $bush['key']],
                ['picked_at' => now()],
            );
            $name = $quantity > 1 ? self::plural($item->name) : $item->name;

            return [
                'quantity' => $quantity,
                'messages' => [
                    "{$character->name} cueille {$quantity} {$name}.",
                    "{$character->name} range " . ($quantity > 1 ? 'les' : 'la') . " {$name} dans la poche Baies.",
                ],
            ];
        });
    }

    // ---------------------------------------------------------------------

    /** [buisson, objet Baie, date de la dernière cueillette ou null] */
    private function find(Character $character, string $mapKey, string $key): array
    {
        $bush = MapInfo::load($mapKey)?->berryBushes()[$key] ?? null;
        $item = $bush ? Item::where('slug', $bush['berry'])->first() : null;
        if (! $bush || ! $item) {
            throw new InvalidAction('Il n\'y a rien ici.');
        }
        $pickedAt = BerryPick::where('character_id', $character->id)->where('map_key', $mapKey)->where('bush', $key)->first()?->picked_at;

        return [$bush, $item, $pickedAt];
    }

    /** Étape de pousse (0 à 4) selon le temps écoulé depuis la dernière cueillette */
    public function stage(array $bush, $pickedAt): int
    {
        if (! $pickedAt) {
            return self::RIPE;   // jamais cueilli : des Baies
        }
        $seconds = max(1, (int) round(($bush['hours'] ?? config('berries.hours', 24)) * 3600));
        $elapsed = max(0, now()->getTimestamp() - $pickedAt->getTimestamp());

        return min(self::RIPE, intdiv($elapsed * self::RIPE, $seconds));
    }

    /** Nombre de Baies de cette récolte : fixe (propriété quantity) ou tiré au hasard, mais le même jusqu'à la cueillette */
    private function quantity(Character $character, string $mapKey, array $bush, $pickedAt): int
    {
        if ($bush['quantity']) {
            return $bush['quantity'];
        }
        [$min, $max] = config('berries.yield', [1, 3]);
        $seed = crc32("{$character->id}:{$mapKey}:{$bush['key']}:" . ($pickedAt?->getTimestamp() ?? 'new'));

        return (int) $min + $seed % max(1, (int) $max - (int) $min + 1);
    }

    /** "Baie Oran" -> "Baies Oran" */
    public static function plural(string $name): string
    {
        return preg_replace('/^Baie\b/u', 'Baies', $name);
    }
}
