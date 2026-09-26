<?php

namespace App\Services\Distribution;

use App\Models\CartItem;

/**
 * What one person wants of a product: an exact amount or a range, maybe a
 * preferred pack size — or the amount the proposer set by hand.
 */
final readonly class Demand
{
    public function __construct(
        public int $userId,
        public float $min,
        public float $max,
        public ?int $preferredTierId = null,
        public ?float $manualQuantity = null,
    ) {}

    public static function fromCartItem(CartItem $item, ?float $manualQuantity = null): self
    {
        return new self(
            userId: (int) $item->user_id,
            min: $item->effectiveMin(),
            max: $item->effectiveMax(),
            preferredTierId: $item->preferred_price_tier_id !== null ? (int) $item->preferred_price_tier_id : null,
            manualQuantity: $manualQuantity,
        );
    }

    public function isManual(): bool
    {
        return $this->manualQuantity !== null;
    }

    /**
     * The least the person gets: the amount set by hand or the minimum wish.
     */
    public function wantedMin(): float
    {
        return $this->manualQuantity ?? $this->min;
    }

    public function wantedMax(): float
    {
        return $this->manualQuantity ?? $this->max;
    }
}
