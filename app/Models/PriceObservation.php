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
}
