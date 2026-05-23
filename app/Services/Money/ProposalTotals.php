<?php

namespace App\Services\Money;

class ProposalTotals
{
    /**
     * @param  array<int, ParticipantTotals>  $perParticipant
     */
    public function __construct(
        public readonly int $goodsCents,
        public readonly int $shippingCents,
        public readonly int $leadFeeCents,
        public readonly int $platformFeeCents,
        public readonly int $grandTotalCents,
        public readonly array $perParticipant,
    ) {}
}
