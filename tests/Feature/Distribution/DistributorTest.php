<?php

use App\Services\Distribution\Demand;
use App\Services\Distribution\Distributor;
use App\Services\Distribution\PackageOption;

function package(int $tierId, float $amount, int $priceCents, int $minOrder = 1, bool $available = true): PackageOption
{
    return new PackageOption($tierId, rtrim(rtrim(number_format($amount, 3, ',', ''), '0'), ',').' kg', $amount, $priceCents, $priceCents, $minOrder, null, $available);
}

beforeEach(function () {
    $this->distributor = app(Distributor::class);
});

it('fills the packages up with the flexible wishes', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 5, 5),
        new Demand(2, 8, 8),
        new Demand(3, 3, 12),
    ], [package(1, 25, 5500)], portionSize: 0.5, unit: 'kg');

    expect($result->mix->describe())->toBe('1 × 25 kg')
        ->and($result->allocationFor(3)->allocatedQuantity)->toBe(12.0)
        ->and($result->sumAllocated())->toBe(25.0)
        ->and($result->fits())->toBeTrue();
});

it('spreads the rest in proportion to the flexibility', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 2, 6),
        new Demand(2, 2, 6),
    ], [package(1, 10, 2800)], portionSize: 1, unit: 'kg');

    expect($result->allocationFor(1)->allocatedQuantity)->toBe(5.0)
        ->and($result->allocationFor(2)->allocatedQuantity)->toBe(5.0);
});

it('hands out portions only', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 1, 3),
        new Demand(2, 1, 3),
        new Demand(3, 1, 3),
    ], [package(1, 5, 1500)], portionSize: 0.5, unit: 'kg');

    expect(collect($result->allocations)->pluck('allocatedQuantity')->all())->toBe([2.0, 1.5, 1.5]);
});

it('combines package sizes to match the wished amount exactly', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 12, 12),
        new Demand(2, 5, 5),
    ], [package(1, 10, 2100), package(2, 5, 1100), package(3, 1, 240)], portionSize: 0.5, unit: 'kg');

    expect($result->mix->describe())->toBe('1 × 10 kg, 1 × 5 kg, 2 × 1 kg')
        ->and($result->totalPriceCents())->toBe(3680)
        ->and($result->overhang)->toBe(0.0)
        ->and($result->fits())->toBeTrue();
});

it('splits the price in proportion to the amounts, exact to the cent', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 12, 12),
        new Demand(2, 5, 5),
    ], [package(1, 10, 2100), package(2, 5, 1100), package(3, 1, 240)], portionSize: 0.5);

    expect($result->allocationFor(1)->shareCents)->toBe(2598)
        ->and($result->allocationFor(2)->shareCents)->toBe(1082);
});

it('keeps amounts set by hand and distributes the rest', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 5, 15),
        new Demand(2, 5, 15, manualQuantity: 10),
    ], [package(1, 25, 4800)], portionSize: 0.5, unit: 'kg');

    expect($result->allocationFor(2)->allocatedQuantity)->toBe(10.0)
        ->and($result->allocationFor(2)->manual)->toBeTrue()
        ->and($result->allocationFor(1)->allocatedQuantity)->toBe(15.0);
});

it('reports a shortfall when more is handed out by hand than ordered', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 5, 15, manualQuantity: 20),
        new Demand(2, 5, 15, manualQuantity: 10),
    ], [package(1, 25, 4800)], portionSize: 0.5, unit: 'kg', fixedCounts: [1 => 1]);

    expect($result->shortfall)->toBe(5.0)
        ->and($result->notes)->toContain('Es sind 5 kg mehr verteilt als bestellt.');
});

it('distributes a package count fixed by hand and reports the leftover', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 6, 6),
        new Demand(2, 2, 8),
    ], [package(1, 10, 3000)], portionSize: 1, unit: 'kg', fixedCounts: [1 => 2]);

    expect($result->totalPriceCents())->toBe(6000)
        ->and($result->sumAllocated())->toBe(14.0)
        ->and($result->overhang)->toBe(6.0)
        ->and($result->fits())->toBeFalse()
        ->and(collect($result->allocations)->sum('shareCents'))->toBe(6000);
});

it('cuts the wishes in proportion when fewer packages are ordered than wanted', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 10, 10),
        new Demand(2, 10, 10),
    ], [package(1, 10, 3000)], portionSize: 1, unit: 'kg', fixedCounts: [1 => 1]);

    expect(collect($result->allocations)->pluck('allocatedQuantity')->all())->toBe([5.0, 5.0])
        ->and($result->notes)->toContain('Es fehlen 10 kg zu den Mindestwünschen.');
});

it('rounds the flexible shares to a coarser step', function () {
    $demands = [new Demand(1, 1, 4), new Demand(2, 1, 2)];

    $fine = $this->distributor->distribute($demands, [package(1, 5, 1850)], portionSize: 0.1);
    $rounded = $this->distributor->distribute($demands, [package(1, 5, 1850)], portionSize: 0.1, roundingStep: 0.5);

    expect(collect($fine->allocations)->pluck('allocatedQuantity')->all())->toBe([3.3, 1.7])
        ->and(collect($rounded->allocations)->pluck('allocatedQuantity')->all())->toBe([3.5, 1.5]);
});

it('gives everybody their own whole packages of the best fitting sizes', function () {
    $small = package(1, 0.25, 110);
    $large = package(2, 2, 740);

    $result = $this->distributor->distribute([
        new Demand(1, 2.5, 2.5),
        new Demand(2, 1, 1, preferredTierId: 1),
    ], [$small, $large], portionSize: null, unit: 'kg');

    expect($result->allocationFor(1)->packageCounts)->toBe([2 => 1, 1 => 2])
        ->and($result->allocationFor(1)->shareCents)->toBe(960)
        ->and($result->allocationFor(2)->packageCounts)->toBe([1 => 4])
        ->and($result->allocationFor(2)->shareCents)->toBe(440)
        ->and($result->mix->describe())->toBe('1 × 2 kg, 6 × 0,25 kg');
});

it('orders the minimum of a size and spreads the cost of the extra packages', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 2, 2),
        new Demand(2, 1, 1),
    ], [package(1, 1, 300, minOrder: 5)], portionSize: null, unit: 'kg');

    expect($result->mix->describe())->toBe('5 × 1 kg')
        ->and($result->overhang)->toBe(2.0)
        ->and($result->allocationFor(1)->shareCents)->toBe(1000)
        ->and($result->allocationFor(2)->shareCents)->toBe(500);
});

it('leaves out sizes the supplier can not deliver', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 25, 25),
    ], [package(1, 25, 5000, available: false), package(2, 10, 2800)], portionSize: 0.5);

    expect($result->mix->describe())->toBe('3 × 10 kg');
});

it('orders nothing when no size can be delivered', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 25, 25),
    ], [package(1, 25, 5000, available: false)], portionSize: 0.5);

    expect($result->mix->isEmpty())->toBeTrue()
        ->and($result->notes)->toContain('Keine Gebindegröße ist lieferbar.');
});

it('returns nothing without wishes', function () {
    $result = $this->distributor->distribute([], [package(1, 10, 2800)], portionSize: 0.5);

    expect($result->allocations)->toBe([])
        ->and($result->mix->isEmpty())->toBeTrue();
});

it('hands packages a minimum order adds to people who still have room first', function () {
    $result = $this->distributor->distribute([
        new Demand(1, 1, 5),
        new Demand(2, 1, 1),
    ], [package(1, 0.5, 200, minOrder: 10)], portionSize: null, unit: 'kg');

    expect($result->mix->describe())->toBe('10 × 0,5 kg')
        ->and($result->allocationFor(1)->allocatedQuantity)->toBe(4.0)
        ->and($result->allocationFor(2)->allocatedQuantity)->toBe(1.0)
        ->and($result->overhang)->toBe(0.0)
        ->and([$result->allocationFor(1)->shareCents, $result->allocationFor(2)->shareCents])->toBe([1600, 400]);
});
