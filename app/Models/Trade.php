<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un échange entre deux joueurs (Pokémon, objets, argent).
 * La finalisation (transfert en transaction) sera écrite à l'étape multijoueur.
 */
class Trade extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'initiator_confirmed' => 'boolean',
            'recipient_confirmed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function initiator()
    {
        return $this->belongsTo(Character::class, 'initiator_id');
    }

    public function recipient()
    {
        return $this->belongsTo(Character::class, 'recipient_id');
    }

    /** Pokémon proposés (pivot->owner_id = qui les propose) */
    public function pokemon()
    {
        return $this->belongsToMany(Pokemon::class, 'trade_pokemon')->withPivot('owner_id');
    }

    public function items()
    {
        return $this->hasMany(TradeItem::class);
    }

    /** Échanges en cours */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ['pending', 'negotiating']);
    }

    /** Échanges auxquels participe un personnage */
    public function scopeInvolving(Builder $query, Character $character): void
    {
        $query->where(fn ($q) => $q->where('initiator_id', $character->id)->orWhere('recipient_id', $character->id));
    }

    public function involves(Character $character): bool
    {
        return in_array($character->id, [$this->initiator_id, $this->recipient_id], true);
    }

    /** L'autre participant */
    public function partnerOf(Character $character): Character
    {
        return $character->id === $this->initiator_id ? $this->recipient : $this->initiator;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'negotiating'], true);
    }

    public function bothConfirmed(): bool
    {
        return $this->initiator_confirmed && $this->recipient_confirmed;
    }

    /** À appeler dès qu'une offre change : personne ne doit valider une offre qu'il n'a pas vue */
    public function resetConfirmations(): void
    {
        $this->initiator_confirmed = false;
        $this->recipient_confirmed = false;
        $this->save();
    }
}
