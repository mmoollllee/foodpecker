<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalItem extends Model
{
    protected $fillable = [
        'proposal_id',
        'product_id',
        'price_tier_id',
        'packages_ordered',
        'total_price_cents',
        'notes',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(OrderProposal::class, 'proposal_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProposalAllocation::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ProposalVote::class);
    }

    public function totalQuantity(): float
    {
        return (float) $this->priceTier?->package_amount * (int) $this->packages_ordered;
    }

    public function upvotes(): int
    {
        return $this->votes->where('value', 'up')->count();
    }

    public function downvotes(): int
    {
        return $this->votes->where('value', 'down')->count();
    }
}
