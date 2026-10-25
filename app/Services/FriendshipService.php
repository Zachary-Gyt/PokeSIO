<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Pokemon;

/**
 * Étape 22 : le BONHEUR des Pokémon (0 à 255), comme dans les jeux de la 4e génération.
 *
 * Il monte en marchant, en gagnant des niveaux, avec les vitamines et certaines Baies ;
 * il baisse quand le Pokémon est K.O. ou qu'on lui donne une plante médicinale amère.
 * Le Grelot Zen (x1,5) et la Luxe Ball (+1) augmentent les gains. Voir config/items.php ("friendship").
 * Certains Pokémon évoluent quand leur Bonheur atteint 160 (Leveinard en Leuphorie...).
 */
class FriendshipService
{
    public const MAX = 255;

    /** Change le Bonheur selon la méthode ("levelup", "vitamin", "faint"...) ; renvoie le nouveau Bonheur. N'enregistre pas. */
    public function change(Pokemon $pokemon, string $method): int
    {
        $table = config("items.friendship.gains.{$method}");
        if (! $table || $pokemon->is_egg) {
            return (int) $pokemon->friendship;
        }
        $current = (int) $pokemon->friendship;
        $gain = (int) $table[min(2, intdiv($current, 100))];

        if ($gain > 0) {
            $pokemon->loadMissing('caughtBall', 'heldItem');
            if ($pokemon->caughtBall?->slug === 'luxury-ball') {
                $gain++;
            }
            if (HeldItemService::effectOf($pokemon) === 'soothe_bell') {
                $gain = (int) floor($gain * 1.5);
            }
        }

        $pokemon->friendship = max(0, min(self::MAX, $current + $gain));

        return (int) $pokemon->friendship;
    }

    /** Même chose, puis enregistre */
    public function apply(Pokemon $pokemon, string $method): int
    {
        $value = $this->change($pokemon, $method);
        $pokemon->save();

        return $value;
    }

    /** Les pas du joueur : +1 de Bonheur tous les 128 pas pour chaque Pokémon de l'équipe */
    public function steps(Character $character, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $flags = $character->story_flags ?? [];
        $counter = (int) ($flags['friend_steps'] ?? 0) + $count;
        $every = max(1, (int) config('items.friendship.walking_steps', 128));
        $times = intdiv($counter, $every);
        $flags['friend_steps'] = $counter % $every;
        $character->forceFill(['story_flags' => $flags])->save();

        if ($times <= 0) {
            return;
        }
        foreach ($character->party()->where('is_egg', false)->where('friendship', '<', self::MAX)->get() as $pokemon) {
            for ($i = 0; $i < $times; $i++) {
                $this->change($pokemon, 'walking');
            }
            $pokemon->save();
        }
    }
}
