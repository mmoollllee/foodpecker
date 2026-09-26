<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One position of an order proposal: a product, the packages to order of
 * it (possibly several sizes) and who gets how much. Package data is a
 * snapshot, so the proposal stays valid when the catalog changes later.
 */
class ProposalItem extends Model
{
    private const EPSILON = 0.001;

    protected $fillable = [
        'proposal_id',
        'product_id',
        'portion_size',
        'rounding_step',
        'packages_fixed',
        'total_price_cents',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'portion_size' => 'decimal:3',
            'rounding_step' => 'decimal:3',
            'packages_fixed' => 'boolean',
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

    public function packages(): HasMany
    {
        return $this->hasMany(ProposalItemPackage::class)->orderByDesc('package_amount');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProposalAllocation::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ProposalVote::class);
    }

    public function isPortioned(): bool
    {
        return $this->portion_size !== null && (float) $this->portion_size > 0;
    }

    /**
     * Whether an amount comes in whole portions of this position.
     */
    public function isWholePortions(float $quantity): bool
    {
        if (! $this->isPortioned()) {
            return true;
        }

        $portions = $quantity / (float) $this->portion_size;

        return abs($portions - round($portions)) < 0.0001;
    }

    public function totalQuantity(): float
    {
        return (float) $this->packages->sum(fn (ProposalItemPackage $package): float => $package->totalAmount());
    }

    public function allocatedQuantity(): float
    {
        return (float) $this->allocations->sum(fn (ProposalAllocation $allocation): float => (float) $allocation->quantity);
    }

    /**
     * Ordered, but nobody wants it within their maximum — paid by all.
     */
    public function overhang(): float
    {
        return max(0.0, $this->totalQuantity() - $this->allocatedQuantity());
    }

    /**
     * Handed out beyond what is ordered — then the proposal can't go out.
     */
    public function shortfall(): float
    {
        return max(0.0, $this->allocatedQuantity() - $this->totalQuantity());
    }

    /**
     * Why the proposal can't be put up for a vote because of this item.
     */
    public function blockingProblem(): ?string
    {
        $name = $this->product?->name ?? 'Eine Position';

        if ($this->packages->isEmpty()) {
            return "{$name}: Es ist keine Gebindegröße lieferbar.";
        }

        if ($this->shortfall() > self::EPSILON) {
            return "{$name}: Es ist mehr verteilt als bestellt.";
        }

        if ($this->allocatedQuantity() <= self::EPSILON) {
            return "{$name}: Niemand bekommt etwas von den bestellten Gebinden.";
        }

        return null;
    }

    /**
     * E.g. "1 × 10 kg Sack, 2 × 1 kg Tüte".
     */
    public function describePackages(): string
    {
        return $this->packages
            ->map(fn (ProposalItemPackage $package): string => $package->count.' × '.$package->label)
            ->implode(', ') ?: '—';
    }

    /**
     * Everybody who ordered the product decides on it — also when the
     * proposal gives them nothing, so nobody can be left out silently.
     *
     * @return Collection<int, int>
     */
    public function stakeholderIds(): Collection
    {
        $allocations = $this->relationLoaded('allocations') ? $this->allocations : $this->allocations()->get();

        return $allocations
            ->map(fn (ProposalAllocation $allocation): int => (int) $allocation->user_id)
            ->unique()
            ->values();
    }

    /**
     * Users who receive something of this item.
     *
     * @return Collection<int, int>
     */
    public function receiverIds(): Collection
    {
        $allocations = $this->relationLoaded('allocations') ? $this->allocations : $this->allocations()->get();

        return $allocations
            ->filter(fn (ProposalAllocation $allocation): bool => (float) $allocation->quantity > 0)
            ->map(fn (ProposalAllocation $allocation): int => (int) $allocation->user_id)
            ->unique()
            ->values();
    }
}
