<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Models\InventoryItem;

/**
 * La Boutique : acheter (selon les badges) et vendre (à moitié prix).
 * Les boutiques et leurs objets sont décrits dans config/shops.php.
 */
class ShopService
{
    /** Clés de config/shops.php qui ne sont pas des boutiques */
    private const NOT_SHOPS = ['bonus_ball', 'prices'];

    /**
     * Prix d'achat : la colonne items.price, ou à défaut le prix de config/shops.php ("prices").
     * (Selon la façon dont la table items a été remplie, items.price peut valoir 0 partout.)
     */
    public static function priceOf(Item $item): int
    {
        return (int) $item->price > 0 ? (int) $item->price : (int) (config("shops.prices.{$item->slug}") ?? 0);
    }

    /** Prix de revente : items.sell_price, ou à défaut la moitié du prix d'achat (comme dans les jeux) */
    public static function sellPriceOf(Item $item): int
    {
        return (int) $item->sell_price > 0 ? (int) $item->sell_price : intdiv(self::priceOf($item), 2);
    }

    /** Les objets en vente pour ce personnage : [itemData + 'price'] */
    public function stock(Character $character, string $shop): array
    {
        $tiers = config("shops.{$shop}");
        if (! is_array($tiers) || in_array($shop, self::NOT_SHOPS, true)) {
            throw new InvalidAction('Cette boutique n\'existe pas.');
        }

        $badges = $character->badges()->count();
        $slugs = collect($tiers)->filter(fn ($list, $needed) => $badges >= (int) $needed)->flatten()->unique()->values();
        $items = Item::whereIn('slug', $slugs)->get()->keyBy('slug');
        $bag = app(BagService::class);

        return $slugs->filter(fn ($slug) => isset($items[$slug]) && self::priceOf($items[$slug]) > 0)
            ->map(fn ($slug) => $bag->itemData($items[$slug], $character->quantityOf($items[$slug])) + ['price' => self::priceOf($items[$slug])])
            ->values()->all();
    }

    /** Achat ; renvoie les messages à afficher */
    public function buy(Character $character, string $shop, Item $item, int $quantity): array
    {
        $onSale = collect($this->stock($character, $shop))->firstWhere('id', $item->id);
        if (! $onSale) {
            throw new InvalidAction('Cet objet n\'est pas en vente ici.');
        }
        if ($character->quantityOf($item) + $quantity > InventoryItem::MAX_QUANTITY) {
            throw new InvalidAction('Vous ne pouvez pas en porter autant.');
        }
        $total = self::priceOf($item) * $quantity;
        if (! $character->spendMoney($total)) {
            throw new InvalidAction('Vous n\'avez pas assez d\'argent.');
        }

        $character->addItem($item, $quantity);
        $messages = ['Voici ! Merci beaucoup !'];

        // Bonus : une Honor Ball par lot de 10 Balls
        $bonus = Item::where('slug', config('shops.bonus_ball', 'premier-ball'))->first();
        if ($bonus && $item->pocket === 'balls' && $quantity >= 10) {
            $count = intdiv($quantity, 10);
            $character->addItem($bonus, $count);
            $messages[] = $count > 1
                ? "En prime, voici {$count} {$bonus->name} !"
                : "En prime, voici une {$bonus->name} !";
        }

        return $messages;
    }

    /** Vente : la moitié du prix d'achat (items.sell_price). Les objets rares ne se vendent pas. */
    public function sell(Character $character, Item $item, int $quantity): int
    {
        if ($item->pocket === 'key_items' || self::sellPriceOf($item) <= 0) {
            throw new InvalidAction("Oh, non, je ne peux pas acheter ça.");
        }
        if (! $character->removeItem($item, $quantity)) {
            throw new InvalidAction('Vous n\'en avez pas autant.');
        }
        $total = self::sellPriceOf($item) * $quantity;
        $character->addMoney($total);

        return $total;
    }
}
