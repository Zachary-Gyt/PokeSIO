<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Un personnage joueur (3 emplacements par compte).
 */
class Character extends Model
{
    public const MAX_PER_USER = 3;
    public const MAX_MONEY = 999_999;

    /**
     * Colonnes modifiables via create() / fill().
     * `money` n'y est volontairement PAS : l'argent ne change que par addMoney() / spendMoney(),
     * jamais en recopiant des données envoyées par le navigateur.
     */
    protected $fillable = [
        'slot', 'name', 'gender', 'avatar', 'trainer_id', 'starter_species_id',
        'current_map', 'position_x', 'position_y', 'facing',
        'last_heal_map', 'last_heal_x', 'last_heal_y',
        'play_time', 'story_flags', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'story_flags' => 'array',
            'last_seen_at' => 'datetime',
            'group_joined_at' => 'datetime',
        ];
    }

    /** Le groupe de joueurs (null : seul). group_id ne se remplit que par GroupService. */
    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    /** Le jeu a sauvegardé il y a peu (config chat.online_seconds) : le joueur est connecté */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subSeconds((int) config('chat.online_seconds', 120)));
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function starterSpecies()
    {
        return $this->belongsTo(PokemonSpecies::class, 'starter_species_id');
    }

    /** TOUS les Pokémon du personnage (équipe + PC) */
    public function pokemon()
    {
        return $this->hasMany(Pokemon::class);
    }

    /** L'équipe, dans l'ordre */
    public function party()
    {
        return $this->hasMany(Pokemon::class)->where('location', 'party')->orderBy('party_slot');
    }

    /** Étape 18 : l'équipe SANS les Œufs (combat, capacités de terrain, Maître de l'ORT) */
    public function battleParty()
    {
        return $this->party()->where('is_egg', false);
    }

    /** Étape 18 : les Pokémon confiés à la Pension (2 places au plus) */
    public function daycare()
    {
        return $this->hasMany(Pokemon::class)->where('location', 'daycare')->orderBy('box_slot');
    }

    /** Le PC, rangé par boîte puis par case */
    public function boxedPokemon()
    {
        return $this->hasMany(Pokemon::class)->where('location', 'box')->orderBy('box_number')->orderBy('box_slot');
    }

    /** Lignes du sac (objet + quantité) */
    public function inventory()
    {
        return $this->hasMany(InventoryItem::class);
    }

    /** Même sac, vu comme une liste d'objets : $character->items->first()->pivot->quantity */
    public function items()
    {
        return $this->belongsToMany(Item::class, 'inventory_items')
            ->withPivot('quantity', 'registered')
            ->withTimestamps();
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class)->withPivot('obtained_at')->orderBy('order');
    }

    public function pokedexEntries()
    {
        return $this->hasMany(PokedexEntry::class);
    }

    /** Dresseurs PNJ déjà battus */
    public function defeatedTrainers()
    {
        return $this->belongsToMany(Trainer::class)
            ->withPivot('times_defeated', 'first_defeated_at', 'last_defeated_at', 'rematch_ready');   // étape 26 : rematch_ready
    }

    // ------------------------------------------------------------------
    // Équipe et PC
    // ------------------------------------------------------------------

    public function hasRoomInParty(): bool
    {
        return $this->party()->count() < Pokemon::PARTY_SIZE;
    }

    /**
     * Où ranger un nouveau Pokémon (capture, cadeau, échange) :
     * dans l'équipe s'il reste de la place, sinon dans la première case libre du PC.
     * Renvoie les colonnes à enregistrer, ou null si tout est plein.
     */
    public function freeSlotForNewPokemon(): ?array
    {
        $usedPartySlots = $this->party()->pluck('party_slot')->all();
        for ($slot = 1; $slot <= Pokemon::PARTY_SIZE; $slot++) {
            if (! in_array($slot, $usedPartySlots, true)) {
                return ['location' => 'party', 'party_slot' => $slot, 'box_number' => null, 'box_slot' => null];
            }
        }

        $box = $this->nextFreeBoxSlot();

        return $box ? ['location' => 'box', 'party_slot' => null] + $box : null;
    }

    /** Première case libre du PC : ['box_number' => 1, 'box_slot' => 4] ou null */
    public function nextFreeBoxSlot(): ?array
    {
        $used = $this->boxedPokemon()
            ->get(['box_number', 'box_slot'])
            ->map(fn ($p) => "{$p->box_number}-{$p->box_slot}")
            ->flip();

        for ($box = 1; $box <= Pokemon::BOX_COUNT; $box++) {
            for ($slot = 1; $slot <= Pokemon::BOX_SIZE; $slot++) {
                if (! isset($used["{$box}-{$slot}"])) {
                    return ['box_number' => $box, 'box_slot' => $slot];
                }
            }
        }

        return null;
    }

    /** L'équipe peut-elle encore se battre ? */
    public function hasUsablePokemon(): bool
    {
        return $this->party()->where('current_hp', '>', 0)->exists();
    }

    /** Soigne toute l'équipe (Centre Pokémon) */
    public function healParty(): void
    {
        $this->party()->with('species', 'nature', 'moves.move')->get()->each->heal();
    }

    // ------------------------------------------------------------------
    // Sac
    // ------------------------------------------------------------------

    public function quantityOf(Item|int $item): int
    {
        $itemId = $item instanceof Item ? $item->id : $item;

        return (int) $this->inventory()->where('item_id', $itemId)->value('quantity');
    }

    public function hasItem(Item|int $item, int $quantity = 1): bool
    {
        return $this->quantityOf($item) >= $quantity;
    }

    public function addItem(Item|int $item, int $quantity = 1): void
    {
        $itemId = $item instanceof Item ? $item->id : $item;
        $line = $this->inventory()->firstOrNew(['item_id' => $itemId]);
        $line->quantity = min(InventoryItem::MAX_QUANTITY, ($line->quantity ?? 0) + $quantity);
        $line->save();
    }

    /** Retire des objets ; renvoie false (sans rien retirer) s'il n'y en a pas assez */
    public function removeItem(Item|int $item, int $quantity = 1): bool
    {
        $itemId = $item instanceof Item ? $item->id : $item;
        $line = $this->inventory()->where('item_id', $itemId)->first();

        if (! $line || $line->quantity < $quantity) {
            return false;
        }

        $line->quantity -= $quantity;
        $line->quantity === 0 ? $line->delete() : $line->save();

        return true;
    }

    // ------------------------------------------------------------------
    // Argent
    // ------------------------------------------------------------------

    public function addMoney(int $amount): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Utilise spendMoney() pour retirer de l\'argent.');
        }
        $this->money = min(self::MAX_MONEY, $this->money + $amount);
        $this->save();
    }

    /** Dépense de l'argent ; renvoie false s'il n'y en a pas assez */
    public function spendMoney(int $amount): bool
    {
        if ($amount < 0 || $this->money < $amount) {
            return false;
        }
        $this->money -= $amount;
        $this->save();

        return true;
    }

    // ------------------------------------------------------------------
    // Pokédex, badges, scénario
    // ------------------------------------------------------------------

    /** À appeler quand le joueur voit (combat) ou capture une espèce */
    public function registerInPokedex(PokemonSpecies|int $species, bool $caught = false): void
    {
        $speciesId = $species instanceof PokemonSpecies ? $species->id : $species;
        $entry = $this->pokedexEntries()->firstOrNew(['pokemon_species_id' => $speciesId]);
        $newlyCaught = $caught && ! $entry->caught;
        $entry->caught = $entry->caught || $caught;
        $entry->save();

        // Étape 13 : une nouvelle espèce capturée peut débloquer une récompense du Professeur (tous les 10)
        if ($newlyCaught) {
            app(\App\Services\PokedexService::class)->checkRewards($this);
        }
    }

    public function hasBadge(Badge|int $badge): bool
    {
        $badgeId = $badge instanceof Badge ? $badge->id : $badge;

        return $this->badges()->whereKey($badgeId)->exists();
    }

    public function hasDefeated(Trainer|int $trainer): bool
    {
        $trainerId = $trainer instanceof Trainer ? $trainer->id : $trainer;

        return $this->defeatedTrainers()->whereKey($trainerId)->exists();
    }

    /** Lecture d'un drapeau de scénario : $character->flag('got_pokedex') */
    public function flag(string $key, mixed $default = false): mixed
    {
        return $this->story_flags[$key] ?? $default;
    }

    public function setFlag(string $key, mixed $value = true): void
    {
        $flags = $this->story_flags ?? [];
        $flags[$key] = $value;
        $this->story_flags = $flags;
        $this->save();
    }

    /** Étape 17 : le personnage choisi (config/avatars.php), "red" par défaut */
    public function avatarKey(): string
    {
        return array_key_exists((string) $this->avatar, config('avatars', [])) ? $this->avatar : ($this->gender === 'female' ? 'leaf' : 'red');
    }

    /** Étape 17 : sprite du personnage. $kind = front (combat, Carte Dresseur) ou walk, run, surf, bike, fish, back */
    public function spriteUrl(string $kind = 'front'): string
    {
        $avatar = $this->avatarKey();

        return $kind === 'front'
            ? asset("game/sprites/trainers/battle/player_{$avatar}.png")
            : asset("game/sprites/player/{$avatar}_{$kind}.png");
    }

    /** Génère un ID Dresseur à 5 chiffres non utilisé */
    public static function generateTrainerId(): int
    {
        do {
            $id = random_int(10000, 99999);
        } while (self::where('trainer_id', $id)->exists());

        return $id;
    }
}
