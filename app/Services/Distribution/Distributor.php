<?php

namespace App\Services\Distribution;

use App\Enums\QuantityMode;
use App\Models\CartItem;
use App\Models\PriceTier;
use Illuminate\Support\Collection;

/**
 * Berechnet aus Warenkorb-Einträgen (exakte und flexible Mengen) eine
 * konkrete Aufteilung in Gebinde- bzw. Einzelmengen.
 *
 * Strategie (high-level):
 *
 *   - Teilbare Gebinde:
 *       1. Gesamtmenge auf das nächste Vielfache des Gebindes aufrunden.
 *       2. Exakte Anforderungen unverändert zuteilen.
 *       3. Restmenge proportional zur Flexibilität auf flexible Items verteilen,
 *          schrittweise auf `divisible_step` snappen.
 *       4. Rundungs-Rest wandert zum Item mit dem größten Spielraum.
 *
 *   - Nicht teilbare Gebinde:
 *       Jede:r Teilnehmer:in bekommt ganzzahlige Gebinde im Rahmen der eigenen
 *       Min/Max-Spanne. Auffüllung greedy nach größtem freien Wunsch.
 *
 * Der Algorithmus ist deterministisch und nachvollziehbar — nicht optimal,
 * aber für reale Gruppen-Bestellungen brauchbar und vom Lead jederzeit
 * hand-editierbar.
 */
class Distributor
{
    private const EPSILON = 0.001;

    /**
     * @param  Collection<int, CartItem>  $cartItems
     */
    public function compute(Collection $cartItems, PriceTier $tier): DistributionResult
    {
        $notes = [];

        if ($cartItems->isEmpty()) {
            return new DistributionResult(0, 0.0, 0, [], true, ['Keine Warenkorb-Einträge.']);
        }

        return $tier->is_divisible
            ? $this->distributeDivisible($cartItems, $tier, $notes)
            : $this->distributeIndivisible($cartItems, $tier, $notes);
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  array<int, string>  $notes
     */
    private function distributeDivisible(Collection $items, PriceTier $tier, array $notes): DistributionResult
    {
        $packageAmount = (float) $tier->package_amount;
        $step = (float) ($tier->divisible_step ?? $packageAmount);

        /** @var array<int, AllocationLine> $lines */
        $lines = [];
        $sumMin = 0.0;
        $sumMax = 0.0;

        foreach ($items as $item) {
            $min = $item->effectiveMin();
            $max = $item->effectiveMax();
            $line = new AllocationLine(
                userId: $item->user_id,
                cartItem: $item,
                requestedMin: $min,
                requestedMax: $max,
                allocatedQuantity: $min,
            );
            $lines[] = $line;
            $sumMin += $min;
            $sumMax += $max;
        }

        // Zielmenge = kleinstes Vielfaches der Gebindegröße >= sumMin
        $packages = max((int) $tier->min_order_packages, (int) ceil($sumMin / $packageAmount - self::EPSILON));
        $target = $packages * $packageAmount;

        $feasible = $target <= $sumMax + self::EPSILON;

        if (! $feasible) {
            $notes[] = sprintf(
                'Verteilung überschreitet die Maximal-Wünsche um %s %s.',
                number_format($target - $sumMax, 3, ',', '.'),
                $tier->product?->unit ?? '',
            );
        }

        // Slack pro Item
        $slack = [];
        $totalSlack = 0.0;
        foreach ($lines as $i => $l) {
            $slack[$i] = max(0.0, $l->requestedMax - $l->allocatedQuantity);
            $totalSlack += $slack[$i];
        }

        $remaining = $target - $sumMin;

        if ($remaining > self::EPSILON && $totalSlack > self::EPSILON) {
            // 1) proportional snappen
            foreach ($lines as $i => $l) {
                if ($slack[$i] <= 0.0) {
                    continue;
                }
                $proportional = ($slack[$i] / $totalSlack) * $remaining;
                $snapped = floor(($proportional + self::EPSILON) / $step) * $step;
                $snapped = min($snapped, $slack[$i]);
                $l->allocatedQuantity += $snapped;
                $remaining -= $snapped;
                $slack[$i] -= $snapped;
            }

            // 2) Rundungs-Rest auf die mit dem größten freien Slack legen
            while ($remaining > self::EPSILON) {
                $bestIdx = null;
                $bestSlack = 0.0;
                foreach ($lines as $i => $l) {
                    if ($slack[$i] >= $step - self::EPSILON && $slack[$i] > $bestSlack) {
                        $bestIdx = $i;
                        $bestSlack = $slack[$i];
                    }
                }
                if ($bestIdx === null) {
                    break;
                }
                $lines[$bestIdx]->allocatedQuantity += $step;
                $slack[$bestIdx] -= $step;
                $remaining -= $step;
            }

            // 3) Wenn immer noch Rest übrig: an die mit dem größten Slack drücken (Infeasible)
            if ($remaining > self::EPSILON) {
                $bestIdx = 0;
                foreach ($lines as $i => $l) {
                    if ($l->requestedMax - $l->allocatedQuantity > $lines[$bestIdx]->requestedMax - $lines[$bestIdx]->allocatedQuantity) {
                        $bestIdx = $i;
                    }
                }
                $lines[$bestIdx]->allocatedQuantity += $remaining;
                $lines[$bestIdx]->unfulfilled = $lines[$bestIdx]->allocatedQuantity > $lines[$bestIdx]->requestedMax + self::EPSILON;
                $remaining = 0.0;
            }
        }

        $totalPriceCents = $packages * (int) $tier->price_cents;
        $totalAllocated = array_sum(array_map(fn (AllocationLine $l) => $l->allocatedQuantity, $lines));

        // Preis-Anteile pro Linie
        if ($totalAllocated > self::EPSILON) {
            $assignedSum = 0;
            $lastIdx = count($lines) - 1;
            foreach ($lines as $i => $l) {
                if ($i === $lastIdx) {
                    $l->shareCents = $totalPriceCents - $assignedSum;
                } else {
                    $l->shareCents = (int) round(($l->allocatedQuantity / $totalAllocated) * $totalPriceCents);
                    $assignedSum += $l->shareCents;
                }
            }
        }

        return new DistributionResult(
            packagesOrdered: $packages,
            totalQuantity: $target,
            totalPriceCents: $totalPriceCents,
            allocations: $lines,
            feasible: $feasible,
            notes: $notes,
        );
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  array<int, string>  $notes
     */
    private function distributeIndivisible(Collection $items, PriceTier $tier, array $notes): DistributionResult
    {
        $packageAmount = (float) $tier->package_amount;
        $perItem = [];

        foreach ($items as $item) {
            $min = $item->effectiveMin();
            $max = $item->effectiveMax();

            $minPkg = (int) ceil($min / $packageAmount - self::EPSILON);
            $maxPkg = (int) floor($max / $packageAmount + self::EPSILON);

            if ($item->quantity_mode === QuantityMode::Exact) {
                $maxPkg = $minPkg;
            }

            if ($maxPkg < $minPkg) {
                $maxPkg = $minPkg;
                $notes[] = sprintf('Anpassung für User #%d: Mindestmenge erfordert mehr als Maximum.', $item->user_id);
            }

            $perItem[] = [
                'item' => $item,
                'minPkg' => $minPkg,
                'maxPkg' => $maxPkg,
                'pkg' => $minPkg,
            ];
        }

        $packages = array_sum(array_column($perItem, 'pkg'));
        $minPackages = max((int) $tier->min_order_packages, 0);

        // Auf Mindestmenge auffüllen, indem flexible Teilnehmer mehr nehmen
        while ($packages < $minPackages) {
            $candidate = null;
            $candidateSlack = 0;
            foreach ($perItem as $idx => $row) {
                $slack = $row['maxPkg'] - $row['pkg'];
                if ($slack > $candidateSlack) {
                    $candidate = $idx;
                    $candidateSlack = $slack;
                }
            }
            if ($candidate === null) {
                $notes[] = 'Mindestbestellmenge konnte nicht erreicht werden.';
                break;
            }
            $perItem[$candidate]['pkg']++;
            $packages++;
        }

        $lines = [];
        $totalAllocated = 0.0;
        foreach ($perItem as $row) {
            /** @var CartItem $item */
            $item = $row['item'];
            $qty = $row['pkg'] * $packageAmount;
            $totalAllocated += $qty;

            $lines[] = new AllocationLine(
                userId: $item->user_id,
                cartItem: $item,
                requestedMin: $item->effectiveMin(),
                requestedMax: $item->effectiveMax(),
                allocatedQuantity: $qty,
                unfulfilled: $row['pkg'] < $row['minPkg'] || $row['pkg'] > $row['maxPkg'],
            );
        }

        $totalPriceCents = $packages * (int) $tier->price_cents;

        if ($packages > 0) {
            $assignedSum = 0;
            $lastIdx = count($lines) - 1;
            foreach ($lines as $i => $l) {
                if ($i === $lastIdx) {
                    $l->shareCents = $totalPriceCents - $assignedSum;
                } else {
                    $l->shareCents = (int) round(($l->allocatedQuantity / $totalAllocated) * $totalPriceCents);
                    $assignedSum += $l->shareCents;
                }
            }
        }

        $feasible = collect($lines)->every(fn (AllocationLine $l) => ! $l->unfulfilled);

        return new DistributionResult(
            packagesOrdered: $packages,
            totalQuantity: $packages * $packageAmount,
            totalPriceCents: $totalPriceCents,
            allocations: $lines,
            feasible: $feasible,
            notes: $notes,
        );
    }
}
