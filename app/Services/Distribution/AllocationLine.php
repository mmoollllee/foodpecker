<?php

namespace App\Services\Distribution;

/**
 * What one person gets of a product and pays for it.
 */
class AllocationLine
{
    /**
     * @param  array<int, int>  $packageCounts  Whole packages by price tier id, for products that are not portioned.
     */
    public function __construct(
        public readonly int $userId,
        public readonly float $requestedMin,
        public readonly float $requestedMax,
        public float $allocatedQuantity = 0.0,
        public int $shareCents = 0,
        public bool $unfulfilled = false,
        public readonly bool $manual = false,
        public array $packageCounts = [],
    ) {}

    /**
     * How far the allocation is off the wish: negative below the minimum,
     * positive above the maximum.
     */
    public function deviation(): float
    {
        if ($this->allocatedQuantity < $this->requestedMin) {
            return $this->allocatedQuantity - $this->requestedMin;
        }
        if ($this->allocatedQuantity > $this->requestedMax) {
            return $this->allocatedQuantity - $this->requestedMax;
        }

        return 0.0;
    }
}
