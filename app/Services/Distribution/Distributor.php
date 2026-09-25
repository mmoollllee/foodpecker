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
 * The lead may override the number of packages (e.g. after negotiating) and
 * the package price. Quantity that nobody wants within their maximum stays
 * unallocated and is reported as leftover; its cost is shared in proportion
 * to the allocated quantities.
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
     * @param  int|null  $packages  Number of packages to order instead of the computed minimum.
     */
    public function compute(Collection $cartItems, PriceTier|PackageSpec $package, ?int $packages = null): DistributionResult
    {
        $spec = $package instanceof PriceTier ? PackageSpec::fromTier($package) : $package;

        if ($cartItems->isEmpty()) {
            return new DistributionResult(0, 0.0, 0, [], true, ['Keine Warenkorb-Einträge.']);
        }

        $unit = $cartItems->first()?->product?->unitLabel() ?? '';

        return $spec->isDivisible
            ? $this->distributeDivisible($cartItems, $spec, $packages, $unit)
            : $this->distributeIndivisible($cartItems, $spec, $packages, $unit);
    }

    /**
     * @param  Collection<int, CartItem>  $items
     */
    private function distributeDivisible(Collection $items, PackageSpec $spec, ?int $packagesOverride, string $unit): DistributionResult
    {
        $notes = [];
        $packageAmount = $spec->packageAmount;
        $step = $spec->step();

        /** @var array<int, AllocationLine> $lines */
        $lines = [];
        $sumMin = 0.0;
        $sumMax = 0.0;

        foreach ($items as $item) {
            $min = $item->effectiveMin();
            $max = $item->effectiveMax();
            $lines[] = new AllocationLine(
                userId: $item->user_id,
                cartItem: $item,
                requestedMin: $min,
                requestedMax: $max,
                allocatedQuantity: 0.0,
            );
            $sumMin += $min;
            $sumMax += $max;
        }

        $packages = $packagesOverride
            ?? max($spec->minOrderPackages, (int) ceil($sumMin / $packageAmount - self::EPSILON));
        $packages = max(0, $packages);
        $target = $packages * $packageAmount;

        if ($target + self::EPSILON >= $sumMin) {
            $this->fillUpToTarget($lines, $target, $sumMin, $step);
        } else {
            $this->shrinkToTarget($lines, $target, $sumMin, $step);
            $notes[] = sprintf(
                'Es fehlen %s %s zu den Mindestwünschen.',
                $this->formatQuantity($sumMin - $target),
                $unit,
            );
        }

        $allocated = array_sum(array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $lines));
        $leftover = max(0.0, $target - $allocated);

        if ($leftover > self::EPSILON) {
            $notes[] = sprintf(
                '%s %s bleiben übrig, weil niemand mehr möchte — sie werden anteilig mitbezahlt.',
                $this->formatQuantity($leftover),
                $unit,
            );
        }

        foreach ($lines as $line) {
            $line->unfulfilled = $line->allocatedQuantity < $line->requestedMin - self::EPSILON
                || $line->allocatedQuantity > $line->requestedMax + self::EPSILON;
        }

        $totalPriceCents = $packages * $spec->priceCents;
        $this->assignShares($lines, $totalPriceCents);

        $feasible = $leftover <= self::EPSILON
            && collect($lines)->every(fn (AllocationLine $line): bool => ! $line->unfulfilled);

        return new DistributionResult(
            packagesOrdered: $packages,
            totalQuantity: $target,
            totalPriceCents: $totalPriceCents,
            allocations: $lines,
            feasible: $feasible,
            notes: $notes,
            unallocatedQuantity: $leftover,
        );
    }

    /**
     * Everybody gets their minimum; the rest is spread in proportion to the
     * remaining flexibility, snapped to the divisible step.
     *
     * @param  array<int, AllocationLine>  $lines
     */
    private function fillUpToTarget(array $lines, float $target, float $sumMin, float $step): void
    {
        $slack = [];
        $totalSlack = 0.0;

        foreach ($lines as $i => $line) {
            $line->allocatedQuantity = $line->requestedMin;
            $slack[$i] = max(0.0, $line->requestedMax - $line->requestedMin);
            $totalSlack += $slack[$i];
        }

        $remaining = $target - $sumMin;

        if ($remaining <= self::EPSILON || $totalSlack <= self::EPSILON) {
            return;
        }

        foreach ($lines as $i => $line) {
            if ($slack[$i] <= 0.0) {
                continue;
            }

            $proportional = min($slack[$i], ($slack[$i] / $totalSlack) * min($remaining, $totalSlack));
            $snapped = min(floor(($proportional + self::EPSILON) / $step) * $step, $slack[$i]);
            $line->allocatedQuantity += $snapped;
            $slack[$i] -= $snapped;
        }

        $remaining = $target - array_sum(array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $lines));

        while ($remaining > self::EPSILON) {
            $bestIndex = null;
            $bestSlack = 0.0;

            foreach ($lines as $i => $line) {
                if ($slack[$i] >= min($step, $remaining) - self::EPSILON && $slack[$i] > $bestSlack) {
                    $bestIndex = $i;
                    $bestSlack = $slack[$i];
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $portion = min($step, $remaining, $slack[$bestIndex]);
            $lines[$bestIndex]->allocatedQuantity += $portion;
            $slack[$bestIndex] -= $portion;
            $remaining -= $portion;
        }
    }

    /**
     * Fewer packages than the minimum wishes: everybody is cut in proportion
     * to their minimum, snapped to the divisible step.
     *
     * @param  array<int, AllocationLine>  $lines
     */
    private function shrinkToTarget(array $lines, float $target, float $sumMin, float $step): void
    {
        $factor = $sumMin > 0 ? $target / $sumMin : 0.0;

        foreach ($lines as $line) {
            $line->allocatedQuantity = floor(($line->requestedMin * $factor + self::EPSILON) / $step) * $step;
        }

        $remaining = $target - array_sum(array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $lines));

        while ($remaining > self::EPSILON) {
            $bestIndex = null;
            $bestShortfall = 0.0;

            foreach ($lines as $i => $line) {
                $shortfall = $line->requestedMin - $line->allocatedQuantity;
                if ($shortfall > $bestShortfall + self::EPSILON) {
                    $bestIndex = $i;
                    $bestShortfall = $shortfall;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $portion = min($step, $remaining, $bestShortfall);
            $lines[$bestIndex]->allocatedQuantity += $portion;
            $remaining -= $portion;
        }
    }

    /**
     * @param  Collection<int, CartItem>  $items
     */
    private function distributeIndivisible(Collection $items, PackageSpec $spec, ?int $packagesOverride, string $unit): DistributionResult
    {
        $notes = [];
        $packageAmount = $spec->packageAmount;
        $rows = [];

        foreach ($items as $item) {
            $min = $item->effectiveMin();
            $max = $item->effectiveMax();

            $minPackages = (int) ceil($min / $packageAmount - self::EPSILON);
            $maxPackages = (int) floor($max / $packageAmount + self::EPSILON);

            if ($item->quantity_mode === QuantityMode::Exact) {
                $maxPackages = $minPackages;
            }

            if ($maxPackages < $minPackages) {
                $maxPackages = $minPackages;
                $notes[] = sprintf(
                    '%s: Wunschmenge passt nicht genau in ganze Packungen, es wird aufgerundet.',
                    $item->user?->first_name ?? 'Teilnehmer #'.$item->user_id,
                );
            }

            $rows[] = [
                'item' => $item,
                'minPackages' => $minPackages,
                'maxPackages' => $maxPackages,
                'packages' => $minPackages,
            ];
        }

        $assigned = array_sum(array_column($rows, 'packages'));
        $target = max(0, $packagesOverride ?? max($assigned, $spec->minOrderPackages));

        while ($assigned < $target) {
            $candidate = null;
            $candidateSlack = 0;

            foreach ($rows as $index => $row) {
                $slack = $row['maxPackages'] - $row['packages'];
                if ($slack > $candidateSlack) {
                    $candidate = $index;
                    $candidateSlack = $slack;
                }
            }

            if ($candidate === null) {
                break;
            }

            $rows[$candidate]['packages']++;
            $assigned++;
        }

        while ($assigned > $target) {
            $candidate = null;
            $candidateScore = null;

            foreach ($rows as $index => $row) {
                if ($row['packages'] <= 0) {
                    continue;
                }

                // Prefer taking packages from flexible people above their minimum.
                $score = [($row['packages'] > $row['minPackages']) ? 1 : 0, $row['packages']];
                if ($candidateScore === null || $score > $candidateScore) {
                    $candidate = $index;
                    $candidateScore = $score;
                }
            }

            if ($candidate === null) {
                break;
            }

            $rows[$candidate]['packages']--;
            $assigned--;
        }

        $packages = max($target, $assigned);
        $leftoverPackages = $packages - $assigned;

        if ($leftoverPackages > 0) {
            $notes[] = sprintf(
                '%d Packung(en) bleiben übrig, weil niemand mehr möchte — sie werden anteilig mitbezahlt.',
                $leftoverPackages,
            );
        }

        $lines = [];
        foreach ($rows as $row) {
            /** @var CartItem $item */
            $item = $row['item'];

            $lines[] = new AllocationLine(
                userId: $item->user_id,
                cartItem: $item,
                requestedMin: $item->effectiveMin(),
                requestedMax: $item->effectiveMax(),
                allocatedQuantity: $row['packages'] * $packageAmount,
                unfulfilled: $row['packages'] < $row['minPackages'] || $row['packages'] > $row['maxPackages'],
            );
        }

        if (collect($lines)->contains(fn (AllocationLine $line): bool => $line->allocatedQuantity < $line->requestedMin - self::EPSILON)) {
            $notes[] = sprintf('Nicht alle Mindestwünsche passen in %d Packung(en).', $packages);
        }

        $totalPriceCents = $packages * $spec->priceCents;
        $this->assignShares($lines, $totalPriceCents);

        $feasible = $leftoverPackages === 0
            && collect($lines)->every(fn (AllocationLine $line): bool => ! $line->unfulfilled);

        return new DistributionResult(
            packagesOrdered: $packages,
            totalQuantity: $packages * $packageAmount,
            totalPriceCents: $totalPriceCents,
            allocations: $lines,
            feasible: $feasible,
            notes: $notes,
            unallocatedQuantity: $leftoverPackages * $packageAmount,
        );
    }

    /**
     * Splits the total price in proportion to the allocated quantities,
     * exact to the cent (the last line absorbs rounding differences).
     *
     * @param  array<int, AllocationLine>  $lines
     */
    private function assignShares(array $lines, int $totalPriceCents): void
    {
        $totalAllocated = array_sum(array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $lines));

        if ($totalAllocated <= self::EPSILON || $lines === []) {
            return;
        }

        $assignedSum = 0;
        $lastIndex = array_key_last($lines);

        foreach ($lines as $i => $line) {
            if ($i === $lastIndex) {
                $line->shareCents = $totalPriceCents - $assignedSum;
            } else {
                $line->shareCents = (int) round(($line->allocatedQuantity / $totalAllocated) * $totalPriceCents);
                $assignedSum += $line->shareCents;
            }
        }
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');
    }
}
