<?php

use App\Models\CartItem;

it('writes a quantity with its unit, packs in the plural', function (float $quantity, string $unit, string $expected) {
    expect(CartItem::formatAmount($quantity, $unit))->toBe($expected);
})->with([
    'one pack' => [1, 'Packung', '1 Packung'],
    'several packs' => [3, 'Packung', '3 Packungen'],
    'half a kilogram' => [0.5, 'kg', '0,5 kg'],
    'jars as a measure' => [2, 'Glas', '2 Glas'],
]);

it('writes a range of quantities with the unit once', function (float $min, float $max, string $unit, string $expected) {
    expect(CartItem::formatRange($min, $max, $unit))->toBe($expected);
})->with([
    'from one to three packs' => [1, 3, 'Packung', '1–3 Packungen'],
    'the same amount at both ends' => [2, 2, 'kg', '2 kg'],
]);
