<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Nature;
use App\Models\Pokemon;
use App\Models\PokemonSpecies;

/**
 * Fabrique de Pokémon : TOUS les Pokémon du jeu naissent ici
 * (starter, rencontre sauvage, cadeau d'un PNJ, œuf plus tard...).
 *
 * Utilisation :
 *   $pokemon = $factory->make($species, 5);                  // tire IV, nature, talent... (pas encore en base)
 *   $factory->giveTo($character, $pokemon, [...]);           // le range dans l'équipe ou au PC
 *
 * Laravel injecte automatiquement ce service dans un contrôleur :
 *   public function store(Request $request, PokemonFactory $factory)
 */
class PokemonFactory
{
    /**
     * Crée un Pokémon en mémoire (non enregistré).
     *
     * $options permet de forcer certaines valeurs :
     *   'nature' => Nature, 'ability' => Ability, 'gender' => 'female',
     *   'shiny' => true, 'ivs' => ['speed' => 31], 'nickname' => 'Pika'
     */
    public function make(PokemonSpecies $species, int $level, array $options = []): Pokemon
    {
        $level = max(1, min(Pokemon::MAX_LEVEL, $level));
        $species->loadMissing('abilities');

        $nature = $options['nature'] ?? Nature::inRandomOrder()->first();
        $ability = $options['ability'] ?? $species->randomAbility();

        $pokemon = new Pokemon([
            'pokemon_species_id' => $species->id,
            'nickname' => $options['nickname'] ?? null,
            'level' => $level,
            'experience' => $species->experienceForLevel($level),
            'friendship' => $species->base_friendship,
            'gender' => $options['gender'] ?? $species->randomGender(),
            'is_shiny' => $options['shiny'] ?? random_int(1, config('game.shiny_odds', 4096)) === 1,
            'nature_id' => $nature->id,
            'ability_id' => $ability->id,
            'status' => 'none',
            'sleep_turns' => 0,
        ]);

        foreach (PokemonSpecies::STATS as $stat) {
            $pokemon->{"iv_{$stat}"} = $options['ivs'][$stat] ?? random_int(0, Pokemon::MAX_IV);
            $pokemon->{"ev_{$stat}"} = 0;
        }

        // On attache les relations déjà chargées : le calcul des stats n'a pas besoin de requête
        $pokemon->setRelation('species', $species);
        $pokemon->setRelation('nature', $nature);

        $pokemon->current_hp = $pokemon->max_hp; // PV pleins

        return $pokemon;
    }

    /**
     * Donne un Pokémon à un personnage : équipe s'il reste de la place, sinon PC.
     * Enregistre le Pokémon, lui apprend ses attaques et met à jour le Pokédex.
     *
     * $origin : 'ball_id' (Item id), 'location' (texte), 'trainer_id' et 'trainer_name' (dresseur d'origine)
     *
     * Renvoie null si l'équipe ET le PC sont pleins.
     */
    public function giveTo(Character $character, Pokemon $pokemon, array $origin = []): ?Pokemon
    {
        $place = $character->freeSlotForNewPokemon();
        if ($place === null) {
            return null;
        }

        $pokemon->fill($place + [
            'original_trainer_id' => $origin['trainer_id'] ?? $character->trainer_id,
            'original_trainer_name' => $origin['trainer_name'] ?? $character->name,
            'caught_ball_id' => $origin['ball_id'] ?? null,
            'met_location' => $origin['location'] ?? null,
            'met_level' => $pokemon->level,
            'met_at' => now(),
        ]);

        // character_id est protégé (guarded) : on passe par la relation
        $pokemon->character()->associate($character);
        $pokemon->save();

        $this->teachStartingMoves($pokemon);
        if (! $pokemon->is_egg) {   // un Œuf : le Pokémon n'est connu qu'à l'éclosion
            $character->registerInPokedex($pokemon->pokemon_species_id, caught: true);
        }

        return $pokemon;
    }

    /**
     * Apprend au Pokémon les 4 dernières attaques de son learnset jusqu'à son niveau,
     * comme un Pokémon sauvage dans les jeux.
     */
    public function teachStartingMoves(Pokemon $pokemon): void
    {
        $moves = $pokemon->species
            ->levelUpMovesUpTo($pokemon->level)
            ->unique('id')
            ->take(-4)
            ->values();

        $pokemon->moves()->delete();

        foreach ($moves as $index => $move) {
            $pokemon->moves()->create([
                'move_id' => $move->id,
                'slot' => $index + 1,
                'current_pp' => $move->pp,
                'pp_ups' => 0,
            ]);
        }
    }
}
