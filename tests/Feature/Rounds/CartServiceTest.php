<?php

use App\Enums\ProductUnit;
use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Services\Rounds\CartService;
use Illuminate\Validation\ValidationException;

it('turns down amounts that are not whole portions', function (array $wish) {
    ['group' => $group, 'round' => $round, 'members' => [$anna]] = roundScenario(1, RoundPhase::Shopping);
    $pesto = productWithTier($group, packageAmount: 6, priceCents: 1438, portion: 1);
    $pesto->update(['name' => 'Pesto Genovese', 'unit' => ProductUnit::Jar]);

    expect(fn () => app(CartService::class)->save($round, $anna, ['product_id' => $pesto->id, ...$wish]))
        ->toThrow(ValidationException::class, 'Pesto Genovese wird in Portionen zu 1 Glas verteilt — bitte ein Vielfaches davon angeben.');
    expect(CartItem::count())->toBe(0);
})->with([
    'half a jar' => [['quantity_mode' => QuantityMode::Exact, 'exact_quantity' => 1.5]],
    'up to two and a half jars' => [['quantity_mode' => QuantityMode::Flexible, 'min_quantity' => 1, 'max_quantity' => 2.5]],
]);

it('takes amounts in whole portions of any size', function () {
    ['group' => $group, 'round' => $round, 'members' => [$anna]] = roundScenario(1, RoundPhase::Shopping);
    $lupinelle = productWithTier($group, packageAmount: 2.8, priceCents: 2508, portion: 0.35);

    $item = app(CartService::class)->save($round, $anna, ['product_id' => $lupinelle->id, 'quantity_mode' => QuantityMode::Exact, 'exact_quantity' => 1.05]);

    expect((float) $item->exact_quantity)->toBe(1.05);
});

it('takes any amount of a product that goes out in whole packages', function () {
    ['group' => $group, 'round' => $round, 'members' => [$anna]] = roundScenario(1, RoundPhase::Shopping);
    $spaghetti = productWithTier($group, packageAmount: 0.25, priceCents: 110, portion: null);

    $item = app(CartService::class)->save($round, $anna, ['product_id' => $spaghetti->id, 'quantity_mode' => QuantityMode::Exact, 'exact_quantity' => 1.3]);

    expect((float) $item->exact_quantity)->toBe(1.3);
});

it('puts the product that was chosen into the cart of a curated round', function () {
    ['group' => $group, 'round' => $round, 'members' => [$anna]] = roundScenario(1, RoundPhase::Shopping);
    $rice = productWithTier($group);
    $oil = productWithTier($group);
    $flour = productWithTier($group);
    $round->availableProducts()->attach([$flour->id, $rice->id]);

    $item = app(CartService::class)->save($round, $anna, ['product_id' => $rice->id, 'quantity_mode' => QuantityMode::Exact, 'exact_quantity' => 1]);

    expect($item->product_id)->toBe($rice->id)
        ->and($round->availableProductsForCart()->pluck('id')->sort()->values()->all())->toBe([$rice->id, $flour->id])
        ->and($oil->id)->not->toBeIn($round->availableProductsForCart()->pluck('id')->all());
});
