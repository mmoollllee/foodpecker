<?php

use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Models\PriceTier;
use App\Services\Distribution\PackageSpec;
use App\Services\Proposals\ProposalBuilder;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);
    $this->builder = app(ProposalBuilder::class);
});

it('picks the cheapest package size that fits the wishes', function () {
    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800);
    PriceTier::factory()->for($rice)->package(25, 5500, '25 kg Sack')->create(['sort_order' => 2]);
    PriceTier::factory()->for($rice)->package(50, 9500, '50 kg Sack')->create(['sort_order' => 3]);

    // 20–40 kg wanted: 50 kg would be too much, 25 kg is cheaper per kg than 3 × 10 kg.
    CartItem::factory()->for($this->round)->flexible(10, 20)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);
    CartItem::factory()->for($this->round)->flexible(10, 20)->create(['user_id' => $this->ben->id, 'product_id' => $rice->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P', 'shipping_cents' => 1500]);
    $item = $proposal->items()->firstOrFail();

    expect($item->tier_label)->toBe('25 kg Sack')
        ->and($item->packages_ordered)->toBe(1)
        ->and($item->package_price_cents)->toBe(5500)
        ->and($item->total_price_cents)->toBe(5500)
        ->and($proposal->shipping_cents)->toBe(1500)
        ->and((float) $item->allocations()->sum('quantity'))->toBe(25.0);
});

it('splits products with several indivisible pack sizes by preferred size', function () {
    $spaghetti = productWithTier($this->group, packageAmount: 0.25, priceCents: 110, divisible: false);
    $small = $spaghetti->priceTiers->first();
    $large = PriceTier::factory()->for($spaghetti)->indivisible()->package(2, 740, '2 kg Beutel')->create(['sort_order' => 2]);

    CartItem::factory()->for($this->round)->exact(1)->create([
        'user_id' => $this->anna->id, 'product_id' => $spaghetti->id, 'preferred_price_tier_id' => $small->id,
    ]);
    CartItem::factory()->for($this->round)->exact(4)->create([
        'user_id' => $this->ben->id, 'product_id' => $spaghetti->id, 'preferred_price_tier_id' => $large->id,
    ]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $items = $proposal->items()->with('allocations')->get()->keyBy('price_tier_id');

    expect($items)->toHaveCount(2)
        ->and($items[$small->id]->packages_ordered)->toBe(4)
        ->and($items[$small->id]->stakeholderIds()->all())->toBe([$this->anna->id])
        ->and($items[$large->id]->packages_ordered)->toBe(2)
        ->and($items[$large->id]->stakeholderIds()->all())->toBe([$this->ben->id]);
});

it('recalculates an item with a negotiated price and a different package count', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4800);
    CartItem::factory()->for($this->round)->flexible(10, 30)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);
    CartItem::factory()->for($this->round)->flexible(10, 30)->create(['user_id' => $this->ben->id, 'product_id' => $flour->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $item = $proposal->items()->firstOrFail();

    expect($item->packages_ordered)->toBe(1);

    $this->builder->updateItem($item, $item->toPackageSpec()->withPrice(4500), packages: 2);
    $item->refresh();

    expect($item->packages_ordered)->toBe(2)
        ->and($item->package_price_cents)->toBe(4500)
        ->and($item->total_price_cents)->toBe(9000)
        ->and((float) $item->allocations()->sum('quantity'))->toBe(50.0)
        ->and((int) $item->allocations()->sum('share_cents'))->toBe(9000)
        ->and($flour->priceTiers()->first()->price_cents)->toBe(4800);
});

it('keeps proposals intact when a price tier is deleted from the product', function () {
    $oats = productWithTier($this->group, packageAmount: 15, priceCents: 2200);
    CartItem::factory()->for($this->round)->exact(15)->create(['user_id' => $this->anna->id, 'product_id' => $oats->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $oats->priceTiers()->delete();

    $item = $proposal->items()->firstOrFail();

    expect($item->price_tier_id)->toBeNull()
        ->and($item->packageLabel())->toBe('15 kg')
        ->and($item->toPackageSpec())->toBeInstanceOf(PackageSpec::class)
        ->and($item->totalQuantity())->toBe(15.0);
});

it('recalculates a draft from the current carts and keeps the negotiated price', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4800);
    CartItem::factory()->for($this->round)->flexible(5, 15)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->ben->id, 'product_id' => $flour->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $item = $proposal->items()->firstOrFail();
    $this->builder->updateItem($item, $item->toPackageSpec()->withPrice(4500));

    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => true]);
    $this->builder->recalculate($proposal);

    $item->refresh();

    expect($item->package_price_cents)->toBe(4500)
        ->and($item->packages_ordered)->toBe(1)
        ->and($item->stakeholderIds()->all())->toBe([$this->anna->id])
        ->and((float) $item->allocations()->sum('quantity'))->toBe(15.0);
});

it('drops products nobody orders anymore and adds them back with the right pack size', function () {
    $spaghetti = productWithTier($this->group, packageAmount: 0.25, priceCents: 110, divisible: false);
    $small = $spaghetti->priceTiers->first();
    $large = PriceTier::factory()->for($spaghetti)->indivisible()->package(2, 740, '2 kg Beutel')->create(['sort_order' => 2]);

    CartItem::factory()->for($this->round)->exact(1)->create([
        'user_id' => $this->anna->id, 'product_id' => $spaghetti->id, 'preferred_price_tier_id' => $small->id,
    ]);
    CartItem::factory()->for($this->round)->exact(4)->create([
        'user_id' => $this->ben->id, 'product_id' => $spaghetti->id, 'preferred_price_tier_id' => $large->id,
    ]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);

    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => true]);
    $this->builder->recalculate($proposal);

    expect($proposal->items()->pluck('price_tier_id')->all())->toBe([$small->id]);

    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => false]);
    $this->builder->recalculate($proposal);

    $items = $proposal->items()->with('allocations')->get()->keyBy('price_tier_id');

    expect($items)->toHaveCount(2)
        ->and($items[$small->id]->stakeholderIds()->all())->toBe([$this->anna->id])
        ->and($items[$large->id]->stakeholderIds()->all())->toBe([$this->ben->id])
        ->and($items[$large->id]->packages_ordered)->toBe(2);
});

it('ignores the carts of excluded participants', function () {
    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->ben->id, 'product_id' => $rice->id]);
    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => true]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);

    expect($proposal->includedUserIds()->all())->toBe([$this->anna->id]);
});
