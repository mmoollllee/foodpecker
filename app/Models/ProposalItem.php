<?php

namespace App\Models;

use App\Services\Distribution\PackageSpec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One position of an order proposal. The package data (label, size,
 * negotiated price, divisibility) is a snapshot, so the proposal stays
 * valid when the product's price tiers change later on.
 */
class ProposalItem extends Model
{
    protected $fillable = [
        'proposal_id',
        'product_id',
        'price_tier_id',
        'tier_label',
        'package_amount',
        'package_price_cents',
        'is_divisible',
        'divisible_step',
        'min_order_packages',
        'packages_ordered',
        'total_price_cents',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'package_amount' => 'decimal:3',
            'package_price_cents' => 'integer',
            'is_divisible' => 'boolean',
            'divisible_step' => 'decimal:3',
            'min_order_packages' => 'integer',
            'packages_ordered' => 'integer',
            'total_price_cents' => 'integer',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(OrderProposal::class, 'proposal_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
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

    public function packageLabel(): string
    {
        return $this->tier_label ?? $this->priceTier?->label ?? '—';
    }

    public function totalQuantity(): float
    {
        return (float) $this->package_amount * $this->packages_ordered;
    }

    /**
     * Users who receive a share of this item — only their votes decide
     * whether the item is approved.
     *
     * @return Collection<int, int>
     */
    public function stakeholderIds(): Collection
    {
        $allocations = $this->relationLoaded('allocations') ? $this->allocations : $this->allocations()->get();

        return $allocations
            ->filter(fn (ProposalAllocation $allocation): bool => (float) $allocation->quantity > 0)
            ->map(fn (ProposalAllocation $allocation): int => (int) $allocation->user_id)
            ->unique()
            ->values();
    }

    public function toPackageSpec(): PackageSpec
    {
        return new PackageSpec(
            label: $this->packageLabel(),
            packageAmount: (float) $this->package_amount,
            priceCents: (int) $this->package_price_cents,
            isDivisible: (bool) $this->is_divisible,
            divisibleStep: $this->divisible_step !== null ? (float) $this->divisible_step : null,
            minOrderPackages: (int) $this->min_order_packages,
            priceTierId: $this->price_tier_id,
        );
    }
}
