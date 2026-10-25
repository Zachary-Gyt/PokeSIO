<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use App\Models\Evolution;
use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use App\Models\TrainerPokemon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Étape 26 : les REVANCHES contre les dresseurs déjà battus.
 *
 * - Le CHERCHE VS (objet rare) : utilisé sur une carte, il fait "lever la main" aux dresseurs battus qui sont
 *   à l'écran (character_trainer.rematch_ready). Ils redeviennent des dresseurs à affronter : ils te voient,
 *   viennent vers toi et lancent le combat. Ensuite, la batterie se recharge en marchant
 *   (story_flags['vs_seeker'] = pas restants avant la prochaine charge).
 * - Les CHAMPIONS D'ARÈNE acceptent une revanche quand on a tous les badges (config rematches.leaders_badges).
 * - L'ÉQUIPE de revanche : trainer_pokemon.version la plus forte débloquée par les badges ; sans équipe de
 *   revanche, l'équipe d'origine monte de niveau et évolue (team()).
 *
 * Réglages : config/rematches.php
 */
class RematchService
{
    /** Ce personnage peut-il affronter ce dresseur maintenant ? (premier combat, ou revanche permise) */
    public function canChallenge(Character $character, Trainer $trainer): bool
    {
        // Étape 27 : la Ligue a ses propres règles (dans l'ordre, tous les badges)
        $league = app(LeagueService::class);
        if ($league->isMember($trainer)) {
            return $league->canChallenge($character, $trainer);
        }
        if ($trainer->is_title || $trainer->rematchable || ! $character->hasDefeated($trainer)) {
            return true;
        }

        return $this->wantsRematch($character, $trainer);
    }

    /** Un dresseur déjà battu accepte-t-il une revanche ? (Cherche VS, ou Champion avec tous les badges) */
    public function wantsRematch(Character $character, Trainer $trainer): bool
    {
        if ($trainer->isGymLeader()) {
            return $character->badges()->count() >= (int) config('rematches.leaders_badges', 8);
        }

        return (bool) $character->defeatedTrainers()->whereKey($trainer->id)->first()?->pivot->rematch_ready;
    }

    /** Les dresseurs (slugs) qui attendent une revanche de ce personnage (pour le jeu : ils ne sont plus "battus") */
    public function readySlugs(Character $character): array
    {
        return $character->defeatedTrainers()->wherePivot('rematch_ready', true)->pluck('slug')->all();
    }

    // =====================================================================
    // Le Cherche VS
    // =====================================================================

    public function item(): ?Item
    {
        return Item::where('slug', config('rematches.vs_seeker.item', 'vs-seeker'))->first();
    }

    /** Pas restants avant que la batterie soit chargée (0 = prête) */
    public function charge(Character $character): int
    {
        return max(0, (int) ($character->story_flags['vs_seeker'] ?? 0));
    }

    /**
     * Utiliser le Cherche VS en (x, y) sur la carte. Renvoie ['ready' => [slugs], 'message'] ;
     * lance InvalidAction (batterie vide, pas d'objet...).
     */
    public function use(Character $character, string $mapKey, int $x, int $y): array
    {
        return DB::transaction(function () use ($character, $mapKey, $x, $y) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $item = $this->item();
            if (! $item || ! $character->hasItem($item)) {
                throw new InvalidAction('Vous n\'avez pas de Cherche VS.');
            }
            if (($left = $this->charge($character)) > 0) {
                throw new InvalidAction("La batterie n'est pas encore chargée...\nEncore {$left} pas avant de pouvoir l'utiliser.");
            }
            $map = MapInfo::load($mapKey) ?? throw new InvalidAction('Carte inconnue.');

            // Les dresseurs battus qui sont à l'écran
            [$rangeX, $rangeY] = config('rematches.vs_seeker.range', [8, 6]);
            $onScreen = collect($map->trainers())->filter(fn ($p) => abs($p['x'] - $x) <= $rangeX && abs($p['y'] - $y) <= $rangeY)->keys();
            $trainers = Trainer::whereIn('slug', $onScreen)->where('is_title', false)->whereNull('badge_id')->where('rematchable', false)->get();
            $ready = [];
            foreach ($trainers as $trainer) {
                $pivot = $character->defeatedTrainers()->whereKey($trainer->id)->first()?->pivot;
                if (! $pivot) {
                    continue;   // pas encore battu : il n'a pas besoin du Cherche VS
                }
                if ($pivot->rematch_ready || random_int(1, 100) <= (int) config('rematches.vs_seeker.chance', 75)) {
                    $character->defeatedTrainers()->updateExistingPivot($trainer->id, ['rematch_ready' => true]);
                    $ready[] = $trainer->slug;
                }
            }

            // La batterie se vide (même si personne n'a répondu, comme dans les jeux)
            $character->setFlag('vs_seeker', max(1, (int) config('rematches.vs_seeker.steps', 100)));

            return [
                'ready' => $ready,
                'message' => $ready ? null : 'Personne ne semble vouloir se battre pour l\'instant...',
                'charge' => $this->charge($character),
            ];
        });
    }

    /** Des pas faits par le joueur : la batterie se recharge (appelé avec les autres pas, DaycareController::steps) */
    public function steps(Character $character, int $count): void
    {
        $left = $this->charge($character);
        if ($left > 0 && $count > 0) {
            $character->setFlag('vs_seeker', max(0, $left - $count));
        }
    }

    /** Après une victoire : le dresseur ne demande plus de revanche (jusqu'au prochain Cherche VS) */
    public function defeated(Character $character, Trainer $trainer): void
    {
        $character->defeatedTrainers()->updateExistingPivot($trainer->id, ['rematch_ready' => false]);
    }

    // =====================================================================
    // L'équipe
    // =====================================================================

    /**
     * L'équipe que le dresseur utilise contre ce personnage : des TrainerPokemon (pas forcément enregistrés).
     * Premier combat : la version 0. Revanche : la plus forte version débloquée, sinon la version 0 renforcée.
     *
     * @return Collection<TrainerPokemon>
     */
    public function team(Character $character, Trainer $trainer): Collection
    {
        // Étape 27 : la Ligue devient plus forte après la première victoire
        $league = app(LeagueService::class);
        if ($league->isMember($trainer)) {
            return $league->team($character, $trainer);
        }
        $base = $trainer->pokemon()->where('version', 0)->with('species')->orderBy('slot')->get();
        if ($trainer->is_title || ! $character->hasDefeated($trainer)) {
            return $base;
        }

        $badges = $character->badges()->count();
        $unlocked = collect(config('rematches.versions', []))->filter(fn ($min) => $badges >= $min)->keys();
        $version = $trainer->pokemon()->whereIn('version', $unlocked)->max('version');
        if ($version) {
            return $trainer->pokemon()->where('version', $version)->with('species')->orderBy('slot')->get();
        }

        return $this->stronger($character, $base, $badges);
    }

    /** L'équipe d'origine, renforcée : des niveaux en plus selon les badges, puis les évolutions */
    private function stronger(Character $character, Collection $team, int $badges): Collection
    {
        $bonus = (int) config('rematches.auto.levels_per_badge', 3) * $badges;
        if ($bonus <= 0) {
            return $team;
        }
        $playerMax = (int) $character->party()->where('is_egg', false)->max('level');

        return $team->map(function (TrainerPokemon $tp) use ($bonus, $playerMax) {
            $level = min(100, $tp->level + $bonus);
            if (config('rematches.auto.cap_to_player', true) && $playerMax > 0) {
                $level = max($tp->level, min($level, $playerMax));
            }
            if ($level === $tp->level) {
                return $tp;
            }
            $copy = $tp->replicate();
            $copy->level = $level;
            $species = $this->evolvedSpecies($tp->species, $level);
            $copy->pokemon_species_id = $species->id;
            $copy->setRelation('species', $species);
            $copy->move_ids = null;   // ses attaques : les dernières apprises à son nouveau niveau
            $copy->nickname = $tp->nickname;

            return $copy;
        });
    }

    /** L'espèce après les évolutions possibles à ce niveau (la première branche, s'il y en a plusieurs) */
    public function evolvedSpecies(PokemonSpecies $species, int $level): PokemonSpecies
    {
        $otherLevel = (int) config('rematches.auto.other_evolution_level', 36);
        for ($i = 0; $i < 3; $i++) {
            $evolution = Evolution::where('from_species_id', $species->id)->with('to')->orderBy('id')->get()
                ->first(fn (Evolution $e) => $e->to && ($e->trigger === 'level' && $e->min_level
                    ? $level >= $e->min_level
                    : in_array($e->trigger, ['level', 'item', 'trade', 'friendship'], true) && $level >= $otherLevel));
            if (! $evolution) {
                break;
            }
            $species = $evolution->to;
        }

        return $species;
    }
}
