<?php

namespace App\Services;

use App\Console\Commands\ImportEssentialsSprites;
use App\Models\Item;
use App\Models\Pokemon;
use App\Models\PokemonMove;
use App\Models\PokemonSpecies;

/**
 * Un Pokémon du joueur, "mis en forme" pour les écrans du jeu (équipe, résumé, PC).
 * Tout ce que l'écran Résumé d'Essentials affiche : stats, nature, talent, attaques, dresseur d'origine...
 */
class PokemonPresenter
{
    /** Relations à précharger pour éviter des centaines de requêtes */
    public const RELATIONS = [
        'species.primaryType', 'species.secondaryType', 'nature', 'ability', 'heldItem', 'caughtBall', 'moves.move.type',
    ];

    public static function detail(Pokemon $p): array
    {
        $p->loadMissing(self::RELATIONS);
        if ($p->is_egg) {
            return self::egg($p);   // étape 18
        }
        $species = $p->species;
        $female = $p->gender === 'female';

        return [
            'id' => $p->id,
            'egg' => false,
            'speciesId' => $species->id,
            'dex' => $species->pokedex_number,
            'name' => $p->display_name,
            'nickname' => $p->nickname,
            'speciesName' => $species->name,
            'level' => $p->level,
            'gender' => $p->gender,
            'shiny' => (bool) $p->is_shiny,
            'types' => $species->types()->pluck('slug')->all(),
            'hp' => $p->current_hp,
            'maxHp' => $p->max_hp,
            'status' => $p->status,
            'stats' => $p->stats(),
            'nature' => [
                'name' => $p->nature?->name,
                'up' => $p->nature?->increased_stat,
                'down' => $p->nature?->decreased_stat,
            ],
            'ability' => ['name' => $p->ability?->name, 'description' => self::clean($p->ability?->description)],
            'item' => $p->heldItem ? ['id' => $p->heldItem->id, 'name' => $p->heldItem->name, 'icon' => self::itemIcon($p->heldItem)] : null,
            'exp' => $p->experience,
            'levelExp' => $species->experienceForLevel($p->level),
            'nextLevelExp' => $p->level >= Pokemon::MAX_LEVEL ? $p->experience : $species->experienceForLevel($p->level + 1),
            'moves' => $p->moves->map(fn (PokemonMove $pm) => self::move($pm))->values()->all(),
            'ot' => ['name' => $p->original_trainer_name, 'id' => $p->original_trainer_id],
            'met' => [
                'location' => $p->met_location,
                'level' => $p->met_level,
                'date' => $p->met_at?->format('d/m/Y'),
            ],
            'ball' => $p->caughtBall?->slug ?? 'poke-ball',
            'location' => $p->location,
            'partySlot' => $p->party_slot,
            'box' => $p->box_number,
            'boxSlot' => $p->box_slot,
            'sprites' => [
                'front' => $species->sprite('front', $p->is_shiny, $female),
                'back' => $species->sprite('back', $p->is_shiny, $female),
                'icon' => $species->sprite('icon'),
                'overworld' => $species->sprite('overworld', $p->is_shiny),   // étape 11 : le Pokémon qui suit
                'cry' => PokemonSpecies::assetUrl($species->cry),
            ],
        ];
    }

    /**
     * Étape 18 : un ŒUF. On ne montre ni l'espèce, ni les stats, ni les attaques (surprise !),
     * seulement où il en est ("Ça bouge un peu...") et d'où il vient. Les clés restent les mêmes
     * que pour un Pokémon : les écrans du jeu n'ont qu'à regarder "egg".
     */
    public static function egg(Pokemon $p): array
    {
        $steps = (int) $p->egg_steps;
        $state = match (true) {
            $steps < 1275 => 'On entend des bruits à l\'intérieur ! Il va bientôt éclore !',
            $steps < 2550 => 'Il bouge de temps en temps. Il devrait bientôt éclore.',
            $steps < 10200 => 'Qu\'est-ce qui va en sortir ? Il n\'a pas l\'air d\'être près d\'éclore.',
            default => 'Il semble que cet Œuf mettra encore longtemps à éclore.',
        };
        $egg = asset('game/sprites/pokemon/eggs/000.png');

        return [
            'id' => $p->id,
            'egg' => true,
            'eggState' => $state,
            'speciesId' => null,
            'dex' => null,
            'name' => 'Œuf',
            'nickname' => null,
            'speciesName' => 'Œuf',
            'level' => null,
            'gender' => null,
            'shiny' => false,
            'types' => [],
            'hp' => 0,
            'maxHp' => 0,
            'status' => 'none',
            'stats' => [],
            'nature' => ['name' => null, 'up' => null, 'down' => null],
            'ability' => ['name' => null, 'description' => null],
            'item' => null,
            'exp' => 0,
            'levelExp' => 0,
            'nextLevelExp' => 0,
            'moves' => [],
            'ot' => ['name' => $p->original_trainer_name, 'id' => $p->original_trainer_id],
            'met' => [
                'location' => $p->met_location,
                'level' => null,
                'date' => $p->met_at?->format('d/m/Y'),
            ],
            'ball' => 'poke-ball',
            'location' => $p->location,
            'partySlot' => $p->party_slot,
            'box' => $p->box_number,
            'boxSlot' => $p->box_slot,
            'sprites' => [
                'front' => $egg,
                'back' => $egg,
                'icon' => asset('game/sprites/pokemon/eggs/000_icon.png'),
                'overworld' => null,
                'cry' => null,
            ],
        ];
    }

    public static function move(PokemonMove $pm): array
    {
        $m = $pm->move;

        return [
            'id' => $m->id,
            'slug' => $m->slug,          // étape 11 : "cut", "surf"... (capacités de terrain dans le menu)
            'slot' => $pm->slot,
            'name' => $m->name,
            'type' => $m->type?->slug,
            'category' => $m->category,
            'power' => $m->power,
            'accuracy' => $m->accuracy,
            'pp' => $pm->current_pp,
            'maxPp' => $pm->max_pp,
            'description' => self::clean($m->description),
            'hm' => \App\Models\Move::isLockedHm($m->id),   // étape 19 : CS (ne s'oublie que chez le Maître des capacités)
        ];
    }

    /** Une attaque pas encore connue (à apprendre) */
    public static function newMove(\App\Models\Move $m): array
    {
        $m->loadMissing('type');

        return [
            'id' => $m->id,
            'name' => $m->name,
            'type' => $m->type?->slug,
            'category' => $m->category,
            'power' => $m->power,
            'accuracy' => $m->accuracy,
            'pp' => $m->pp,
            'maxPp' => $m->pp,
            'description' => self::clean($m->description),
        ];
    }

    /** Nom du fichier d'icône d'objet d'Essentials : "super-potion" -> SUPERPOTION */
    public static function itemIcon(Item $item): string
    {
        // CT / CS : icône du disque de la couleur du type (machine_ROCK, machine_hm_WATER...)
        if ($item->effect_code === 'teach' && $item->move_id) {
            $type = strtoupper($item->move?->type?->slug ?? 'normal');
            $hm = str_starts_with($item->slug, 'hm') && in_array($type, ['FIGHTING', 'FLYING', 'NORMAL', 'WATER'], true);

            return $hm ? "machine_hm_{$type}" : "machine_{$type}";
        }

        return ImportEssentialsSprites::essentialsKey($item->slug);
    }

    /** Textes PokéAPI : retours à la ligne et tirets de césure parasites */
    public static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', str_replace(["\u{AD}", "\f"], ['', ' '], $text)));
    }
}
