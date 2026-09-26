<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a supplier confirmed for a package in a round: its price (null
 * keeps the list price) and whether it can be delivered — together with
 * the list price at that moment.
 */
class RoundPackagePrice extends Model
{
    protected $fillable = [
        'round_id',
        'price_tier_id',
        'price_cents',
        'list_price_cents',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'list_price_cents' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }
}
