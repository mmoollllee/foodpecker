<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Packages of one size in a proposal position, e.g. "2 × 1 kg Tüte". A
 * snapshot of the package with the price the supplier confirmed.
 */
class ProposalItemPackage extends Model
{
    protected $fillable = [
        'proposal_item_id',
        'price_tier_id',
        'label',
        'article_number',
        'package_amount',
        'price_cents',
        'list_price_cents',
        'min_order_packages',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'package_amount' => 'decimal:3',
            'price_cents' => 'integer',
            'list_price_cents' => 'integer',
            'min_order_packages' => 'integer',
            'count' => 'integer',
        ];
    }

    public function proposalItem(): BelongsTo
    {
        return $this->belongsTo(ProposalItem::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function totalAmount(): float
    {
        return (float) $this->package_amount * $this->count;
    }

    public function totalPriceCents(): int
    {
        return $this->price_cents * $this->count;
    }
}
