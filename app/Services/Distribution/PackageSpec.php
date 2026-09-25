<?php

namespace App\Services\Distribution;

use App\Models\PriceTier;

/**
 * Package data the distributor works with: either taken from a product's
 * price tier or from a proposal item's snapshot (which may carry a
 * negotiated price).
 */
final readonly class PackageSpec
{
    public function __construct(
        public string $label,
        public float $packageAmount,
        public int $priceCents,
        public bool $isDivisible,
        public ?float $divisibleStep = null,
        public int $minOrderPackages = 1,
        public ?int $priceTierId = null,
    ) {}

    public static function fromTier(PriceTier $tier, ?int $priceCents = null): self
    {
        return new self(
            label: $tier->label,
            packageAmount: (float) $tier->package_amount,
            priceCents: $priceCents ?? (int) $tier->price_cents,
            isDivisible: (bool) $tier->is_divisible,
            divisibleStep: $tier->divisible_step !== null ? (float) $tier->divisible_step : null,
            minOrderPackages: max(1, (int) $tier->min_order_packages),
            priceTierId: $tier->id,
        );
    }

    public function withPrice(int $priceCents): self
    {
        return new self(
            label: $this->label,
            packageAmount: $this->packageAmount,
            priceCents: $priceCents,
            isDivisible: $this->isDivisible,
            divisibleStep: $this->divisibleStep,
            minOrderPackages: $this->minOrderPackages,
            priceTierId: $this->priceTierId,
        );
    }

    /**
     * Step in which a divisible package may be split between participants.
     */
    public function step(): float
    {
        return $this->divisibleStep ?? $this->packageAmount;
    }

    public function pricePerUnitCents(): float
    {
        return $this->packageAmount > 0 ? $this->priceCents / $this->packageAmount : 0.0;
    }
}
