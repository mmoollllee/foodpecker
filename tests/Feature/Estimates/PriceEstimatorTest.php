<?php

use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Models\PriceTier;
use App\Models\RoundPackagePrice;
use App\Services\Estimates\PriceEstimator;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben, $this->cleo]] = roundScenario(3, RoundPhase::Shopping);
    $this->round->update(['lead_fee_percent' => 2.5, 'platform_fee_percent' => 1.0]);

    $this->rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800, portion: 1);
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->anna->id, 'product_id' => $this->rice->id]);

    $this->estimator = app(PriceEstimator::class);
});

it('estimates what everybody pays for the packages the group would order now, fees included', function () {
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->ben->id, 'product_id' => $this->rice->id]);

    $estimate = $this->estimator->estimate($this->round);

    expect($estimate->forProduct($this->rice->id)->shareCentsFor($this->anna->id))->toBe(1400)
        ->and($estimate->totalCentsFor($this->anna->id))->toBe(1449)
        ->and($estimate->forProduct($this->rice->id)->pricePerUnitCents())->toBe(280.0);
});

it('shows how much of the ordered packages is still free', function () {
    expect($this->estimator->estimate($this->round)->forProduct($this->rice->id)->freeQuantity())->toBe(5.0);
});

it('prices a wish with the wishes of everybody else in the round', function () {
    expect($this->estimator->estimateWish($this->round, $this->rice, $this->cleo->id, 5, 5))
        ->toBe(['from' => 1449, 'to' => 1449, 'perUnit' => 280.0]);
});

it('prices a flexible wish as a range', function () {
    expect($this->estimator->estimateWish($this->round, $this->rice, $this->cleo->id, 2, 5))
        ->toBe(['from' => 828, 'to' => 1449, 'perUnit' => 280.0]);
});

it('uses the prices the suppliers confirmed unless the list prices are asked for', function () {
    RoundPackagePrice::create(['round_id' => $this->round->id, 'price_tier_id' => $this->rice->priceTiers->first()->id, 'price_cents' => 2500]);

    expect($this->estimator->estimate($this->round)->forProduct($this->rice->id)->pricePerUnitCents())->toBe(250.0)
        ->and($this->estimator->estimate($this->round, listPrices: true)->forProduct($this->rice->id)->pricePerUnitCents())->toBe(280.0);
});

it('prices a wish with the package size chosen for it', function () {
    $pasta = productWithTier($this->group, packageAmount: 0.5, priceCents: 200, portion: null);
    $bag = $pasta->priceTiers->first();
    PriceTier::factory()->for($pasta)->package(5, 1200, '5 kg Beutel')->create();

    expect($this->estimator->estimateWish($this->round, $pasta->load('priceTiers'), $this->cleo->id, 5, 5, $bag->id)['to'])->toBe(2070)
        ->and($this->estimator->estimateWish($this->round, $pasta, $this->cleo->id, 5, 5)['to'])->toBe(1242);
});
