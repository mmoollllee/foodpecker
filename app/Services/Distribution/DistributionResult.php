<?php

namespace App\Services\Distribution;

/**
 * The packages to order for a product and who gets how much of them.
 */
class DistributionResult
{
    private const EPSILON = 0.001;

    /**
     * @param  array<int, AllocationLine>  $allocations
     * @param  array<int, string>  $notes
     * @param  float  $overhang  Ordered, but nobody wants it within their maximum — paid by all.
     * @param  float  $shortfall  Handed out beyond what is ordered — the order can't go out like this.
     */
    public function __construct(
        public readonly PackageMix $mix,
        public readonly array $allocations,
        public readonly array $notes = [],
        public readonly float $overhang = 0.0,
        public readonly float $shortfall = 0.0,
    ) {}

    public function totalQuantity(): float
    {
        return $this->mix->totalAmount();
    }

    public function totalPriceCents(): int
    {
        return $this->mix->totalPriceCents();
    }

    public function sumAllocated(): float
    {
        return array_sum(array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $this->allocations));
    }

    public function allocationFor(int $userId): ?AllocationLine
    {
        foreach ($this->allocations as $line) {
            if ($line->userId === $userId) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Everybody gets what they wished for and nothing is left over.
     */
    public function fits(): bool
    {
        return $this->overhang <= self::EPSILON
            && $this->shortfall <= self::EPSILON
            && collect($this->allocations)->every(fn (AllocationLine $line): bool => ! $line->unfulfilled);
    }
}
