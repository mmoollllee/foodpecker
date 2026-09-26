<?php

namespace App\Services\Distribution;

/**
 * Finds the packages to order for a demand, combining sizes where that
 * helps — 17 kg flour may become one 10 kg sack, one 5 kg sack and two
 * 1 kg bags.
 *
 * Among the combinations within the wanted range the lowest price per
 * unit wins: flexible people rather take a bit more when that makes it
 * cheaper for everybody. A bigger combination wins if it costs less in
 * total — a 25 kg sack for less than seven 3 kg cartons — and so does the
 * cheapest one when nothing fits; the rest is paid by all. On equal prices
 * the one overshooting less wins. A size with a minimum order is either not
 * used or used at least that often.
 */
class PackageMixer
{
    /**
     * Amounts are compared in thousandths of the unit.
     */
    private const PRECISION = 1000;

    /**
     * Largest search grid; beyond it the mixer falls back to a single size.
     */
    private const GRID_LIMIT = 200_000;

    /**
     * Sizes with a minimum order are tried in every combination of
     * "used" and "not used" — for up to this many of them.
     */
    private const MAX_MINIMUM_ORDER_SIZES = 4;

    /**
     * @param  array<int, PackageOption>  $options
     */
    public function best(array $options, float $min, float $max): PackageMix
    {
        $options = array_values(array_filter($options, fn (PackageOption $option): bool => $option->available && $option->amount > 0));
        $max = max($max, $min);

        if ($options === [] || $max <= 0) {
            return new PackageMix;
        }

        $units = array_map(fn (PackageOption $option): int => max(1, (int) round($option->amount * self::PRECISION)), $options);
        $grid = array_reduce($units, fn (int $carry, int $unit): int => $this->gcd($carry, $unit), 0);
        $sizes = array_map(fn (int $unit): int => intdiv($unit, $grid), $units);

        $minSteps = max(0, (int) ceil($min * self::PRECISION / $grid - 0.001));
        $maxSteps = max($minSteps, (int) floor($max * self::PRECISION / $grid + 0.001));
        $largest = max(array_map(fn (int $size, PackageOption $option): int => $size * $option->minOrderPackages, $sizes, $options));
        $limit = $maxSteps + $largest;

        if ($limit > self::GRID_LIMIT) {
            return $this->singleSize($options, $min);
        }

        $fitting = null;
        $overshooting = null;

        foreach ($this->minimumOrderSubsets($options) as $subset) {
            foreach ($this->bestWithin($options, $sizes, $subset, $minSteps, $maxSteps, $limit) as $candidate) {
                if ($candidate['fits']) {
                    $fitting = $fitting === null || $this->fitsBetter($candidate, $fitting) ? $candidate : $fitting;
                } else {
                    $overshooting = $overshooting === null || $this->overshootsBetter($candidate, $overshooting) ? $candidate : $overshooting;
                }
            }
        }

        // Nobody needs anything: order only what fits the wishes.
        if ($fitting === null && ($overshooting === null || $minSteps === 0)) {
            return new PackageMix;
        }

        $best = $overshooting !== null && ($fitting === null || $overshooting['cost'] < $fitting['cost'])
            ? $overshooting
            : $fitting;

        return PackageMix::fromCounts($options, $best['counts']);
    }

    /**
     * Exactly the given package counts, e.g. when the lead fixed them.
     * Sizes that are not available anymore are left out.
     *
     * @param  array<int, PackageOption>  $options
     * @param  array<int, int>  $countsByTierId
     */
    public function fixed(array $options, array $countsByTierId): PackageMix
    {
        $counts = [];

        foreach ($options as $index => $option) {
            if ($option->available && $option->priceTierId !== null) {
                $counts[$index] = max(0, (int) ($countsByTierId[$option->priceTierId] ?? 0));
            }
        }

        return PackageMix::fromCounts($options, $counts);
    }

    /**
     * The best combination within the wanted range and the best one beyond
     * it, for one choice of sizes with a minimum order.
     *
     * @param  array<int, PackageOption>  $options
     * @param  array<int, int>  $sizes
     * @param  array<int, int>  $subset  Indexes of sizes with a minimum order that are used.
     * @return list<array{counts: array<int, int>, steps: int, cost: int, packages: int, fits: bool, overshoot: int}>
     */
    private function bestWithin(array $options, array $sizes, array $subset, int $minSteps, int $maxSteps, int $limit): array
    {
        $baseSteps = 0;
        $baseCost = 0;
        $basePackages = 0;
        $baseCounts = [];

        foreach ($subset as $index) {
            $count = $options[$index]->minOrderPackages;
            $baseSteps += $count * $sizes[$index];
            $baseCost += $count * $options[$index]->priceCents;
            $basePackages += $count;
            $baseCounts[$index] = $count;
        }

        $allowed = array_keys(array_filter(
            $options,
            fn (PackageOption $option, int $index): bool => $option->minOrderPackages <= 1 || in_array($index, $subset, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        $upper = $limit - $baseSteps;

        if ($upper < 0) {
            return [];
        }

        [$cost, $packages, $choice] = $this->cheapestPerAmount($sizes, $options, $allowed, $upper);

        $fitting = null;
        $overshooting = null;

        for ($steps = 0; $steps <= $upper; $steps++) {
            $total = $baseSteps + $steps;

            if ($cost[$steps] < 0 || $total === 0 || $total < $minSteps) {
                continue;
            }

            $candidate = [
                'steps' => $total,
                'extra' => $steps,
                'cost' => $baseCost + $cost[$steps],
                'packages' => $basePackages + $packages[$steps],
                'fits' => $total <= $maxSteps,
                'overshoot' => max(0, $total - $maxSteps),
            ];

            if ($candidate['fits']) {
                $fitting = $fitting === null || $this->fitsBetter($candidate, $fitting) ? $candidate : $fitting;
            } elseif ($overshooting === null || $this->overshootsBetter($candidate, $overshooting)) {
                $overshooting = $candidate;
            }
        }

        return array_map(function (array $candidate) use ($baseCounts, $sizes, $choice): array {
            $counts = $baseCounts;

            for ($steps = $candidate['extra']; $steps > 0; $steps -= $sizes[$choice[$steps]]) {
                $counts[$choice[$steps]] = ($counts[$choice[$steps]] ?? 0) + 1;
            }

            unset($candidate['extra']);

            return ['counts' => $counts, ...$candidate];
        }, array_values(array_filter([$fitting, $overshooting])));
    }

    /**
     * Cheapest way to reach every exact amount up to the upper bound, with
     * as few packages as possible on a tie.
     *
     * @param  array<int, int>  $sizes
     * @param  array<int, PackageOption>  $options
     * @param  array<int, int>  $allowed
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>} cost (−1 = unreachable), packages, last size used
     */
    private function cheapestPerAmount(array $sizes, array $options, array $allowed, int $upper): array
    {
        $cost = array_fill(0, $upper + 1, -1);
        $packages = array_fill(0, $upper + 1, 0);
        $choice = array_fill(0, $upper + 1, -1);
        $cost[0] = 0;

        for ($steps = 1; $steps <= $upper; $steps++) {
            foreach ($allowed as $index) {
                $previous = $steps - $sizes[$index];

                if ($previous < 0 || $cost[$previous] < 0) {
                    continue;
                }

                $candidateCost = $cost[$previous] + $options[$index]->priceCents;
                $candidatePackages = $packages[$previous] + 1;

                if ($cost[$steps] < 0
                    || $candidateCost < $cost[$steps]
                    || ($candidateCost === $cost[$steps] && $candidatePackages < $packages[$steps])) {
                    $cost[$steps] = $candidateCost;
                    $packages[$steps] = $candidatePackages;
                    $choice[$steps] = $index;
                }
            }
        }

        return [$cost, $packages, $choice];
    }

    /**
     * Within the wanted range: the lower price per unit, then the lower
     * price, then fewer packages.
     *
     * @param  array{steps: int, cost: int, packages: int, fits: bool, overshoot: int}  $a
     * @param  array{steps: int, cost: int, packages: int, fits: bool, overshoot: int}  $b
     */
    private function fitsBetter(array $a, array $b): bool
    {
        $perUnit = ($a['cost'] * $b['steps']) <=> ($b['cost'] * $a['steps']);

        if ($perUnit !== 0) {
            return $perUnit < 0;
        }

        if ($a['cost'] !== $b['cost']) {
            return $a['cost'] < $b['cost'];
        }

        return $a['packages'] < $b['packages'];
    }

    /**
     * Beyond the wanted range: the lower price, then the smaller overshoot,
     * then fewer packages.
     *
     * @param  array{steps: int, cost: int, packages: int, fits: bool, overshoot: int}  $a
     * @param  array{steps: int, cost: int, packages: int, fits: bool, overshoot: int}  $b
     */
    private function overshootsBetter(array $a, array $b): bool
    {
        if ($a['cost'] !== $b['cost']) {
            return $a['cost'] < $b['cost'];
        }

        if ($a['overshoot'] !== $b['overshoot']) {
            return $a['overshoot'] < $b['overshoot'];
        }

        return $a['packages'] < $b['packages'];
    }

    /**
     * Every combination of used sizes among those with a minimum order.
     *
     * @param  array<int, PackageOption>  $options
     * @return array<int, array<int, int>>
     */
    private function minimumOrderSubsets(array $options): array
    {
        $withMinimum = array_slice(array_keys(array_filter(
            $options,
            fn (PackageOption $option): bool => $option->minOrderPackages > 1,
        )), 0, self::MAX_MINIMUM_ORDER_SIZES);

        $subsets = [[]];

        foreach ($withMinimum as $index) {
            foreach ($subsets as $subset) {
                $subsets[] = [...$subset, $index];
            }
        }

        return $subsets;
    }

    /**
     * Fallback for unusual amounts: the size with the lowest price per unit.
     *
     * @param  array<int, PackageOption>  $options
     */
    private function singleSize(array $options, float $min): PackageMix
    {
        $index = 0;
        $lowest = null;

        foreach ($options as $candidateIndex => $option) {
            if ($lowest === null || $option->pricePerUnitCents() < $lowest) {
                $lowest = $option->pricePerUnitCents();
                $index = $candidateIndex;
            }
        }

        $option = $options[$index];
        $count = max($option->minOrderPackages, (int) ceil($min / $option->amount - 0.001), 1);

        return PackageMix::fromCounts($options, [$index => $count]);
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return abs($a);
    }
}
