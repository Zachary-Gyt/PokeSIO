<?php

namespace App\Http\Controllers;

use App\Models\Ability;
use App\Models\Evolution;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Models\Type;
use App\Models\TypeEfficacy;

/**
 * Wiki : les rubriques de RÉFÉRENCE, construites à partir de la base de données
 * (elles restent justes quand tu ajoutes des Pokémon) : Talents, Capacités, Types, Évolutions.
 */
class WikiReferenceController extends Controller
{
    /** Talents qui agissent aussi hors combat, quand le premier Pokémon de l'équipe les a (FieldAbilityService) */
    public const FIELD_EFFECTS = [
        'intimidate' => 'Hors combat : les Pokémon sauvages bien plus faibles fuient une fois sur deux.',
        'keen-eye' => 'Hors combat : les Pokémon sauvages bien plus faibles fuient une fois sur deux.',
        'stench' => 'Hors combat : une rencontre sur deux évitée.',
        'white-smoke' => 'Hors combat : une rencontre sur deux évitée.',
        'quick-feet' => 'Hors combat : une rencontre sur deux évitée.',
        'sand-veil' => 'Hors combat : une rencontre sur deux évitée dans une tempête de sable.',
        'snow-cloak' => 'Hors combat : une rencontre sur deux évitée sous la grêle.',
        'static' => 'Hors combat : une fois sur deux, un Pokémon Électrik de la zone.',
        'magnet-pull' => 'Hors combat : une fois sur deux, un Pokémon Acier de la zone.',
        'hustle' => 'Hors combat : une fois sur deux, le Pokémon sauvage a le niveau le plus haut de la zone.',
        'pressure' => 'Hors combat : une fois sur deux, le Pokémon sauvage a le niveau le plus haut de la zone.',
        'vital-spirit' => 'Hors combat : une fois sur deux, le Pokémon sauvage a le niveau le plus haut de la zone.',
        'synchronize' => 'Hors combat : une fois sur deux, le Pokémon sauvage a la même nature.',
        'cute-charm' => 'Hors combat : 2 fois sur 3, le Pokémon sauvage est du sexe opposé.',
        'pickup' => 'Hors combat : après une victoire, peut ramasser un objet (s\'il ne tient rien).',
        'flame-body' => 'Hors combat : les Œufs de l\'équipe éclosent 2 fois plus vite.',
        'magma-armor' => 'Hors combat : les Œufs de l\'équipe éclosent 2 fois plus vite.',
        'run-away' => 'Fuite assurée face à un Pokémon sauvage, même piégé.',
    ];

    /** Talents sans effet ici : le système dont ils ont besoin n'existe pas (encore) */
    public const NOT_HERE = [
        'plus' => 'combats doubles', 'minus' => 'combats doubles', 'oblivious' => 'Attraction', 'cute-charm' => 'Attraction (en combat)',
        'suction-cups' => 'capacités qui forcent le changement', 'sticky-hold' => 'capacités qui volent l\'objet',
        'multitype' => 'formes d\'Arceus', 'honey-gather' => 'Miel', 'illuminate' => 'plus de rencontres',
    ];

    public function abilities()
    {
        $abilities = Ability::whereHas('species', fn ($q) => $q->obtainable())
            ->with(['species' => fn ($q) => $q->obtainable()->orderBy('pokedex_number')])
            ->orderBy('name')->get();

        return view('wiki.abilities', ['abilities' => $abilities]);
    }

    public function moves()
    {
        $moves = Move::whereHas('species', fn ($q) => $q->obtainable())->with('type')->orderBy('name')->get();

        return view('wiki.moves', [
            'moves' => $moves,
            'types' => Type::whereIn('id', $moves->pluck('type_id')->unique())->orderBy('name')->get(),
        ]);
    }

    public function move(string $slug)
    {
        $move = Move::where('slug', $slug)->with('type')->firstOrFail();
        $learners = $move->species()->obtainable()->orderBy('pokedex_number')->get()->groupBy('pivot.learn_method');

        return view('wiki.move', ['move' => $move, 'learners' => $learners]);
    }

    public function types()
    {
        $types = Type::whereNotIn('slug', ['unknown', 'shadow', 'stellar'])->orderBy('id')->get();
        $chart = [];
        foreach (TypeEfficacy::all() as $row) {
            $chart[$row->attacking_type_id][$row->defending_type_id] = (float) $row->multiplier;
        }

        return view('wiki.types', ['types' => $types, 'chart' => $chart]);
    }

    /** Toutes les évolutions des Pokémon du jeu, rangées par méthode */
    public function evolutions()
    {
        $all = Evolution::with('from', 'to', 'item', 'heldItem', 'knownMove', 'partySpecies')->get()
            ->filter(fn ($e) => $e->from?->is_obtainable && $e->to?->is_obtainable)
            ->sortBy(fn ($e) => $e->from->pokedex_number * 1000 + $e->to->pokedex_number);

        $groups = [
            'Niveau' => fn ($e) => $e->trigger === 'level' && $e->min_level && ! $e->held_item_id && ! $e->known_move_id && ! $e->party_species_id && ! $e->location && $e->relative_stats === null && ! $e->time_of_day && ! $e->gender,
            'Niveau, avec une condition (sexe, Attaque / Défense)' => fn ($e) => $e->trigger === 'level' && $e->min_level && ($e->gender || $e->relative_stats !== null),
            'Pierre ou objet utilisé' => fn ($e) => $e->trigger === 'item',
            'Bonheur' => fn ($e) => $e->trigger === 'friendship',
            'Objet tenu en montant de niveau' => fn ($e) => $e->trigger === 'level' && $e->held_item_id,
            'Capacité connue' => fn ($e) => $e->trigger === 'level' && $e->known_move_id,
            'Pokémon dans l\'équipe' => fn ($e) => $e->trigger === 'level' && $e->party_species_id,
            'Lieu spécial' => fn ($e) => $e->trigger === 'level' && $e->location,
            'Échange' => fn ($e) => $e->trigger === 'trade',
            'Cas spéciaux' => fn ($e) => $e->trigger === 'shed',
        ];
        $sections = collect($groups)->map(fn ($test) => $all->filter($test)->values());
        $placed = $sections->flatten()->pluck('id')->all();
        $sections['Autres'] = $all->reject(fn ($e) => in_array($e->id, $placed, true))->values();
        $sections = $sections->filter(fn ($list) => $list->isNotEmpty());

        return view('wiki.evolutions', ['sections' => $sections, 'areas' => Evolution::AREAS]);
    }
}
