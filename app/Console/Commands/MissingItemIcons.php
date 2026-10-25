<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Services\PokemonPresenter;
use App\Support\WorldRegion;
use Illuminate\Console\Command;

/**
 * Les objets du Wiki qui n'ont pas d'image dans public/game/items (le Wiki affiche « ? » à la place).
 *
 *   php artisan wiki:images
 *
 * Pour en ajouter une : un PNG de 48 x 48 nommé comme indiqué (ex. VSSEEKER.png) dans public/game/items.
 */
class MissingItemIcons extends Command
{
    protected $signature = 'wiki:images';

    protected $description = 'Liste les objets du Wiki sans image (public/game/items)';

    public function handle(): int
    {
        $rows = Item::with('move.type')->orderBy('pocket')->orderBy('name')->get()
            ->filter(fn (Item $i) => WorldRegion::itemGeneration($i->slug) !== null)   // ceux que montre le Wiki
            ->map(fn (Item $i) => [$i->name, $i->slug, PokemonPresenter::itemIcon($i) . '.png', $i->pocket])
            ->filter(fn ($r) => ! is_file(public_path("game/items/{$r[2]}")))
            ->values();

        if ($rows->isEmpty()) {
            $this->info('Toutes les images des objets sont là !');

            return self::SUCCESS;
        }
        $this->table(['Objet', 'Slug', 'Fichier attendu', 'Poche'], $rows->all());
        $this->warn("{$rows->count()} objet(s) sans image : ajoute ces fichiers dans public/game/items.");

        return self::SUCCESS;
    }
}
