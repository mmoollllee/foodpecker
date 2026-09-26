<?php

namespace App\Services\Distribution;

use App\Models\PriceTier;

/**
 * A package size as it can be ordered in a round: with the supplier's list
 * price, or the price the supplier confirmed for this round.
 */
final readonly class PackageOption
{
    public function __construct(
        public ?int $priceTierId,
        public string $label,
        public float $amount,
        public int $priceCents,
        public int $listPriceCents,
        public int $minOrderPackages = 1,
        public ?string $articleNumber = null,
        public bool $available = true,
    ) {}

    public static function fromTier(PriceTier $tier, ?int $confirmedPriceCents = null, bool $available = true): self
    {
        return new self(
            priceTierId: $tier->id,
            label: $tier->label,
            amount: (float) $tier->package_amount,
            priceCents: $confirmedPriceCents ?? (int) $tier->price_cents,
            listPriceCents: (int) $tier->price_cents,
            minOrderPackages: max(1, (int) $tier->min_order_packages),
            articleNumber: filled($tier->article_number) ? $tier->article_number : null,
            available: $available,
        );
    }

    /**
     * The same size for a single person — minimum orders apply to the
     * whole order, not to one person's packages.
     */
    public function withoutMinimumOrder(): self
    {
        return new self(
            priceTierId: $this->priceTierId,
            label: $this->label,
            amount: $this->amount,
            priceCents: $this->priceCents,
            listPriceCents: $this->listPriceCents,
            minOrderPackages: 1,
            articleNumber: $this->articleNumber,
            available: $this->available,
        );
    }

    public function pricePerUnitCents(): float
    {
        return $this->amount > 0 ? $this->priceCents / $this->amount : 0.0;
    }
}
