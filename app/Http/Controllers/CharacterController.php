<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Services\PokemonFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Les personnages d'un compte : emplacements, création, fiche, suppression.
 */
class CharacterController extends Controller
{
    /** Écran des 3 emplacements */
    public function index(Request $request)
    {
        $characters = $request->user()
            ->characters()
            ->with('party.species')
            ->withCount(['badges', 'pokedexEntries as caught_count' => fn ($q) => $q->where('caught', true)])
            ->get()
            ->keyBy('slot');

        // Le nom de la carte où chaque personnage s'est arrêté (propriété Tiled "displayName")
        foreach ($characters as $character) {
            $character->map_name = \App\Services\MapInfo::load($character->current_map)?->property('displayName') ?? $character->current_map;
        }

        return view('dashboard', [
            'user' => $request->user(),
            'characters' => $characters,
        ]);
    }

    /** Formulaire de création pour un emplacement vide */
    public function create(Request $request, int $slot)
    {
        if ($slot < 1 || $slot > Character::MAX_PER_USER || $request->user()->characters()->where('slot', $slot)->exists()) {
            return redirect()->route('dashboard');
        }

        // Starters des générations jouables, regroupés par génération : [1 => [Bulbizarre, ...], 2 => [...]]
        $startersByGeneration = PokemonSpecies::starters()
            ->obtainable()
            ->with('primaryType', 'secondaryType')
            ->get()
            ->groupBy('generation')
            ->sortKeys();

        return view('characters.create', [
            'slot' => $slot,
            'startersByGeneration' => $startersByGeneration,
            'regions' => config('game.regions'),
        ]);
    }

    /** Création du personnage et de son premier Pokémon */
    public function store(Request $request, PokemonFactory $factory)
    {
        $user = $request->user();

        $data = $request->validate([
            'slot' => [
                'required', 'integer', 'between:1,' . Character::MAX_PER_USER,
                // Emplacement encore libre pour CE compte
                Rule::unique('characters')->where('user_id', $user->id),
            ],
            'name' => [
                'required', 'string', 'max:' . config('game.name_max_length'),
                // Lettres (accents compris), chiffres, espace, tiret, apostrophe et point
                'regex:/^[\pL\pN][\pL\pN \-\'.]*$/u',
                // Un nom UNIQUE dans tout le jeu (sans tenir compte des majuscules : "Sacha" = "sacha")
                function (string $attribute, mixed $value, \Closure $fail) {
                    $name = mb_strtolower(trim((string) $value));
                    if (Character::whereRaw('LOWER(TRIM(name)) = ?', [$name])->exists()) {
                        $fail('Désolé, ce nom est déjà utilisé.');
                    }
                },
            ],
            // Étape 17 : un des personnages de config/avatars.php (Red, Leaf, Brendan, May)
            'avatar' => ['required', Rule::in(array_keys(config('avatars', [])))],
            'starter_species_id' => [
                'required',
                // Le starter doit exister, être un starter et appartenir à une génération jouable
                Rule::exists('pokemon_species', 'id')->where('is_starter', true)->where('is_obtainable', true),
            ],
        ], [
            'slot.unique' => 'Cet emplacement est déjà occupé.',
            'name.required' => 'Choisis un nom pour ton personnage.',
            'name.max' => 'Le nom ne doit pas dépasser :max caractères.',
            'name.regex' => 'Le nom ne peut contenir que des lettres, des chiffres, des espaces, des tirets et des apostrophes.',
            'avatar.required' => 'Choisis ton personnage.',
            'avatar.in' => 'Choisis ton personnage.',
            'starter_species_id.required' => 'Choisis ton premier Pokémon.',
            'starter_species_id.exists' => 'Ce Pokémon ne peut pas être choisi.',
        ]);

        // Transaction : soit TOUT est créé (personnage, starter, objets), soit rien.
        // Sans elle, une erreur au milieu laisserait un personnage sans Pokémon.
        $character = DB::transaction(function () use ($user, $data, $factory) {
            $character = $user->characters()->create([
                'slot' => $data['slot'],
                'name' => trim($data['name']),
                'avatar' => $data['avatar'],
                'gender' => config("avatars.{$data['avatar']}.gender", 'male'),
                'trainer_id' => Character::generateTrainerId(),
                'starter_species_id' => $data['starter_species_id'],
                'current_map' => config('game.starting_map'),
                'position_x' => config('game.starting_x'),
                'position_y' => config('game.starting_y'),
                'facing' => config('game.starting_facing'),
                'last_heal_map' => config('game.starting_map'),
                'last_heal_x' => config('game.starting_x'),
                'last_heal_y' => config('game.starting_y'),
            ]);

            // money n'est pas "fillable" : on le règle explicitement, côté serveur
            $character->money = config('game.starting_money');
            $character->save();

            // Le starter
            $species = PokemonSpecies::findOrFail($data['starter_species_id']);
            $ball = Item::where('slug', config('game.starter_ball'))->first();

            $factory->giveTo(
                $character,
                $factory->make($species, config('game.starting_level')),
                ['ball_id' => $ball?->id, 'location' => config('game.starting_location_name')]
            );

            // Le sac de départ
            $items = Item::whereIn('slug', array_keys(config('game.starting_items')))->get();
            foreach ($items as $item) {
                $character->addItem($item, config("game.starting_items.{$item->slug}"));
            }

            return $character;
        });

        // Le personnage est créé : l'aventure commence tout de suite
        return redirect()->route('game', $character);
    }

    /** Fiche du personnage : équipe et sac */
    public function show(Request $request, Character $character)
    {
        $this->ensureOwner($request, $character);

        $character->load([
            'party.species.primaryType',
            'party.species.secondaryType',
            'party.nature',
            'party.ability',
            'party.caughtBall',
            'party.moves.move.type',
            'inventory.item',
        ]);

        return view('characters.show', ['character' => $character]);
    }

    /** Suppression définitive (Pokémon, sac, Pokédex... partent avec, via les cascades) */
    public function destroy(Request $request, Character $character)
    {
        $this->ensureOwner($request, $character);

        $name = $character->name;
        $character->delete();

        return redirect()->route('dashboard')->with('status', "{$name} a été supprimé.");
    }

    /**
     * Un joueur ne peut voir ou modifier que SES personnages.
     * 404 plutôt que 403 : on ne confirme même pas que l'id existe.
     */
    private function ensureOwner(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
