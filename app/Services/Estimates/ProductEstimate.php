<?php

namespace App\Services\Estimates;

use App\Models\Product;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\PackageOption;

/**
 * What a product would cost the group if it were ordered now: the
 * packages for the current wishes and everybody's share.
 */
final readonly class ProductEstimate
{
    /**
     * @param  array<int, PackageOption>  $options
     */
    public function __construct(
        public Product $product,
        public array $options,
        public DistributionResult $distribution,
    ) {}

    /**
     * Price per unit of the packages the group would order, in cents.
     */
    public function pricePerUnitCents(): ?float
    {
        $amount = $this->distribution->totalQuantity();

        return $amount > 0 ? $this->distribution->totalPriceCents() / $amount : null;
    }

    /**
     * Ordered, but nobody wants it yet — it could go to anybody at no extra cost.
     */
    public function freeQuantity(): float
    {
        return $this->distribution->overhang;
    }

    public function shareCentsFor(int $userId): int
    {
        return $this->distribution->allocationFor($userId)?->shareCents ?? 0;
    }
}
