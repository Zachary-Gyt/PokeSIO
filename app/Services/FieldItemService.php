<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Étape 19 : les OBJETS DE TERRAIN qu'on utilise depuis le Sac, sans choisir de Pokémon.
 *
 *   Repousse (effect_code "repel", effect_value = nombre de pas) :
 *     story_flags['repel'] = pas restants. Les pas sont comptés par le serveur (POST /pas, comme la Pension).
 *     Tant qu'il agit, BattleService::startWild() repousse les Pokémon sauvages trop faibles (config move_tutor.repel_mode).
 *
 *   Corde Sortie (effect_code "escape") : dans une grotte (propriété de carte "cave" ou "dark"), ramène à l'entrée.
 *     L'entrée est notée par GameSaveController quand le joueur entre dans la grotte par une sortie (story_flags['escape_point']).
 *
 * Étape 22 :
 *   Cendre Sacrée ("revive_all") : ranime toute l'équipe avec tous ses PV.
 *   Flûte Noire / Blanche ("encounter_flute", value = % de rencontres) : jusqu'au changement de carte (le jeu s'en souvient).
 *   Le Bonheur monte en marchant : les pas sont donc toujours envoyés (stepStatus active) s'il reste un Pokémon à rendre heureux.
 */
class FieldItemService
{
    /**
     * Le jeu doit-il envoyer les pas au serveur ? (Pension, Œuf dans l'équipe, Repousse actif)
     * ['active' => bool, 'hatchIn' => pas avant la prochaine éclosion ou null, 'repel' => pas restants]
     */
    public function stepStatus(Character $character): array
    {
        $status = app(BreedingService::class)->status($character);
        $repel = $this->repelSteps($character);
        $friendship = $character->party()->where('is_egg', false)->where('friendship', '<', FriendshipService::MAX)->exists();

        $vsSeeker = app(RematchService::class)->charge($character) > 0;   // étape 26 : la batterie du Cherche VS se recharge

        return ['active' => $status['active'] || $repel > 0 || $friendship || $vsSeeker, 'hatchIn' => $status['hatchIn'], 'repel' => $repel];
    }

    /** Pas restants du Repousse (0 = aucun) */
    public function repelSteps(Character $character): int
    {
        return max(0, (int) ($character->story_flags['repel'] ?? 0));
    }

    /** Utiliser un Repousse ; renvoie le message */
    public function useRepel(Character $character, Item $item): array
    {
        if ($item->effect_code !== 'repel') {
            throw new InvalidAction('Ce n\'est pas un Repousse.');
        }

        return DB::transaction(function () use ($character, $item) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            if ($this->repelSteps($character) > 0) {
                throw new InvalidAction('L\'effet du dernier Repousse agit encore !');
            }
            if (! $character->removeItem($item)) {
                throw new InvalidAction('Vous n\'avez pas cet objet.');
            }
            $steps = max(1, (int) ($item->effect_value ?: 100));
            $character->setFlag('repel', $steps);

            return ['repel' => $steps, 'message' => "{$character->name} utilise {$item->name}."];
        });
    }

    /**
     * Des pas faits par le joueur (déjà vérifiés par BreedingService::steps).
     * Renvoie ['repel' => pas restants, 'repelEnded' => bool, 'repelNext' => un autre Repousse du Sac ou null]
     */
    public function steps(Character $character, int $count): array
    {
        $left = $this->repelSteps($character);
        if ($left <= 0 || $count <= 0) {
            return ['repel' => $left, 'repelEnded' => false, 'repelNext' => null];
        }
        $left = max(0, $left - $count);
        $character->setFlag('repel', $left);

        return [
            'repel' => $left,
            'repelEnded' => $left === 0,
            'repelNext' => $left === 0 ? $this->anotherRepel($character) : null,
        ];
    }

    /** Un Repousse du Sac à proposer quand l'effet se termine : ['id', 'name'] ou null */
    private function anotherRepel(Character $character): ?array
    {
        $item = $character->items()->where('effect_code', 'repel')->orderBy('effect_value')->first();

        return $item ? ['id' => $item->id, 'name' => $item->name] : null;
    }

    /**
     * Le Repousse empêche-t-il cette rencontre ? Mode "level" (jeux récents) : le Pokémon sauvage est plus faible
     * que le premier Pokémon en forme de l'équipe. Mode "all" : toutes les rencontres.
     */
    public function repels(Character $character, int $wildLevel): bool
    {
        if ($this->repelSteps($character) <= 0) {
            return false;
        }
        if (config('move_tutor.repel_mode', 'level') === 'all') {
            return true;
        }
        $lead = $character->battleParty()->where('current_hp', '>', 0)->first();

        return $lead && $wildLevel < $lead->level;
    }

    /**
     * Utiliser une Corde Sortie : seulement dans une grotte. Renvoie la destination (entrée de la grotte).
     * Sans entrée connue (joueur téléporté...), retour au dernier Centre Pokémon.
     */
    public function useEscapeRope(Character $character, Item $item, string $mapKey): array
    {
        if ($item->effect_code !== 'escape') {
            throw new InvalidAction('Ce n\'est pas une Corde Sortie.');
        }
        $map = MapInfo::load($mapKey);
        if ($mapKey !== $character->current_map || ! $this->isCave($map)) {
            throw new InvalidAction('Ce n\'est pas le moment d\'utiliser ça !');
        }

        $point = $character->story_flags['escape_point'] ?? null;
        if (! $point || ! MapInfo::load((string) ($point['map'] ?? ''))) {
            $point = [
                'map' => $character->last_heal_map ?: config('game.starting_map'),
                'x' => $character->last_heal_x,
                'y' => $character->last_heal_y,
                'facing' => 'down',
            ];
        }
        if (! $character->removeItem($item)) {
            throw new InvalidAction('Vous n\'avez pas cet objet.');
        }

        return [
            'to' => ['map' => $point['map'], 'x' => $point['x'], 'y' => $point['y'], 'facing' => $point['facing'] ?? 'down'],
            'message' => "{$character->name} utilise {$item->name} !",
        ];
    }

    /** Étape 22 : Cendre Sacrée, toute l'équipe K.O. reprend connaissance avec tous ses PV */
    public function useSacredAsh(Character $character, Item $item): array
    {
        $fainted = $character->party()->where('is_egg', false)->where('current_hp', '<=', 0)->get();
        if ($fainted->isEmpty()) {
            throw new InvalidAction('Cela n\'aura aucun effet.');
        }
        if (! $character->removeItem($item)) {
            throw new InvalidAction('Vous n\'avez pas cet objet.');
        }
        foreach ($fainted as $pokemon) {
            $pokemon->forceFill(['current_hp' => $pokemon->max_hp, 'status' => 'none', 'sleep_turns' => 0])->save();
        }
        $names = $fainted->pluck('display_name')->implode(', ');

        return ['message' => "{$character->name} utilise {$item->name} !\n{$names} " . ($fainted->count() > 1 ? 'ont' : 'a') . ' repris connaissance !'];
    }

    /** Étape 22 : Flûte Noire (moins de rencontres) / Blanche (plus de rencontres), jusqu'au changement de carte */
    public function useFlute(Character $character, Item $item, string $mapKey): array
    {
        $rate = max(10, min(300, (int) ($item->effect_value ?: 100)));
        $message = $rate < 100
            ? "{$character->name} joue de la {$item->name}.\nLes Pokémon sauvages vont être repoussés."
            : "{$character->name} joue de la {$item->name}.\nLes Pokémon sauvages vont être attirés.";

        return ['message' => $message, 'encounterRate' => ['map' => $mapKey, 'rate' => $rate]];
    }

    /** Une grotte : propriété de carte "cave" (étape 17) ou "dark" (grotte sombre, Flash) */
    public function isCave(?MapInfo $map): bool
    {
        return (bool) ($map?->property('cave') || $map?->property('dark'));
    }

    /**
     * Appelé à chaque sauvegarde qui suit un changement de carte : si le joueur vient d'entrer dans une grotte
     * par une vraie sortie d'une carte qui n'est pas une grotte, on note cette sortie (l'entrée de la grotte).
     * $from = ['map', 'x', 'y', 'facing'] : la case de la sortie empruntée (vérifiée dans la carte d'origine).
     */
    public function rememberCaveEntrance(Character $character, string $mapKey, ?array $from): void
    {
        if (! $from || ! $this->isCave(MapInfo::load($mapKey))) {
            return;
        }
        $origin = MapInfo::load((string) ($from['map'] ?? ''));
        if (! $origin || $this->isCave($origin)) {
            return;   // d'une grotte à l'autre : l'entrée reste celle d'avant
        }
        $warp = $origin->warpAt((int) $from['x'], (int) $from['y']);
        if (! $warp || $warp['toMap'] !== $mapKey) {
            return;   // pas une vraie sortie vers cette grotte : on ignore
        }
        $opposite = ['up' => 'down', 'down' => 'up', 'left' => 'right', 'right' => 'left'];
        $character->setFlag('escape_point', [
            'map' => $from['map'],
            'x' => (int) $from['x'],
            'y' => (int) $from['y'],
            'facing' => $opposite[$from['facing'] ?? 'up'] ?? 'down',
        ]);
    }
}
