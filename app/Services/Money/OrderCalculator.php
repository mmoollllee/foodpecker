<?php

namespace App\Services\Money;

use App\Models\OrderProposal;

class OrderCalculator
{
    /**
     * Totals of a proposal and the share of everybody who receives something.
     *
     * Components:
     *   - goods: sum of the positions, split by the allocations
     *   - shipping: per supplier, split in proportion to what everybody
     *     gets from that supplier
     *   - lead fee and platform fee: percentages of the goods, split in
     *     proportion to the goods
     *   - round-up donation: per participant, optional
     */
    public function calculate(OrderProposal $proposal): ProposalTotals
    {
        $proposal->loadMissing([
            'items.allocations',
            'items.product',
            'round.participants',
        ]);

        $goods = (int) $proposal->items->sum('total_price_cents');

        $leadPct = (float) $proposal->round->lead_fee_percent;
        $platformPct = (float) $proposal->round->platform_fee_percent;

        $leadFee = Money::percent($goods, $leadPct);
        $platformFee = Money::percent($goods, $platformPct);

        $perUser = [];
        $perUserAndSupplier = [];

        foreach ($proposal->items as $item) {
            $supplierId = (int) $item->product?->supplier_id;

            foreach ($item->allocations as $allocation) {
                if ((float) $allocation->quantity <= 0) {
                    continue;
                }

                $userId = (int) $allocation->user_id;
                $perUser[$userId] = ($perUser[$userId] ?? 0) + (int) $allocation->share_cents;
                $perUserAndSupplier[$supplierId][$userId] = ($perUserAndSupplier[$supplierId][$userId] ?? 0) + (int) $allocation->share_cents;
            }
        }

        ksort($perUser);

        $shippingShares = [];
        $shipping = 0;

        foreach ($proposal->shipping_by_supplier ?? [] as $supplierId => $cents) {
            $receivers = $perUserAndSupplier[(int) $supplierId] ?? [];

            if ($receivers === [] || (int) $cents <= 0) {
                continue;
            }

            ksort($receivers);
            $shipping += (int) $cents;

            foreach (Money::splitProportionally((int) $cents, $receivers) as $userId => $share) {
                $shippingShares[$userId] = ($shippingShares[$userId] ?? 0) + $share;
            }
        }

        $perParticipant = [];

        foreach ($perUser as $userId => $userGoods) {
            $userLead = $goods > 0 ? Money::percent($userGoods, $leadPct) : 0;
            $userPlatform = $goods > 0 ? Money::percent($userGoods, $platformPct) : 0;

            $roundUp = (int) ($proposal->round->participants
                ->firstWhere('user_id', $userId)?->round_up_to_cents ?? 0);

            $roundUpDonation = 0;
            $subtotal = $userGoods + ($shippingShares[$userId] ?? 0) + $userLead + $userPlatform;
            if ($roundUp > 0 && $subtotal > 0) {
                $roundUpDonation = max(0, Money::roundUpTo($subtotal, $roundUp) - $subtotal);
            }

            $perParticipant[] = new ParticipantTotals(
                userId: $userId,
                goodsCents: $userGoods,
                shippingShareCents: $shippingShares[$userId] ?? 0,
                leadFeeCents: $userLead,
                platformFeeCents: $userPlatform,
                roundUpDonationCents: $roundUpDonation,
            );
        }

        // Rounding differences of the percentages go to the last participant.
        $leadDelta = $leadFee - array_sum(array_map(fn (ParticipantTotals $p): int => $p->leadFeeCents, $perParticipant));
        $platformDelta = $platformFee - array_sum(array_map(fn (ParticipantTotals $p): int => $p->platformFeeCents, $perParticipant));

        if ($perParticipant !== [] && ($leadDelta !== 0 || $platformDelta !== 0)) {
            $index = array_key_last($perParticipant);
            $last = $perParticipant[$index];
            $perParticipant[$index] = new ParticipantTotals(
                userId: $last->userId,
                goodsCents: $last->goodsCents,
                shippingShareCents: $last->shippingShareCents,
                leadFeeCents: $last->leadFeeCents + $leadDelta,
                platformFeeCents: $last->platformFeeCents + $platformDelta,
                roundUpDonationCents: $last->roundUpDonationCents,
            );
        }

        return new ProposalTotals(
            goodsCents: $goods,
            shippingCents: $shipping,
            leadFeeCents: $leadFee,
            platformFeeCents: $platformFee,
            grandTotalCents: $goods + $shipping + $leadFee + $platformFee,
            perParticipant: $perParticipant,
        );
    }
}
