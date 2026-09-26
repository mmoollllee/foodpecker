<?php

namespace App\Services\Estimates;

use App\Models\Round;
use App\Services\Money\Money;

/**
 * What the round would cost if it were ordered now, per product and per
 * person — without shipping, which only the suppliers know.
 */
final readonly class RoundEstimate
{
    /**
     * @param  array<int, ProductEstimate>  $products  Keyed by product id.
     */
    public function __construct(
        public Round $round,
        public array $products,
    ) {}

    public function forProduct(int $productId): ?ProductEstimate
    {
        return $this->products[$productId] ?? null;
    }

    public function goodsCentsFor(int $userId): int
    {
        return array_sum(array_map(fn (ProductEstimate $estimate): int => $estimate->shareCentsFor($userId), $this->products));
    }

    /**
     * Goods plus lead fee and platform fee.
     */
    public function totalCentsFor(int $userId): int
    {
        return $this->withFees($this->goodsCentsFor($userId));
    }

    public function withFees(int $goodsCents): int
    {
        return $goodsCents
            + Money::percent($goodsCents, (float) $this->round->lead_fee_percent)
            + Money::percent($goodsCents, (float) $this->round->platform_fee_percent);
    }

    public function goodsCents(): int
    {
        return array_sum(array_map(fn (ProductEstimate $estimate): int => $estimate->distribution->totalPriceCents(), $this->products));
    }
}
