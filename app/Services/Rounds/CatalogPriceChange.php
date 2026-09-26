<?php

namespace App\Services\Rounds;

use App\Models\PriceTier;

/**
 * A package whose confirmed price differs from the catalog.
 */
final readonly class CatalogPriceChange
{
    public function __construct(
        public PriceTier $tier,
        public int $fromCents,
        public int $toCents,
    ) {}

    /**
     * E.g. "Basmati-Reis, 25 kg Sack".
     */
    public function label(): string
    {
        return ($this->tier->product?->name ?? '—').', '.$this->tier->label;
    }
}
