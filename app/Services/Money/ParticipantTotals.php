<?php

namespace App\Services\Money;

class ParticipantTotals
{
    public function __construct(
        public readonly int $userId,
        public readonly int $goodsCents,
        public readonly int $shippingShareCents,
        public readonly int $leadFeeCents,
        public readonly int $platformFeeCents,
        public readonly int $roundUpDonationCents = 0,
    ) {}

    public function subtotalCents(): int
    {
        return $this->goodsCents + $this->shippingShareCents + $this->leadFeeCents + $this->platformFeeCents;
    }

    public function totalCents(): int
    {
        return $this->subtotalCents() + $this->roundUpDonationCents;
    }
}
