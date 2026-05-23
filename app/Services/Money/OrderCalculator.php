<?php

namespace App\Services\Money;

use App\Models\OrderProposal;

class OrderCalculator
{
    /**
     * Berechnet die Gesamtsummen eines Vorschlags inkl. Anteile pro Teilnehmer.
     *
     * Komponenten:
     *   - Warenwert: Summe aller `proposal_items.total_price_cents`
     *   - Versand: pauschal aus `order_proposal.shipping_cents`, gleichmäßig
     *     auf Teilnehmer aufgeteilt
     *   - Lead-Honorar: `lead_fee_percent` × Warenwert
     *   - Vereinsbeitrag: `platform_fee_percent` × Warenwert
     *   - Aufrund-Spende: pro Teilnehmer, optional
     *
     * Aufteilung pro Teilnehmer:
     *   - Waren-Anteil: nach individueller `proposal_allocation.share_cents`
     *   - Versand-Anteil: gleichmäßig
     *   - Honorar/Beitrag: proportional zum Waren-Anteil
     */
    public function calculate(OrderProposal $proposal): ProposalTotals
    {
        $proposal->loadMissing([
            'items.allocations',
            'round.participants',
        ]);

        $items = $proposal->items;

        $goods = (int) $items->sum('total_price_cents');
        $shipping = (int) $proposal->shipping_cents;

        $leadPct = (float) $proposal->round->lead_fee_percent;
        $platformPct = (float) $proposal->round->platform_fee_percent;

        $leadFee = Money::percent($goods, $leadPct);
        $platformFee = Money::percent($goods, $platformPct);

        // Allokationen pro User aggregieren
        $perUser = []; // userId => goodsCents
        foreach ($items as $item) {
            foreach ($item->allocations as $alloc) {
                $perUser[$alloc->user_id] ??= 0;
                $perUser[$alloc->user_id] += (int) $alloc->share_cents;
            }
        }

        $participantIds = array_keys($perUser);
        sort($participantIds);

        // Versand-Anteile gleichmäßig
        $shippingShares = Money::splitEvenly($shipping, count($participantIds));

        // Lead/Platform proportional zum goods-Anteil
        $perParticipant = [];
        $i = 0;
        foreach ($participantIds as $uid) {
            $userGoods = $perUser[$uid];
            $userLead = $goods > 0 ? Money::percent($userGoods, $leadPct) : 0;
            $userPlatform = $goods > 0 ? Money::percent($userGoods, $platformPct) : 0;

            $roundUp = (int) ($proposal->round->participants
                ->firstWhere('user_id', $uid)?->round_up_to_cents ?? 0);

            $roundUpDonation = 0;
            $subtotal = $userGoods + ($shippingShares[$i] ?? 0) + $userLead + $userPlatform;
            if ($roundUp > 0 && $subtotal > 0) {
                $roundUpDonation = max(0, Money::roundUpTo($subtotal, $roundUp) - $subtotal);
            }

            $perParticipant[] = new ParticipantTotals(
                userId: $uid,
                goodsCents: $userGoods,
                shippingShareCents: $shippingShares[$i] ?? 0,
                leadFeeCents: $userLead,
                platformFeeCents: $userPlatform,
                roundUpDonationCents: $roundUpDonation,
            );
            $i++;
        }

        // Rundungs-Verluste aus Prozent-Verteilung dem letzten Teilnehmer gutschreiben/abziehen
        $sumLeadShares = 0;
        $sumPlatformShares = 0;
        foreach ($perParticipant as $p) {
            $sumLeadShares += $p->leadFeeCents;
            $sumPlatformShares += $p->platformFeeCents;
        }
        $leadDelta = $leadFee - $sumLeadShares;
        $platformDelta = $platformFee - $sumPlatformShares;

        if (! empty($perParticipant) && ($leadDelta !== 0 || $platformDelta !== 0)) {
            $last = end($perParticipant);
            $idx = key($perParticipant);
            $perParticipant[$idx] = new ParticipantTotals(
                userId: $last->userId,
                goodsCents: $last->goodsCents,
                shippingShareCents: $last->shippingShareCents,
                leadFeeCents: $last->leadFeeCents + $leadDelta,
                platformFeeCents: $last->platformFeeCents + $platformDelta,
                roundUpDonationCents: $last->roundUpDonationCents,
            );
        }

        $grandTotal = $goods + $shipping + $leadFee + $platformFee;

        return new ProposalTotals(
            goodsCents: $goods,
            shippingCents: $shipping,
            leadFeeCents: $leadFee,
            platformFeeCents: $platformFee,
            grandTotalCents: $grandTotal,
            perParticipant: $perParticipant,
        );
    }
}
