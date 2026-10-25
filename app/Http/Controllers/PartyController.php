<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Item;
use App\Models\Move;
use App\Models\Pokemon;
use App\Battle\InvalidAction;
use App\Services\BagService;
use App\Services\EvolutionService;
use App\Services\HeldItemService;
use App\Services\MapInfo;
use App\Services\PokemonPresenter;
use Illuminate\Http\Request;

/**
 * L'équipe, vue depuis le menu du jeu : liste, réordonner, évoluer, apprendre une attaque, se faire soigner.
 * Étape 14 : donner un objet à tenir, le reprendre.
 */
class PartyController extends Controller
{
    public function __construct(private EvolutionService $evolutions, private HeldItemService $heldItems, private BagService $bag)
    {
    }

    /**
     * L'équipe complète (écran d'équipe, résumé).
     * ?item_id=… (une CT) : chaque Pokémon reçoit "able" = able / unable / learned (APTE, PAS APTE, APPRIS)
     */
    public function index(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $party = $this->party($character);

        $machine = $request->integer('item_id') ? Item::with('move')->find($request->integer('item_id')) : null;
        if ($machine?->effect_code === 'teach' && $machine->move) {
            $models = $character->party()->with('species', 'moves')->get()->keyBy('id');
            foreach ($party as &$p) {
                $model = $models[$p['id']];
                $p['able'] = $model->is_egg ? 'unable' : ($model->moves->contains('move_id', $machine->move_id) ? 'learned'
                    : ($this->evolutions->canLearnByMachine($model, $machine->move) ? 'able' : 'unable')); // étape 18 : un Œuf n'apprend rien
            }
            unset($p);
        }

        return response()->json(['party' => $party]);
    }

    /** Échanger deux Pokémon de l'équipe (touche "Échanger" de l'écran d'équipe) */
    public function swap(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'a' => ['required', 'integer', 'between:1,6'],
            'b' => ['required', 'integer', 'between:1,6'],
        ]);

        $a = $character->party()->where('party_slot', $data['a'])->firstOrFail();
        $b = $character->party()->where('party_slot', $data['b'])->firstOrFail();

        // L'index unique (personnage, case) interdit d'avoir deux Pokémon sur la même case, même un instant
        $a->forceFill(['party_slot' => 99])->save();
        $b->forceFill(['party_slot' => $data['a']])->save();
        $a->forceFill(['party_slot' => $data['b']])->save();

        return response()->json(['party' => $this->party($character)]);
    }

    /**
     * Évolution (après un combat, un Super Bonbon ou une Pierre). Le serveur revérifie les conditions.
     * item_id : la Pierre utilisée (consommée seulement si l'évolution a lieu).
     */
    public function evolve(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'pokemon_id' => ['required', 'integer'],
            'item_id' => ['nullable', 'integer'],
        ]);

        $pokemon = $this->ownedPokemon($character, $data['pokemon_id']);
        $item = isset($data['item_id']) ? Item::find($data['item_id']) : null;
        if ($item && ! $character->hasItem($item)) {
            return response()->json(['message' => 'Vous n\'avez pas cet objet.'], 422);
        }

        $evolution = $this->evolutions->candidate($pokemon, $item);
        if (! $evolution) {
            return response()->json(['message' => 'Ce Pokémon ne peut pas évoluer maintenant.'], 422);
        }

        $moves = $this->evolutions->evolve($pokemon, $evolution);
        if ($item) {
            $character->removeItem($item);
        }

        return response()->json([
            'pokemon' => PokemonPresenter::detail($pokemon->fresh()),
            'learned' => $moves['learned'],
            'learn' => $moves['pending'],
            'messages' => $moves['messages'] ?? [],   // étape 23 : Munja
        ]);
    }

    /** Apprendre une attaque (après une montée de niveau ou une évolution) */
    public function learn(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'pokemon_id' => ['required', 'integer'],
            'move_id' => ['required', 'integer', 'exists:moves,id'],
            'forget' => ['nullable', 'integer', 'between:1,4'],
            'item_id' => ['nullable', 'integer'],      // la CT utilisée, s'il y en a une
        ]);

        $pokemon = $this->ownedPokemon($character, $data['pokemon_id']);
        abort_if($pokemon->is_egg, 422, 'Un Œuf ne peut rien apprendre.');   // étape 18
        $machine = isset($data['item_id']) ? Item::find($data['item_id']) : null;
        $this->evolutions->learn($pokemon, Move::find($data['move_id']), $data['forget'] ?? null, $machine);

        return response()->json(['pokemon' => PokemonPresenter::detail($pokemon->fresh())]);
    }

    /**
     * L'infirmière du Centre Pokémon soigne l'équipe. On vérifie que la carte a bien une infirmière
     * (objet Tiled avec la propriété action = heal) : sinon, ce serait un soin gratuit n'importe où.
     */
    public function heal(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'map' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
        ]);

        $map = MapInfo::load($data['map']);
        if (! $map || ! $map->hasAction('heal') || $data['x'] >= $map->width() || $data['y'] >= $map->height()) {
            return response()->json(['message' => 'Il n\'y a pas d\'infirmière ici.'], 422);
        }

        $character->healParty();
        // Après un K.O. total, le joueur se réveillera ici
        $character->forceFill(['last_heal_map' => $data['map'], 'last_heal_x' => $data['x'], 'last_heal_y' => $data['y']])->save();

        return response()->json(['party' => $this->party($character)]);
    }

    /** Étape 14 : donner un objet du Sac à un Pokémon de l'équipe (son ancien objet revient dans le Sac) */
    public function give(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate([
            'pokemon_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
        ]);

        $pokemon = $character->party()->with(PokemonPresenter::RELATIONS)->findOrFail($data['pokemon_id']);
        try {
            $message = $this->heldItems->give($character, $pokemon, Item::find($data['item_id']));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message, 'party' => $this->party($character), 'bag' => $this->bag->contents($character)]);
    }

    /**
     * Étape 19 : donner un SURNOM (ou l'enlever : nickname vide). Pour un Pokémon de l'équipe ou du PC.
     * Comme dans les jeux, un Pokémon reçu d'un autre dresseur garde son nom (config move_tutor.rename_traded).
     */
    public function nickname(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $max = (int) config('move_tutor.nickname_max', 12);
        $data = $request->validate([
            'pokemon_id' => ['required', 'integer'],
            // Lettres (accents compris), chiffres, espace et quelques signes ; pas de < > ni de caractères invisibles
            'nickname' => ['nullable', 'string', "max:{$max}", 'regex:/^[\p{L}\p{N} .\'’\-!?♂♀]+$/u'],
        ], ['nickname.regex' => 'Ce surnom contient des caractères interdits.', 'nickname.max' => "{$max} caractères au plus."]);

        $pokemon = $this->ownedPokemon($character, $data['pokemon_id']);
        if ($pokemon->is_egg) {
            return response()->json(['message' => 'On ne peut pas donner de surnom à un Œuf.'], 422);
        }
        if (! config('move_tutor.rename_traded', false) && (int) $pokemon->original_trainer_id !== (int) $character->trainer_id) {
            return response()->json(['message' => "{$pokemon->display_name} vient d'un autre dresseur : il tient à son nom !"], 422);
        }

        $nickname = trim(preg_replace('/\s+/u', ' ', (string) ($data['nickname'] ?? '')));
        // Le nom de l'espèce comme surnom = pas de surnom
        $pokemon->forceFill(['nickname' => $nickname === '' || $nickname === $pokemon->species->name ? null : $nickname])->save();

        return response()->json([
            'pokemon' => PokemonPresenter::detail($pokemon->fresh()),
            'party' => $this->party($character),
        ]);
    }

    /** Étape 14 : reprendre l'objet tenu (il revient dans le Sac) */
    public function take(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $data = $request->validate(['pokemon_id' => ['required', 'integer']]);

        $pokemon = $character->party()->with(PokemonPresenter::RELATIONS)->findOrFail($data['pokemon_id']);
        try {
            $message = $this->heldItems->take($character, $pokemon);
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message, 'party' => $this->party($character), 'bag' => $this->bag->contents($character)]);
    }

    private function party(Character $character): array
    {
        return $character->party()->with(PokemonPresenter::RELATIONS)->get()
            ->map(fn (Pokemon $p) => PokemonPresenter::detail($p))->values()->all();
    }

    private function ownedPokemon(Character $character, int $id): Pokemon
    {
        return $character->pokemon()->whereIn('location', ['party', 'box'])->with(PokemonPresenter::RELATIONS)->findOrFail($id); // étape 18 : pas ceux de la Pension
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
