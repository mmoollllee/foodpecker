<?php

use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Models\PriceTier;
use App\Models\RoundPackagePrice;
use App\Models\RoundSupplier;
use App\Services\Proposals\ProposalBuilder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);
    $this->builder = app(ProposalBuilder::class);
});

it('picks the lowest price per unit that fits the wishes', function () {
    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800);
    PriceTier::factory()->for($rice)->package(25, 5500, '25 kg Sack')->create(['sort_order' => 2]);
    PriceTier::factory()->for($rice)->package(50, 9500, '50 kg Sack')->create(['sort_order' => 3]);

    CartItem::factory()->for($this->round)->flexible(10, 20)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);
    CartItem::factory()->for($this->round)->flexible(10, 20)->create(['user_id' => $this->ben->id, 'product_id' => $rice->id]);

    $item = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P'])->items()->firstOrFail();

    expect($item->describePackages())->toBe('1 × 25 kg Sack')
        ->and($item->total_price_cents)->toBe(5500)
        ->and($item->allocatedQuantity())->toBe(25.0);
});

it('combines package sizes of a product in one position', function () {
    $flour = productWithTier($this->group, packageAmount: 10, priceCents: 2100);
    PriceTier::factory()->for($flour)->package(5, 1100, '5 kg')->create();
    PriceTier::factory()->for($flour)->package(1, 240, '1 kg')->create();

    CartItem::factory()->for($this->round)->exact(12)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->ben->id, 'product_id' => $flour->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);

    expect($proposal->items)->toHaveCount(1)
        ->and($proposal->items->first()->describePackages())->toBe('1 × 10 kg, 1 × 5 kg, 2 × 1 kg')
        ->and($proposal->items->first()->total_price_cents)->toBe(3680);
});

it('gives everybody their own packages when a product is not shared out in portions', function () {
    $spaghetti = productWithTier($this->group, packageAmount: 0.25, priceCents: 110, portion: null);
    $small = $spaghetti->priceTiers->first();
    PriceTier::factory()->for($spaghetti)->package(2, 740, '2 kg Beutel')->create(['sort_order' => 2]);

    CartItem::factory()->for($this->round)->exact(2.5)->create(['user_id' => $this->anna->id, 'product_id' => $spaghetti->id]);
    CartItem::factory()->for($this->round)->exact(1)->create([
        'user_id' => $this->ben->id, 'product_id' => $spaghetti->id, 'preferred_price_tier_id' => $small->id,
    ]);

    $item = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P'])->items()->with(['packages', 'allocations'])->firstOrFail();
    $anna = $item->allocations->firstWhere('user_id', $this->anna->id);
    $ben = $item->allocations->firstWhere('user_id', $this->ben->id);

    expect($item->describePackages())->toBe('1 × 2 kg Beutel, 6 × 0.25 kg')
        ->and($anna->describePackages($item))->toBe('1 × 2 kg Beutel, 2 × 0.25 kg')
        ->and($anna->share_cents)->toBe(960)
        ->and($ben->describePackages($item))->toBe('4 × 0.25 kg')
        ->and($ben->share_cents)->toBe(440);
});

it('calculates with the prices and shipping the supplier confirmed for the round', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4800);
    CartItem::factory()->for($this->round)->exact(25)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);

    RoundPackagePrice::create(['round_id' => $this->round->id, 'price_tier_id' => $flour->priceTiers->first()->id, 'price_cents' => 4500]);
    RoundSupplier::create(['round_id' => $this->round->id, 'supplier_id' => $flour->supplier_id, 'shipping_cents' => 1200]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $package = $proposal->items()->firstOrFail()->packages()->firstOrFail();

    expect($package->price_cents)->toBe(4500)
        ->and($package->list_price_cents)->toBe(4800)
        ->and($proposal->shippingCentsFor($flour->supplier_id))->toBe(1200);
});

it('keeps amounts set by hand when the draft is recalculated', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4800);
    CartItem::factory()->for($this->round)->flexible(5, 15)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);
    CartItem::factory()->for($this->round)->flexible(5, 15)->create(['user_id' => $this->ben->id, 'product_id' => $flour->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $item = $proposal->items()->firstOrFail();

    $this->builder->setAllocation($item, $this->ben->id, 10);
    $this->builder->recalculate($proposal);

    $allocations = $item->allocations()->get()->keyBy('user_id');

    expect((float) $allocations[$this->ben->id]->quantity)->toBe(10.0)
        ->and($allocations[$this->ben->id]->is_manual)->toBeTrue()
        ->and((float) $allocations[$this->anna->id]->quantity)->toBe(15.0);
});

it('turns down amounts set by hand that are not whole portions', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4950, portion: 0.5);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);
    $item = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P'])->items()->firstOrFail();

    expect(fn () => $this->builder->setAllocation($item, $this->anna->id, 10.3))
        ->toThrow(ValidationException::class, 'Die Menge muss ein Vielfaches der Portion (0,5 kg) sein.');
});

it('keeps people who get nothing as stakeholders of the position', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4800);
    CartItem::factory()->for($this->round)->exact(20)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);
    CartItem::factory()->for($this->round)->flexible(0, 10)->create(['user_id' => $this->ben->id, 'product_id' => $flour->id]);

    $item = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P'])->items()->firstOrFail();
    $item = $this->builder->setAllocation($item, $this->ben->id, 0);

    expect($item->stakeholderIds()->sort()->values()->all())->toBe(collect([$this->anna->id, $this->ben->id])->sort()->values()->all())
        ->and($item->receiverIds()->all())->toBe([$this->anna->id]);
});

it('orders the package counts fixed by hand', function () {
    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800, portion: 1);
    CartItem::factory()->for($this->round)->flexible(10, 30)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);

    $item = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P'])->items()->firstOrFail();
    $item = $this->builder->setPackageCounts($item, [$rice->priceTiers->first()->id => 3]);

    expect($item->describePackages())->toBe('3 × 10 kg')
        ->and($item->allocatedQuantity())->toBe(30.0)
        ->and($item->packages_fixed)->toBeTrue();
});

it('rounds the flexible shares of a position', function () {
    $spirelli = productWithTier($this->group, packageAmount: 5, priceCents: 1850, portion: 0.1);
    CartItem::factory()->for($this->round)->flexible(1, 4)->create(['user_id' => $this->anna->id, 'product_id' => $spirelli->id]);
    CartItem::factory()->for($this->round)->flexible(1, 2)->create(['user_id' => $this->ben->id, 'product_id' => $spirelli->id]);

    $item = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P'])->items()->firstOrFail();
    $item = $this->builder->setRounding($item, 0.5);

    expect((float) $item->allocations->firstWhere('user_id', $this->anna->id)->quantity)->toBe(3.5)
        ->and((float) $item->allocations->firstWhere('user_id', $this->ben->id)->quantity)->toBe(1.5);
});

it('does not change proposals that are up for a vote', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4800);
    CartItem::factory()->for($this->round)->exact(25)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $proposal->update(['status' => 'published']);

    expect(fn () => $this->builder->setAllocation($proposal->items()->firstOrFail(), $this->anna->id, 20))
        ->toThrow(ValidationException::class, 'Nur Entwürfe lassen sich anpassen — für Änderungen gibt es eine neue Version.');
});

it('copies the corrections into a new version', function () {
    $flour = productWithTier($this->group, packageAmount: 25, priceCents: 4800);
    CartItem::factory()->for($this->round)->flexible(5, 15)->create(['user_id' => $this->anna->id, 'product_id' => $flour->id]);
    CartItem::factory()->for($this->round)->flexible(5, 15)->create(['user_id' => $this->ben->id, 'product_id' => $flour->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'Vorschlag']);
    $this->builder->setAllocation($proposal->items()->firstOrFail(), $this->ben->id, 10);

    $copy = $this->builder->createNewVersion($proposal->fresh(), $this->anna);
    $allocation = $copy->items()->firstOrFail()->allocations()->where('user_id', $this->ben->id)->firstOrFail();

    expect($copy->title)->toBe('Vorschlag (Version 2)')
        ->and($copy->based_on_proposal_id)->toBe($proposal->id)
        ->and($copy->isDraft())->toBeTrue()
        ->and((float) $allocation->quantity)->toBe(10.0)
        ->and($allocation->is_manual)->toBeTrue();
});

it('keeps proposals intact when a package size is deleted from the product', function () {
    $oats = productWithTier($this->group, packageAmount: 15, priceCents: 2200);
    CartItem::factory()->for($this->round)->exact(15)->create(['user_id' => $this->anna->id, 'product_id' => $oats->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $oats->priceTiers()->delete();

    $item = $proposal->items()->firstOrFail();

    expect($item->packages->first()->price_tier_id)->toBeNull()
        ->and($item->describePackages())->toBe('1 × 15 kg')
        ->and($item->totalQuantity())->toBe(15.0);
});

it('drops products nobody orders anymore and adds them back', function () {
    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->ben->id, 'product_id' => $rice->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);

    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => true]);
    $this->builder->recalculate($proposal);

    expect($proposal->items()->count())->toBe(0);

    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => false]);
    $this->builder->recalculate($proposal);

    expect($proposal->items()->firstOrFail()->receiverIds()->all())->toBe([$this->ben->id]);
});

it('ignores the carts of excluded participants', function () {
    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->ben->id, 'product_id' => $rice->id]);
    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => true]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);

    expect($proposal->stakeholderIds()->all())->toBe([$this->anna->id]);
});

it('orders nothing of a product when nobody needs it and no package fits', function () {
    $oil = productWithTier($this->group, packageAmount: 10, priceCents: 9000, portion: 1);
    CartItem::factory()->for($this->round)->flexible(0, 2)->create(['user_id' => $this->anna->id, 'product_id' => $oil->id]);
    CartItem::factory()->for($this->round)->flexible(0, 3)->create(['user_id' => $this->ben->id, 'product_id' => $oil->id]);

    $proposal = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P']);

    expect($proposal->items()->count())->toBe(0);
});

it('refuses to fix a package size the supplier can\'t deliver', function () {
    $rice = productWithTier($this->group, packageAmount: 10, priceCents: 2800);
    $sack = PriceTier::factory()->for($rice)->package(25, 5500, '25 kg Sack')->create();
    CartItem::factory()->for($this->round)->exact(20)->create(['user_id' => $this->anna->id, 'product_id' => $rice->id]);
    RoundPackagePrice::create(['round_id' => $this->round->id, 'price_tier_id' => $sack->id, 'is_available' => false]);
    $item = $this->builder->createFromCarts($this->round, $this->lead, ['title' => 'P'])->items()->firstOrFail();

    expect(fn () => $this->builder->setPackageCounts($item, [$sack->id => 1]))
        ->toThrow(ValidationException::class, '„25 kg Sack“ ist laut Lieferant nicht lieferbar.');
});
