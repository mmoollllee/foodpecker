<?php

namespace App\Services\Distribution;

class DistributionResult
{
    /**
     * @param  array<int, AllocationLine>  $allocations
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly int $packagesOrdered,
        public readonly float $totalQuantity,
        public readonly int $totalPriceCents,
        public readonly array $allocations,
        public readonly bool $feasible,
        public readonly array $notes = [],
        public readonly float $unallocatedQuantity = 0.0,
    ) {}

    public function sumAllocated(): float
    {
        $sum = 0.0;
        foreach ($this->allocations as $a) {
            $sum += $a->allocatedQuantity;
        }

        return $sum;
    }

    public function unfulfilledCount(): int
    {
        $n = 0;
        foreach ($this->allocations as $a) {
            if ($a->unfulfilled) {
                $n++;
            }
        }

        return $n;
    }
}
