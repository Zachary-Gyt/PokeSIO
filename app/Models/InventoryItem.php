<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne du sac : un objet et sa quantité.
 * Pour ajouter / retirer, passe par Character::addItem() et Character::removeItem().
 */
class InventoryItem extends Model
{
    public const MAX_QUANTITY = 999;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['registered' => 'boolean'];
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
