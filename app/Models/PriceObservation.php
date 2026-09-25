<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceObservation extends Model
{
    protected $fillable = [
        'product_id',
        'price_tier_id',
        'round_id',
        'group_id',
        'observed_price_cents',
        'package_amount',
        'observed_on',
    ];

    protected function casts(): array
    {
        return [
            'observed_price_cents' => 'integer',
            'package_amount' => 'decimal:3',
            'observed_on' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function pricePerUnitCents(): float
    {
        return (float) $this->package_amount > 0 ? $this->observed_price_cents / (float) $this->package_amount : 0.0;
    }
}
