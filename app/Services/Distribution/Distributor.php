<?php

namespace App\Services\Distribution;

use App\Models\CartItem;
use App\Services\Money\Money;

/**
 * Turns the wishes for a product (exact amounts and flexible ranges) into
 * packages to order and the share everybody gets.
 *
 *   - Portioned products (flour, rice, mustard by the jar): the packages
 *     are shared out in portions. Exact wishes and amounts set by hand are
 *     handed out as they are, the rest goes to the flexible people in
 *     proportion to their room, snapped to the portion size. Everybody
 *     pays the same mixed price per unit.
 *   - Whole packages (spaghetti in 250 g and 2 kg bags): everybody gets
 *     their own packages — of the size they prefer, or the combination
 *     that fits their wish best — and pays for them.
 *
 * What nobody wants within their maximum stays over and is paid by all in
 * proportion to their shares. The algorithm is deterministic and easy to
 * follow — not optimal, and the proposer can always correct it by hand.
 */
class Distributor
{
    private const EPSILON = 0.001;

    public function __construct(private PackageMixer $mixer) {}

    /**
     * @param  array<int, Demand>  $demands
     * @param  array<int, PackageOption>  $options
     * @param  float|null  $portionSize  Null: every package goes whole to one person.
     * @param  array<int, int>|null  $fixedCounts  Package counts by price tier id, fixed by hand.
     * @param  float|null  $roundingStep  Coarser step for the flexible shares, e.g. whole kilograms.
     */
    public function distribute(
        array $demands,
        array $options,
        ?float $portionSize,
        string $unit = '',
        ?array $fixedCounts = null,
        ?float $roundingStep = null,
    ): DistributionResult {
        if ($demands === []) {
            return new DistributionResult(new PackageMix, [], ['Keine Warenkorb-Einträge.']);
        }

        return $portionSize !== null && $portionSize > 0
            ? $this->portions($demands, $options, $portionSize, $unit, $fixedCounts, $roundingStep)
            : $this->wholePackages($demands, $options, $unit);
    }

    /**
     * @param  array<int, Demand>  $demands
     * @param  array<int, PackageOption>  $options
     * @param  array<int, int>|null  $fixedCounts
     */
    private function portions(array $demands, array $options, float $portionSize, string $unit, ?array $fixedCounts, ?float $roundingStep): DistributionResult
    {
        $notes = [];
        $sumMin = array_sum(array_map(fn (Demand $demand): float => $demand->wantedMin(), $demands));
        $sumMax = array_sum(array_map(fn (Demand $demand): float => $demand->wantedMax(), $demands));

        $mix = $fixedCounts !== null
            ? $this->mixer->fixed($options, $fixedCounts)
            : $this->mixer->best($options, $sumMin, $sumMax);

        if ($mix->isEmpty() && $sumMin > self::EPSILON) {
            $notes[] = 'Keine Gebindegröße ist lieferbar.';
        }

        $target = $mix->totalAmount();
        $step = max($portionSize, $roundingStep ?? 0.0);

        /** @var array<int, AllocationLine> $manual */
        $manual = [];
        /** @var array<int, AllocationLine> $flexible */
        $flexible = [];

        foreach ($demands as $demand) {
            $line = new AllocationLine(
                userId: $demand->userId,
                requestedMin: $demand->min,
                requestedMax: $demand->max,
                allocatedQuantity: $demand->manualQuantity ?? 0.0,
                manual: $demand->isManual(),
            );

            if ($demand->isManual()) {
                $manual[] = $line;
            } else {
                $flexible[] = $line;
            }
        }

        $manualSum = array_sum(array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $manual));
        $remaining = max(0.0, $target - $manualSum);
        $flexibleMin = array_sum(array_map(fn (AllocationLine $line): float => $line->requestedMin, $flexible));

        if ($remaining + self::EPSILON >= $flexibleMin) {
            $this->fillUpToTarget($flexible, $remaining, $flexibleMin, $step);
        } else {
            $this->shrinkToTarget($flexible, $remaining, $flexibleMin, $step);
            $notes[] = sprintf('Es fehlen %s zu den Mindestwünschen.', CartItem::formatAmount($flexibleMin - $remaining, $unit));
        }

        $order = array_flip(array_map(fn (Demand $demand): int => $demand->userId, $demands));
        $lines = [...$manual, ...$flexible];
        usort($lines, fn (AllocationLine $a, AllocationLine $b): int => $order[$a->userId] <=> $order[$b->userId]);

        $allocated = array_sum(array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $lines));
        $overhang = max(0.0, $target - $allocated);
        $shortfall = max(0.0, $allocated - $target);

        if ($overhang > self::EPSILON) {
            $notes[] = sprintf('%s %s übrig, weil niemand mehr möchte — sie werden anteilig mitbezahlt.', CartItem::formatAmount($overhang, $unit), abs($overhang - 1) < 0.0005 ? 'bleibt' : 'bleiben');
        }

        if ($shortfall > self::EPSILON) {
            $notes[] = sprintf('Es sind %s mehr verteilt als bestellt.', CartItem::formatAmount($shortfall, $unit));
        }

        $this->markUnfulfilled($lines);
        $this->assignShares($lines, $mix->totalPriceCents());

        return new DistributionResult($mix, $lines, $notes, $overhang, $shortfall);
    }

    /**
     * Everybody gets their own packages. The package counts follow from the
     * people; a minimum order may add packages — first for people whose
     * wish still has room, the rest nobody asked for.
     *
     * @param  array<int, Demand>  $demands
     * @param  array<int, PackageOption>  $options
     */
    private function wholePackages(array $demands, array $options, string $unit): DistributionResult
    {
        $demands = array_values($demands);
        $notes = [];
        $lines = [];
        $counts = [];

        foreach ($demands as $demand) {
            $personal = $this->optionsFor($demand, $options);
            $personalMix = $this->mixer->best($personal, $demand->wantedMin(), $demand->wantedMax());
            $packageCounts = [];

            foreach ($personalMix->lines as $mixLine) {
                $tierId = (int) $mixLine['option']->priceTierId;
                $packageCounts[$tierId] = $mixLine['count'];
                $counts[$tierId] = ($counts[$tierId] ?? 0) + $mixLine['count'];
            }

            $lines[] = new AllocationLine(
                userId: $demand->userId,
                requestedMin: $demand->min,
                requestedMax: $demand->max,
                allocatedQuantity: $personalMix->totalAmount(),
                shareCents: $personalMix->totalPriceCents(),
                manual: $demand->isManual(),
                packageCounts: $packageCounts,
            );
        }

        $overhang = 0.0;
        $leftoverCents = 0;

        foreach ($options as $option) {
            $count = $counts[(int) $option->priceTierId] ?? 0;

            if ($count > 0 && $count < $option->minOrderPackages) {
                $counts[(int) $option->priceTierId] = $option->minOrderPackages;
                $missing = $option->minOrderPackages - $count;
                $missing -= $this->handOutExtraPackages($lines, $demands, $option, $missing);

                if ($missing > 0) {
                    $overhang += $missing * $option->amount;
                    $leftoverCents += $missing * $option->priceCents;
                    $notes[] = sprintf('%s: Mindestbestellmenge %d — %d Packung(en) bleiben übrig und werden anteilig mitbezahlt.', $option->label, $option->minOrderPackages, $missing);
                }
            }
        }

        $mix = PackageMix::fromCounts($options, array_map(
            fn (PackageOption $option): int => $counts[(int) $option->priceTierId] ?? 0,
            $options,
        ));

        if ($mix->isEmpty() && array_sum(array_map(fn (Demand $demand): float => $demand->wantedMin(), $demands)) > self::EPSILON) {
            $notes[] = 'Keine Packungsgröße ist lieferbar.';
        }

        if (collect($lines)->contains(fn (AllocationLine $line): bool => ! $line->manual && $line->allocatedQuantity > $line->requestedMax + self::EPSILON)) {
            $notes[] = 'Nicht jede Wunschmenge passt genau in ganze Packungen — dort wird aufgerundet.';
        }

        $this->markUnfulfilled($lines);
        $this->spreadExtraCost($lines, $leftoverCents);

        return new DistributionResult($mix, $lines, $notes, $overhang);
    }

    /**
     * Hands packages a minimum order adds to people whose wish still has room
     * for them, whoever has the most room first. Amounts set by hand and
     * other preferred sizes stay as they are.
     *
     * @param  array<int, AllocationLine>  $lines  In the order of the demands.
     * @param  array<int, Demand>  $demands
     * @return int How many of the packages found somebody.
     */
    private function handOutExtraPackages(array $lines, array $demands, PackageOption $option, int $packages): int
    {
        $tierId = (int) $option->priceTierId;
        $handedOut = 0;

        while ($handedOut < $packages) {
            $best = null;
            $bestRoom = 0.0;

            foreach ($demands as $i => $demand) {
                $room = $demand->wantedMax() - $lines[$i]->allocatedQuantity;
                $fits = ! $demand->isManual()
                    && ($demand->preferredTierId === null || $demand->preferredTierId === $option->priceTierId)
                    && $room + self::EPSILON >= $option->amount;

                if ($fits && $room > $bestRoom) {
                    $best = $i;
                    $bestRoom = $room;
                }
            }

            if ($best === null) {
                break;
            }

            $lines[$best]->allocatedQuantity += $option->amount;
            $lines[$best]->shareCents += $option->priceCents;
            $lines[$best]->packageCounts[$tierId] = ($lines[$best]->packageCounts[$tierId] ?? 0) + 1;
            $handedOut++;
        }

        return $handedOut;
    }

    /**
     * Whole packages of the preferred size, if it can be delivered. Minimum
     * orders apply to the whole order, not to a single person.
     *
     * @param  array<int, PackageOption>  $options
     * @return array<int, PackageOption>
     */
    private function optionsFor(Demand $demand, array $options): array
    {
        $options = array_map(fn (PackageOption $option): PackageOption => $option->withoutMinimumOrder(), $options);

        if ($demand->preferredTierId === null) {
            return $options;
        }

        $preferred = array_values(array_filter(
            $options,
            fn (PackageOption $option): bool => $option->priceTierId === $demand->preferredTierId && $option->available,
        ));

        return $preferred !== [] ? $preferred : $options;
    }

    /**
     * Everybody gets their minimum; the rest is spread in proportion to the
     * remaining flexibility, snapped to the step.
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
     * Less than the minimum wishes: everybody is cut in proportion to their
     * minimum, snapped to the step.
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
     * @param  array<int, AllocationLine>  $lines
     */
    private function markUnfulfilled(array $lines): void
    {
        foreach ($lines as $line) {
            $line->allocatedQuantity = round($line->allocatedQuantity, 3);
            $line->unfulfilled = $line->allocatedQuantity < $line->requestedMin - self::EPSILON
                || $line->allocatedQuantity > $line->requestedMax + self::EPSILON;
        }
    }

    /**
     * Splits the total price in proportion to the allocated quantities,
     * exact to the cent.
     *
     * @param  array<int, AllocationLine>  $lines
     */
    private function assignShares(array $lines, int $totalPriceCents): void
    {
        foreach ($lines as $line) {
            $line->shareCents = 0;
        }

        $receivers = array_values(array_filter($lines, fn (AllocationLine $line): bool => $line->allocatedQuantity > self::EPSILON));

        if ($receivers === []) {
            return;
        }

        $shares = Money::splitProportionally($totalPriceCents, array_map(fn (AllocationLine $line): float => $line->allocatedQuantity, $receivers));

        foreach ($receivers as $i => $line) {
            $line->shareCents = $shares[$i];
        }
    }

    /**
     * Packages nobody asked for are paid in proportion to the shares.
     *
     * @param  array<int, AllocationLine>  $lines
     */
    private function spreadExtraCost(array $lines, int $extraCents): void
    {
        $receivers = array_values(array_filter($lines, fn (AllocationLine $line): bool => $line->shareCents > 0));

        if ($extraCents <= 0 || $receivers === []) {
            return;
        }

        $extras = Money::splitProportionally($extraCents, array_map(fn (AllocationLine $line): int => $line->shareCents, $receivers));

        foreach ($receivers as $i => $line) {
            $line->shareCents += $extras[$i];
        }
    }
}
