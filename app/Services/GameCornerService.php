<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Models\PokemonSpecies;
use Illuminate\Support\Facades\DB;

/**
 * Étape 20 : le CASINO, hors des jeux eux-mêmes : les jetons, la Boîte Jetons, le guichet et les lots.
 *
 * Les jetons sont dans characters.coins (9 999 au plus). Comme l'argent, ils ne changent que par
 * addCoins() / spendCoins(), appelés par le serveur : le navigateur n'envoie jamais un nombre de jetons.
 */
class GameCornerService
{
    public function __construct(private PokemonFactory $factory)
    {
    }

    public function max(): int
    {
        return (int) config('game_corner.max_coins', 9999);
    }

    public function hasCoinCase(Character $character): bool
    {
        $case = Item::where('slug', config('game_corner.coin_case', 'coin-case'))->first();

        return $case && $character->hasItem($case);
    }

    /** Sans Boîte Jetons, pas de jetons */
    public function requireCoinCase(Character $character): void
    {
        if (! $this->hasCoinCase($character)) {
            throw new InvalidAction('Il vous faut une Boîte Jetons !');
        }
    }

    /** Ajoute des jetons (plafonné) ; renvoie le nouveau total */
    public function addCoins(Character $character, int $amount): int
    {
        $character->coins = min($this->max(), (int) $character->coins + max(0, $amount));
        $character->save();

        return (int) $character->coins;
    }

    /** Retire des jetons ; false (sans rien retirer) s'il n'y en a pas assez */
    public function spendCoins(Character $character, int $amount): bool
    {
        if ($amount < 0 || $character->coins < $amount) {
            return false;
        }
        $character->coins -= $amount;
        $character->save();

        return true;
    }

    // =====================================================================
    // Le guichet : acheter des jetons avec de l'argent
    // =====================================================================

    public function packs(): array
    {
        return collect(config('game_corner.packs', []))
            ->map(fn ($p, $i) => ['index' => $i, 'coins' => (int) $p[0], 'price' => (int) $p[1]])
            ->values()->all();
    }

    public function buy(Character $character, int $index): array
    {
        $pack = $this->packs()[$index] ?? null;
        if (! $pack) {
            throw new InvalidAction('Ce lot de jetons n\'existe pas.');
        }

        return DB::transaction(function () use ($character, $pack) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $this->requireCoinCase($character);
            if ($character->coins + $pack['coins'] > $this->max()) {
                throw new InvalidAction('Votre Boîte Jetons ne peut pas en contenir autant !');
            }
            if (! $character->spendMoney($pack['price'])) {
                throw new InvalidAction('Vous n\'avez pas assez d\'argent.');
            }
            $this->addCoins($character, $pack['coins']);

            return ['coins' => (int) $character->coins, 'money' => (int) $character->money,
                'message' => "Et voilà vos {$pack['coins']} jetons !"];
        });
    }

    // =====================================================================
    // Les comptoirs des lots
    // =====================================================================

    /** Les lots d'un comptoir, avec leur nom et leur icône (les lignes inconnues de la base sont ignorées) */
    public function prizes(string $counter): array
    {
        $list = [];
        foreach (config("game_corner.prizes.{$counter}", []) as $i => $p) {
            $row = ['index' => $i, 'price' => (int) $p['price']];
            if (! empty($p['pokemon'])) {
                $species = PokemonSpecies::where('slug', $p['pokemon'])->first();
                if (! $species) {
                    continue;
                }
                $row += ['kind' => 'pokemon', 'name' => $species->name, 'level' => (int) ($p['level'] ?? 5), 'icon' => $species->sprite('icon')];
            } else {
                $item = $this->prizeItem($p);
                if (! $item) {
                    continue;
                }
                $row += ['kind' => 'item', 'name' => $item->name, 'icon' => PokemonPresenter::itemIcon($item)];
            }
            $list[] = $row;
        }

        return $list;
    }

    /** L'objet d'un lot : 'item' => slug, ou 'tm' => slug de la capacité (on cherche la CT qui l'enseigne) */
    private function prizeItem(array $p): ?Item
    {
        if (! empty($p['tm'])) {
            return Item::where('effect_code', 'teach')
                ->whereHas('move', fn ($q) => $q->where('slug', $p['tm']))
                ->orderBy('slug')->first();
        }

        return ! empty($p['item']) ? Item::where('slug', $p['item'])->first() : null;
    }

    /** Échanger des jetons contre un lot */
    public function exchange(Character $character, string $counter, int $index, string $mapKey): array
    {
        $p = config("game_corner.prizes.{$counter}.{$index}");
        if (! $p) {
            throw new InvalidAction('Ce lot n\'existe pas.');
        }

        return DB::transaction(function () use ($character, $p, $mapKey) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $this->requireCoinCase($character);
            $price = (int) $p['price'];
            if ($character->coins < $price) {
                throw new InvalidAction('Vous n\'avez pas assez de jetons.');
            }

            if (! empty($p['pokemon'])) {
                $species = PokemonSpecies::where('slug', $p['pokemon'])->firstOrFail();
                $place = MapInfo::load($mapKey)?->property('displayName') ?? 'Casino';
                $given = $this->factory->giveTo($character, $this->factory->make($species, (int) ($p['level'] ?? 5)), ['location' => $place]);
                if (! $given) {
                    throw new InvalidAction('Votre équipe et votre PC sont pleins !');
                }
                $this->spendCoins($character->fresh(), $price);
                $messages = ["{$character->name} reçoit {$species->name} !"];
                if ($given->location !== 'party') {
                    $messages[] = "{$species->name} a été envoyé au PC.";
                }

                return ['messages' => $messages, 'me' => 'pokemon_get', 'coins' => (int) $character->fresh()->coins, 'pokemon' => PokemonPresenter::detail($given->fresh())];
            }

            $item = $this->prizeItem($p);
            if (! $item) {
                throw new InvalidAction('Ce lot n\'existe pas.');
            }
            $this->spendCoins($character, $price);
            $character->addItem($item);

            return ['messages' => ["{$character->name} reçoit {$item->name} !"], 'me' => 'item', 'coins' => (int) $character->fresh()->coins];
        });
    }
}
