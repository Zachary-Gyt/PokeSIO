<?php

namespace App\Services;

use App\Models\Character;
use App\Models\HallOfFameEntry;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Support\Collection;

/**
 * Étape 27 : la LIGUE POKÉMON (le Conseil 4 puis le Maître) et le PANTHÉON.
 *
 * Les règles des jeux :
 *   - il faut tous les badges (config league.badges) pour entrer ;
 *   - les membres (config league.members) se battent DANS L'ORDRE, l'un après l'autre ;
 *   - une défaite, ou une sauvegarde hors des cartes de la Ligue (on est ressorti), fait tout recommencer ;
 *   - battre le Maître : l'équipe entre au Panthéon (table hall_of_fame_entries) ;
 *   - après une première victoire, la Ligue utilise ses équipes "version 1" (plus fortes), si elles existent.
 *
 * La progression de la tentative en cours : characters.story_flags['league'] = nombre de membres battus.
 */
class LeagueService
{
    /** @return string[] les slugs des membres, dans l'ordre */
    public function members(): array
    {
        return array_values(config('league.members', []));
    }

    /** Position du dresseur dans la Ligue (0 = premier membre), ou null s'il n'en fait pas partie */
    public function index(Trainer|string $trainer): ?int
    {
        $slug = $trainer instanceof Trainer ? $trainer->slug : $trainer;
        $index = array_search($slug, $this->members(), true);

        return $index === false ? null : $index;
    }

    public function isMember(Trainer|string $trainer): bool
    {
        return $this->index($trainer) !== null;
    }

    /** Membres battus pendant la tentative en cours (0 à 4 ; 5 n'arrive jamais : le Maître battu, on recommence à 0) */
    public function progress(Character $character): int
    {
        return max(0, (int) ($character->story_flags['league'] ?? 0));
    }

    public function hasBadges(Character $character): bool
    {
        return $character->badges()->count() >= (int) config('league.badges', 8);
    }

    /** Ce membre peut-il être affronté maintenant ? (tous les badges, et c'est son tour) */
    public function canChallenge(Character $character, Trainer $trainer): bool
    {
        return $this->hasBadges($character) && $this->index($trainer) === $this->progress($character);
    }

    /** Nombre d'entrées au Panthéon de ce personnage */
    public function wins(Character $character): int
    {
        return HallOfFameEntry::where('character_id', $character->id)->count();
    }

    /** L'équipe du membre : la version de revanche après une première victoire (si elle existe), sinon l'équipe d'origine */
    public function team(Character $character, Trainer $trainer): Collection
    {
        $version = (int) config('league.rematch_version', 1);
        if ($version > 0 && $this->wins($character) > 0 && $trainer->pokemon()->where('version', $version)->exists()) {
            return $trainer->pokemon()->where('version', $version)->with('species')->orderBy('slot')->get();
        }

        return $trainer->pokemon()->where('version', 0)->with('species')->orderBy('slot')->get();
    }

    /**
     * Fin d'un combat contre un membre. Renvoie ce que le jeu doit savoir :
     *   ['progress' => n, 'members' => [...], 'hallOfFame' => entrée du Panthéon (Maître battu) ou absent]
     */
    public function afterBattle(Character $character, Trainer $trainer, string $result): array
    {
        $index = $this->index($trainer);
        $info = ['members' => $this->members()];
        if ($result !== 'win') {
            $this->reset($character);

            return $info + ['progress' => 0];
        }
        if ($index === count($this->members()) - 1) {
            $entry = $this->enterHallOfFame($character);
            $this->reset($character);

            return $info + ['progress' => 0, 'hallOfFame' => $entry->present() + ['after' => config('league.after')]];
        }
        $character->setFlag('league', $index + 1);

        return $info + ['progress' => $index + 1];
    }

    /** La tentative est perdue (défaite, ou le joueur est ressorti de la Ligue) */
    public function reset(Character $character): void
    {
        if ($this->progress($character) > 0) {
            $character->setFlag('league', 0);
        }
    }

    /** Sauvegarde sur une carte : hors de la Ligue, la tentative en cours est perdue */
    public function rememberMap(Character $character, string $mapKey): void
    {
        if ($this->progress($character) > 0 && ! in_array($mapKey, config('league.maps', []), true)) {
            $this->reset($character);
        }
    }

    /** L'équipe entre au Panthéon : une "photo" de chaque Pokémon (hors Œufs) */
    public function enterHallOfFame(Character $character): HallOfFameEntry
    {
        $team = $character->party()->where('is_egg', false)->with('species')->get()->map(fn ($p) => [
            'speciesId' => $p->pokemon_species_id,
            'slug' => $p->species->slug,
            'name' => $p->display_name,
            'speciesName' => $p->species->name,
            'level' => $p->level,
            'gender' => $p->gender,
            'shiny' => (bool) $p->is_shiny,
            'sprite' => $p->species->sprite('front', (bool) $p->is_shiny, $p->gender === 'female'),
            'icon' => $p->species->sprite('icon'),
            'cry' => PokemonSpecies::assetUrl($p->species->cry),
        ])->values()->all();

        return HallOfFameEntry::create([
            'character_id' => $character->id,
            'number' => $this->wins($character) + 1,
            'trainer_name' => $character->name,
            'avatar' => $character->avatarKey(),
            'team' => $team,
            'play_time' => (int) $character->play_time,
            'entered_at' => now(),
        ]);
    }
}
