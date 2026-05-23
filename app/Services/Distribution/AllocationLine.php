<?php

namespace App\Services\Distribution;

use App\Models\CartItem;

/**
 * Ein einzelner Verteilungs-Eintrag pro Cart-Item / Teilnehmer.
 */
class AllocationLine
{
    public function __construct(
        public readonly int $userId,
        public readonly ?CartItem $cartItem,
        public readonly float $requestedMin,
        public readonly float $requestedMax,
        public float $allocatedQuantity = 0.0,
        public int $shareCents = 0,
        public bool $unfulfilled = false,
    ) {}

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
