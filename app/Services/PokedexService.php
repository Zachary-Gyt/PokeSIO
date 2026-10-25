<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\WildEncounter;

/**
 * Le POKÉDEX du joueur (étape 13) : ce qu'il a vu et capturé, et les récompenses du Professeur.
 *
 * Rien sur un Pokémon jamais vu ne part vers le navigateur : son nom, son image et ses infos
 * restent sur le serveur. Un Pokémon vu montre son nom, ses types, son image et ses lieux ;
 * un Pokémon capturé montre en plus sa catégorie, sa taille, son poids et le texte du Pokédex.
 */
class PokedexService
{
    public const METHODS = [
        'grass' => 'Hautes herbes', 'cave' => 'Grotte', 'surf' => 'Surf', 'rock_smash' => 'Éclate-Roc',
        'old_rod' => 'Canne', 'good_rod' => 'Super Canne', 'super_rod' => 'Méga Canne', 'headbutt' => 'Coup d\'Boule',
    ];

    public const TIMES = ['morning' => 'le matin', 'day' => 'le jour', 'night' => 'la nuit'];

    /** La liste : tous les Pokémon disponibles, avec ce que le joueur en sait */
    public function list(Character $character): array
    {
        $entries = $character->pokedexEntries()->get()->keyBy('pokemon_species_id');
        $species = PokemonSpecies::obtainable()->with('primaryType', 'secondaryType')->orderBy('pokedex_number')->get();

        $list = $species->map(function (PokemonSpecies $s) use ($entries) {
            $entry = $entries[$s->id] ?? null;
            $row = ['id' => $s->id, 'number' => $s->pokedex_number, 'state' => $entry ? ($entry->caught ? 'caught' : 'seen') : 'none'];
            if ($entry) {
                $row += [
                    'name' => $s->name,
                    'types' => collect([$s->primaryType, $s->secondaryType])->filter()->pluck('slug')->all(),
                    'sprite' => $s->sprite('front'),
                ];
            }

            return $row;
        })->values();

        return [
            'list' => $list->all(),
            'seen' => $list->where('state', '!=', 'none')->count(),
            'caught' => $list->where('state', 'caught')->count(),
            'total' => $list->count(),
            'types' => \App\Models\Type::orderBy('name')->get(['slug', 'name', 'color'])->all(),
        ];
    }

    /** La fiche d'un Pokémon vu (null s'il n'a jamais été vu) */
    public function detail(Character $character, PokemonSpecies $species): ?array
    {
        $entry = $character->pokedexEntries()->where('pokemon_species_id', $species->id)->first();
        if (! $entry) {
            return null;
        }
        $species->loadMissing('primaryType', 'secondaryType');
        $caught = (bool) $entry->caught;

        return [
            'id' => $species->id,
            'number' => $species->pokedex_number,
            'name' => $species->name,
            'caught' => $caught,
            'types' => collect([$species->primaryType, $species->secondaryType])->filter()
                ->map(fn ($t) => ['slug' => $t->slug, 'name' => $t->name, 'color' => $t->color])->values()->all(),
            'sprite' => $species->sprite('front'),
            'cry' => PokemonSpecies::assetUrl($species->cry),
            // Seulement pour un Pokémon capturé, comme dans les jeux
            'category' => $caught ? $species->category : null,
            'height' => $caught ? $species->height / 10 : null,     // en mètres
            'weight' => $caught ? $species->weight / 10 : null,     // en kilos
            'entry' => $caught ? $species->pokedex_entry : null,
            'footprint' => $caught ? PokemonSpecies::assetUrl($species->sprite_footprint) : null,
            'areas' => $this->areas($species),
        ];
    }

    /** Où trouver le Pokémon : les tables de rencontres sauvages, regroupées par carte */
    public function areas(PokemonSpecies $species): array
    {
        return WildEncounter::where('pokemon_species_id', $species->id)->get()
            ->groupBy('map_key')
            ->map(fn ($rows, $map) => [
                'map' => $map,
                'name' => MapInfo::load($map)?->property('displayName') ?? $map,
                'levels' => $rows->min('min_level') === $rows->max('max_level')
                    ? 'N. ' . $rows->min('min_level')
                    : 'N. ' . $rows->min('min_level') . '-' . $rows->max('max_level'),
                'methods' => $rows->pluck('method')->unique()->map(fn ($m) => self::METHODS[$m] ?? $m)->values()->all(),
                'times' => $rows->pluck('time_of_day')->filter()->unique()->map(fn ($t) => self::TIMES[$t] ?? $t)->values()->all(),
            ])
            ->sortBy('name')->values()->all();
    }

    // =====================================================================
    // Récompenses du Professeur (config/pokedex.php)
    // =====================================================================

    /**
     * Donne les récompenses des paliers atteints et pas encore reçus.
     * Appelé à chaque nouvelle capture (Character::registerInPokedex). Les messages à afficher
     * sont gardés dans story_flags['dex_pending'] jusqu'à ce que le jeu les ait montrés.
     */
    public function checkRewards(Character $character): void
    {
        $caught = $character->pokedexEntries()->where('caught', true)
            ->whereHas('species', fn ($q) => $q->obtainable())->count();
        $total = PokemonSpecies::obtainable()->count();
        $flags = $character->story_flags ?? [];
        $claimed = $flags['dex_rewards'] ?? [];
        $changed = false;

        foreach (config('pokedex.rewards', []) as $tier => $reward) {
            $needed = $tier === 'complete' ? $total : (int) $tier;
            $key = (string) $tier;
            if ($caught < $needed || in_array($key, $claimed, true)) {
                continue;
            }
            $claimed[] = $key;
            $flags['dex_pending'][] = ['tier' => $key, 'count' => $needed, 'gifts' => $this->give($character, $reward)];
            $changed = true;
        }

        if ($changed) {
            $flags['dex_rewards'] = $claimed;
            $character->forceFill(['story_flags' => $flags])->save();
        }
    }

    /** Les messages de récompense pas encore montrés au joueur */
    public function pending(Character $character): array
    {
        return $character->story_flags['dex_pending'] ?? [];
    }

    /** Le jeu a montré les messages : on les oublie */
    public function acknowledge(Character $character): void
    {
        $flags = $character->story_flags ?? [];
        unset($flags['dex_pending']);
        $character->forceFill(['story_flags' => $flags])->save();
    }

    /** Ajoute l'argent et les objets ; renvoie la liste lisible ("10 Poké Ball", "3 000 ₽") */
    private function give(Character $character, array $reward): array
    {
        $gifts = [];
        foreach ($reward['items'] ?? [] as $slug => $quantity) {
            $item = Item::where('slug', $slug)->first();
            if (! $item) {
                continue;
            }
            $character->addItem($item, (int) $quantity);
            $gifts[] = ((int) $quantity > 1 ? $quantity . ' ' : '') . $item->name;
        }
        if (! empty($reward['money'])) {
            $character->addMoney((int) $reward['money']);
            $gifts[] = number_format((int) $reward['money'], 0, ',', ' ') . ' ₽';
        }

        return $gifts;
    }
}
