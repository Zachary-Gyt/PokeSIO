<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Des objets proposés dans un échange.
 */
class TradeItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function trade()
    {
        return $this->belongsTo(Trade::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function owner()
    {
        return $this->belongsTo(Character::class, 'owner_id');
    }
}
