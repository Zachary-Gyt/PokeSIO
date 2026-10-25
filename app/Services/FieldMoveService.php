<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Battle;
use App\Models\Badge;
use App\Models\Character;
use App\Models\Pokemon;
use App\Models\PokemonMove;

/**
 * Les CAPACITÉS UTILISÉES HORS COMBAT (CS) : Coupe, Éclate-Roc, Force, Surf, Flash, Vol.
 *
 * Le navigateur demande "je veux utiliser Coupe sur la case (12, 8)". Ici on vérifie :
 *   1. qu'un Pokémon de l'équipe connaît la capacité ;
 *   2. que le joueur a le badge demandé (config/field_moves.php) ;
 *   3. que l'endroit s'y prête : un petit arbre sur cette case de la carte, de l'eau devant, une grotte sombre...
 *
 * Ce que le serveur ne peut pas suivre (le rocher poussé par Force) reste dans le navigateur :
 * ça ne change que l'affichage et le chemin du joueur, jamais ses Pokémon, son argent ou ses objets.
 */
class FieldMoveService
{
    public function __construct(private BattleService $battles)
    {
    }

    /**
     * Les capacités de terrain de l'équipe :
     *   ['cut' => ['name' => 'Coupe', 'pokemon' => 'Bulbizarre', 'allowed' => true, 'reason' => null], ...]
     * Seulement celles qu'au moins un Pokémon connaît.
     */
    public function available(Character $character): array
    {
        $result = [];
        foreach (array_keys(config('field_moves.moves')) as $slug) {
            $user = $this->userOf($character, $slug);
            if (! $user) {
                continue;
            }
            $reason = $this->badgeMissing($character, $slug);
            $result[$slug] = [
                'name' => $user['move'],
                'pokemon' => $user['pokemon']->display_name,
                'pokemonId' => $user['pokemon']->id,
                // Image de face, pour le bandeau "Pikachu utilise Surf !"
                'sprite' => $user['pokemon']->species->sprite('front', (bool) $user['pokemon']->is_shiny, $user['pokemon']->gender === 'female'),
                'allowed' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $result;
    }

    /** Les villes déjà visitées où l'on peut se rendre avec Vol */
    public function flyDestinations(Character $character): array
    {
        return collect($character->story_flags['fly'] ?? [])
            ->map(fn ($key) => [$key, MapInfo::load($key)])
            ->filter(fn ($pair) => $pair[1] && $pair[1]->property('fly'))
            ->map(fn ($pair) => [
                'map' => $pair[0],
                'name' => $pair[1]->property('displayName', $pair[0]),
                // Position sur la carte de la région (facultatif, propriétés Tiled town_map_x / town_map_y)
                'townMapX' => $pair[1]->property('town_map_x'),
                'townMapY' => $pair[1]->property('town_map_y'),
            ])
            ->values()->all();
    }

    /**
     * Une ville "fly" visitée est retenue pour Vol (appelé à chaque sauvegarde de position).
     * Propriété de carte Tiled : fly = true.
     */
    public function rememberVisit(Character $character, string $mapKey): void
    {
        $map = MapInfo::load($mapKey);
        $flags = $character->story_flags ?? [];
        if (! $map?->property('fly') || in_array($mapKey, $flags['fly'] ?? [], true)) {
            return;
        }
        $flags['fly'][] = $mapKey;
        $character->forceFill(['story_flags' => $flags])->save();
    }

    /**
     * Utilise une capacité. $data : map, x, y (la case visée) ; pour Vol : to (la ville).
     * Renvoie ce que le jeu doit savoir (le texte à afficher, la destination de Vol, un combat...).
     */
    public function use(Character $character, string $slug, array $data): array
    {
        if (! array_key_exists($slug, config('field_moves.moves'))) {
            throw new InvalidAction('Cette capacité ne s\'utilise pas hors combat.');
        }
        $user = $this->userOf($character, $slug)
            ?? throw new InvalidAction('Aucun Pokémon de votre équipe ne connaît cette capacité.');
        if ($reason = $this->badgeMissing($character, $slug)) {
            throw new InvalidAction($reason);
        }
        if ($this->inBattle($character)) {
            throw new InvalidAction('Impossible pendant un combat.');
        }

        $map = MapInfo::load((string) ($data['map'] ?? ''))
            ?? throw new InvalidAction('Carte inconnue.');
        $x = (int) ($data['x'] ?? -1);
        $y = (int) ($data['y'] ?? -1);
        $pokemon = $user['pokemon']->display_name;
        $text = "{$pokemon} utilise {$user['move']} !";

        switch ($slug) {
            case 'cut':
                $this->expect($map->fieldObjectAt($x, $y) === 'cut', 'Il n\'y a rien à couper ici.');
                return ['text' => $text];

            case 'rock-smash':
                $this->expect($map->fieldObjectAt($x, $y) === 'rock_smash', 'Il n\'y a rien à briser ici.');
                // Parfois, un Pokémon sauvage se cachait sous le rocher
                $battle = random_int(1, 100) <= config('field_moves.rock_smash_encounter_chance', 25)
                    ? $this->battles->startFieldEncounter($character, $data['map'], $x, $y, 'rock_smash')
                    : null;
                return ['text' => $text, 'battle' => $battle];

            case 'strength':
                $this->expect($map->hasFieldObject('strength'), 'Il n\'y a aucun rocher à déplacer ici.');
                return ['text' => "{$text}\n{$pokemon} peut maintenant déplacer les rochers."];

            case 'surf':
                $this->expect($map->tileHasProperty($x, $y, 'surf'), 'On ne peut pas surfer ici.');
                return ['text' => "{$text}"];

            case 'flash':
                $this->expect((bool) $map->property('dark'), 'Il fait déjà assez clair ici.');
                return ['text' => "{$text}\nLa grotte est maintenant éclairée !"];

            case 'fly':
                $this->expect(! $map->property('no_fly'), 'Impossible de s\'envoler ici.');
                $to = (string) ($data['to'] ?? '');
                $this->expect(in_array($to, $character->story_flags['fly'] ?? [], true), 'Vous n\'avez pas encore visité cette ville.');
                $destination = MapInfo::load($to) ?? throw new InvalidAction('Ville inconnue.');
                $spot = $this->flySpot($destination);
                $character->forceFill(['current_map' => $to, 'position_x' => $spot['x'], 'position_y' => $spot['y'], 'facing' => 'down'])->save();

                return ['text' => $text, 'fly' => ['map' => $to, 'x' => $spot['x'], 'y' => $spot['y'], 'facing' => 'down']];
        }

        throw new InvalidAction('Capacité inconnue.');
    }

    // =====================================================================

    /** Le premier Pokémon de l'équipe qui connaît la capacité : ['pokemon' => Pokemon, 'move' => 'Coupe'] ou null */
    private function userOf(Character $character, string $slug): ?array
    {
        $party = $character->battleParty()->with('species', 'moves.move')->get(); // étape 18 : sans les Œufs
        foreach ($party as $pokemon) {
            /** @var Pokemon $pokemon */
            $known = $pokemon->moves->first(fn (PokemonMove $pm) => $pm->move?->slug === $slug);
            if ($known) {
                return ['pokemon' => $pokemon, 'move' => $known->move->name];
            }
        }

        return null;
    }

    /** null si le badge est là (ou si le réglage est coupé), sinon le message d'explication */
    private function badgeMissing(Character $character, string $slug): ?string
    {
        $order = config("field_moves.moves.{$slug}");
        if (! config('field_moves.require_badges') || ! $order) {
            return null;
        }
        if ($character->badges()->where('order', $order)->exists()) {
            return null;
        }
        $badge = Badge::where('order', $order)->value('name') ?? "badge n°{$order}";

        return "Il faut le {$badge} pour utiliser cette capacité hors combat.";
    }

    private function inBattle(Character $character): bool
    {
        return Battle::involving($character)->active()->exists();
    }

    /** Point d'arrivée de Vol : propriétés de carte fly_x / fly_y, sinon le point d'apparition */
    private function flySpot(MapInfo $map): array
    {
        if ($map->property('fly_x') !== null && $map->property('fly_y') !== null) {
            return ['x' => (int) $map->property('fly_x'), 'y' => (int) $map->property('fly_y')];
        }

        return $map->spawn() ?? ['x' => 0, 'y' => 0];
    }

    private function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidAction($message);
        }
    }
}
