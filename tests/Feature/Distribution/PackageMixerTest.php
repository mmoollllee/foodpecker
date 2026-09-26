<?php

use App\Services\Distribution\PackageMixer;
use App\Services\Distribution\PackageOption;

function size(int $tierId, float $amount, int $priceCents, int $minOrder = 1): PackageOption
{
    return new PackageOption($tierId, rtrim(rtrim(number_format($amount, 3, ',', ''), '0'), ',').' kg', $amount, $priceCents, $priceCents, $minOrder);
}

beforeEach(function () {
    $this->mixer = new PackageMixer;
});

it('takes the lowest price per unit within the wanted range', function () {
    $mix = $this->mixer->best([size(1, 10, 2800), size(2, 25, 5500), size(3, 50, 9500)], 20, 40);

    expect($mix->describe())->toBe('1 × 25 kg');
});

it('takes the cheapest combination for an exact amount', function () {
    $mix = $this->mixer->best([size(1, 10, 2100), size(2, 5, 1100), size(3, 1, 240)], 17, 17);

    expect($mix->describe())->toBe('1 × 10 kg, 1 × 5 kg, 2 × 1 kg')
        ->and($mix->totalPriceCents())->toBe(3680);
});

it('takes the cheapest combination when nothing fits', function () {
    $mix = $this->mixer->best([size(1, 3, 1284), size(2, 25, 4574)], 20, 20);

    expect($mix->describe())->toBe('1 × 25 kg')
        ->and($mix->totalPriceCents())->toBe(4574);
});

it('overshoots less when nothing fits and two combinations cost the same', function () {
    $mix = $this->mixer->best([size(1, 20, 4000), size(2, 30, 4000)], 15, 15);

    expect($mix->describe())->toBe('1 × 20 kg');
});

it('takes a bigger combination that costs less in total than one that fits', function () {
    $mix = $this->mixer->best([size(1, 1, 300), size(2, 25, 5000)], 20, 20);

    expect($mix->describe())->toBe('1 × 25 kg');
});

it('keeps the fitting combination when a bigger one costs more', function () {
    $mix = $this->mixer->best([size(1, 1, 200), size(2, 25, 5000)], 20, 20);

    expect($mix->describe())->toBe('20 × 1 kg');
});

it('uses a size with a minimum order at least that often', function () {
    $mix = $this->mixer->best([size(1, 1, 100, minOrder: 10), size(2, 5, 600)], 3, 3);

    expect($mix->describe())->toBe('1 × 5 kg');
});

it('orders nothing when nobody needs anything and nothing fits', function () {
    $mix = $this->mixer->best([size(1, 25, 4800)], 0, 5);

    expect($mix->isEmpty())->toBeTrue();
});

it('keeps package counts fixed by hand', function () {
    $mix = $this->mixer->fixed([size(1, 10, 2800), size(2, 25, 5500)], [1 => 3]);

    expect($mix->describe())->toBe('3 × 10 kg')
        ->and($mix->totalPriceCents())->toBe(8400);
});
