<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use App\Models\PokemonSpecies;
use Illuminate\Support\Facades\DB;

/**
 * Étape 16 : les POKÉ BALLS posées par terre (calque d'objets Tiled "items").
 *
 * Chaque Ball donne UNE fois par personnage : un objet, de l'argent ou un Pokémon.
 * Les Balls déjà ramassées sont notées dans characters.story_flags['picked'] ("carte:clé").
 */
class ItemBallService
{
    public function __construct(private PokemonFactory $pokemonFactory)
    {
    }

    /** Les Balls encore présentes sur la carte pour ce personnage (le jeu les dessine) */
    public function remaining(Character $character, string $mapKey): array
    {
        $map = MapInfo::load($mapKey);
        if (! $map) {
            return [];
        }
        $picked = $character->story_flags['picked'] ?? [];

        return collect($map->itemBalls())
            ->reject(fn ($b) => in_array("{$mapKey}:{$b['key']}", $picked, true))
            ->map(fn ($b) => ['key' => $b['key'], 'x' => $b['x'], 'y' => $b['y'], 'hidden' => $b['hidden']])
            ->values()->all();
    }

    /**
     * Ramasse la Ball. Renvoie ['messages' => [...], 'me' => 'item' | 'pokemon_get'].
     */
    public function pick(Character $character, string $mapKey, string $key): array
    {
        $ball = MapInfo::load($mapKey)?->itemBalls()[$key] ?? null;
        if (! $ball) {
            throw new InvalidAction('Il n\'y a rien ici.');
        }

        return DB::transaction(function () use ($character, $mapKey, $key, $ball) {
            // Ligne verrouillée : deux appuis très rapides ne donnent pas deux fois la récompense
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $flags = $character->story_flags ?? [];
            $flag = "{$mapKey}:{$key}";
            if (in_array($flag, $flags['picked'] ?? [], true)) {
                throw new InvalidAction('Il n\'y a plus rien ici.');
            }

            $messages = [];
            $me = 'item';
            $who = $character->name;

            if ($ball['pokemon']) {
                $species = PokemonSpecies::where('slug', $ball['pokemon'])->first();
                if (! $species) {
                    throw new InvalidAction("Pokémon inconnu : {$ball['pokemon']} (propriété pokemon de la Ball).");
                }
                $pokemon = $this->pokemonFactory->make($species, $ball['level'], ['shiny' => $ball['shiny']]);
                $given = $this->pokemonFactory->giveTo($character, $pokemon, ['location' => MapInfo::load($mapKey)?->property('displayName') ?? $mapKey]);
                if (! $given) {
                    throw new InvalidAction('Votre équipe et votre PC sont pleins !');
                }
                $messages[] = "{$who} trouve un {$species->name} !";
                if ($given->location !== 'party') {
                    $messages[] = "{$species->name} a été envoyé au PC.";
                }
                $me = 'pokemon_get';
            } elseif ($ball['item']) {
                $item = Item::where('slug', $ball['item'])->first();
                if (! $item) {
                    throw new InvalidAction("Objet inconnu : {$ball['item']} (propriété item de la Ball).");
                }
                $character->addItem($item, $ball['quantity']);
                $messages[] = $ball['quantity'] > 1 ? "{$who} trouve {$ball['quantity']} {$item->name} !" : "{$who} trouve {$item->name} !";
                $messages[] = "{$who} range l'objet dans la poche " . (BagService::POCKETS[BagService::POCKET_OF[$item->pocket] ?? 1]) . '.';
                if ($item->pocket === 'key_items') {
                    $me = 'key_item';
                }
            }
            // Étape 20 : des jetons (cachés par terre au Casino, comme dans Rouge / Bleu). Il faut la Boîte Jetons.
            if (($ball['coins'] ?? 0) > 0) {
                $corner = app(GameCornerService::class);
                if (! $corner->hasCoinCase($character)) {
                    throw new InvalidAction('Il y a des jetons par terre... mais vous n\'avez pas de Boîte Jetons.');
                }
                $corner->addCoins($character, $ball['coins']);
                $messages[] = "{$who} trouve {$ball['coins']} jetons !";
            }
            if ($ball['money'] > 0) {
                $character->addMoney($ball['money']);
                $messages[] = "{$who} trouve " . number_format($ball['money'], 0, ',', ' ') . ' ₽ !';
            }
            if (! $messages) {
                $messages[] = 'La Poké Ball est vide...';
            }

            $flags = $character->fresh()->story_flags ?? [];   // giveTo peut avoir changé les drapeaux (Pokédex)
            $flags['picked'][] = $flag;
            $character->forceFill(['story_flags' => $flags])->save();

            return ['messages' => $messages, 'me' => $me, 'money' => $character->fresh()->money];
        });
    }
}
