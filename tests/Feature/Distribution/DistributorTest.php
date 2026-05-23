<?php

use App\Models\CartItem;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use App\Services\Distribution\Distributor;

beforeEach(function () {
    $this->distributor = new Distributor;

    $owner = User::factory()->create();
    $this->group = Group::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'owner_id' => $owner->id]);
    $manufacturer = Manufacturer::create([
        'group_id' => $this->group->id, 'visibility' => 'private',
        'name' => 'Hersteller', 'slug' => 'h-'.uniqid(), 'created_by_user_id' => $owner->id,
    ]);
    $this->product = Product::create([
        'manufacturer_id' => $manufacturer->id,
        'group_id' => $this->group->id,
        'visibility' => 'private',
        'name' => 'Reis', 'slug' => 'reis-'.uniqid(),
        'unit' => 'kg',
        'packaging_strategy' => 'tiered',
        'created_by_user_id' => $owner->id,
    ]);
    $this->round = Round::create([
        'group_id' => $this->group->id,
        'lead_user_id' => $owner->id,
        'title' => 'Test', 'phase' => 'shopping',
    ]);
});

/** Helper: schnell ein Cart-Item bauen */
function buildCartItem($round, $product, string $mode, ?float $exact = null, ?float $min = null, ?float $max = null): CartItem
{
    return CartItem::create([
        'round_id' => $round->id,
        'user_id' => User::factory()->create()->id,
        'product_id' => $product->id,
        'quantity_mode' => $mode,
        'exact_quantity' => $exact,
        'min_quantity' => $min,
        'max_quantity' => $max,
    ]);
}

it('rundet die Gesamtmenge auf das nächste Gebinde auf — teilbare Tier', function () {
    $tier = PriceTier::create([
        'product_id' => $this->product->id,
        'label' => '25 kg', 'package_amount' => 25.0,
        'price_cents' => 5500, 'is_divisible' => true, 'divisible_step' => 0.5,
    ]);

    $items = collect([
        buildCartItem($this->round, $this->product, 'exact', 5),
        buildCartItem($this->round, $this->product, 'exact', 8),
        buildCartItem($this->round, $this->product, 'flexible', null, 3, 12),
    ]);

    $r = $this->distributor->compute($items, $tier);

    expect($r->packagesOrdered)->toBe(1)
        ->and($r->totalQuantity)->toBe(25.0)
        ->and($r->feasible)->toBeTrue();

    $sum = collect($r->allocations)->sum('allocatedQuantity');
    expect(abs($sum - 25.0))->toBeLessThan(0.001);
});

it('verteilt die Restmenge proportional auf flexible Teilnehmer', function () {
    $tier = PriceTier::create([
        'product_id' => $this->product->id,
        'label' => '10 kg', 'package_amount' => 10.0,
        'price_cents' => 2800, 'is_divisible' => true, 'divisible_step' => 1.0,
    ]);

    $items = collect([
        buildCartItem($this->round, $this->product, 'flexible', null, 2, 6),
        buildCartItem($this->round, $this->product, 'flexible', null, 2, 6),
    ]);

    $r = $this->distributor->compute($items, $tier);

    // Min = 4, gerundet auf 10 → +6 zu verteilen, je 3 pro Person
    expect($r->packagesOrdered)->toBe(1);
    expect(collect($r->allocations)->map(fn ($a) => $a->allocatedQuantity)->all())
        ->each(fn ($q) => $q->toBe(5.0));
});

it('respektiert "exakte Menge" und gibt Rest an Flexible', function () {
    $tier = PriceTier::create([
        'product_id' => $this->product->id,
        'label' => '25 kg', 'package_amount' => 25.0,
        'price_cents' => 5500, 'is_divisible' => true, 'divisible_step' => 0.5,
    ]);

    $exact = buildCartItem($this->round, $this->product, 'exact', 12);
    $flex = buildCartItem($this->round, $this->product, 'flexible', null, 5, 20);

    $r = $this->distributor->compute(collect([$exact, $flex]), $tier);

    $exactAlloc = collect($r->allocations)->firstWhere('userId', $exact->user_id);
    $flexAlloc = collect($r->allocations)->firstWhere('userId', $flex->user_id);

    expect($exactAlloc->allocatedQuantity)->toBe(12.0);
    expect($flexAlloc->allocatedQuantity)->toBe(13.0); // 25 - 12
    expect($r->feasible)->toBeTrue();
});

it('snappt Allokationen auf den divisible_step', function () {
    $tier = PriceTier::create([
        'product_id' => $this->product->id,
        'label' => '5 kg', 'package_amount' => 5.0,
        'price_cents' => 1500, 'is_divisible' => true, 'divisible_step' => 0.5,
    ]);

    // 3 flexible Items, je 1..3 kg, Ziel = 5 kg, Slack je 2 = total 6, share je (2/6)*2 = 0.66
    // snap auf 0.5 → 0.5 pro Person, Rest 0.5 fließt zur ersten mit Slack
    $items = collect([
        buildCartItem($this->round, $this->product, 'flexible', null, 1, 3),
        buildCartItem($this->round, $this->product, 'flexible', null, 1, 3),
        buildCartItem($this->round, $this->product, 'flexible', null, 1, 3),
    ]);

    $r = $this->distributor->compute($items, $tier);

    expect($r->packagesOrdered)->toBe(1);
    foreach ($r->allocations as $alloc) {
        // Jede Allokation muss ein Vielfaches von 0.5 sein
        $remainder = fmod($alloc->allocatedQuantity, 0.5);
        expect(abs($remainder) < 0.001 || abs($remainder - 0.5) < 0.001)->toBeTrue();
    }
    expect(collect($r->allocations)->sum('allocatedQuantity'))->toBe(5.0);
});

it('teilt nicht-teilbare Pakete in ganzen Einheiten zu', function () {
    $tier = PriceTier::create([
        'product_id' => $this->product->id,
        'label' => '2 kg', 'package_amount' => 2.0,
        'price_cents' => 740, 'is_divisible' => false,
    ]);

    $items = collect([
        buildCartItem($this->round, $this->product, 'exact', 4),     // 2 Pakete
        buildCartItem($this->round, $this->product, 'exact', 2),     // 1 Paket
        buildCartItem($this->round, $this->product, 'flexible', null, 0, 6), // 0–3 Pakete
    ]);

    $r = $this->distributor->compute($items, $tier);

    // Mindestens 2+1+0 = 3 Pakete, allokiert in ganzen 2-kg-Einheiten
    expect($r->packagesOrdered)->toBeGreaterThanOrEqual(3);
    foreach ($r->allocations as $alloc) {
        $remainder = fmod($alloc->allocatedQuantity, 2.0);
        expect(abs($remainder) < 0.001 || abs($remainder - 2.0) < 0.001)->toBeTrue();
    }
});

it('verteilt die Preisanteile auf Cent genau', function () {
    $tier = PriceTier::create([
        'product_id' => $this->product->id,
        'label' => '10 kg', 'package_amount' => 10.0,
        'price_cents' => 2800, 'is_divisible' => true, 'divisible_step' => 0.5,
    ]);

    $items = collect([
        buildCartItem($this->round, $this->product, 'exact', 5),
        buildCartItem($this->round, $this->product, 'exact', 5),
    ]);

    $r = $this->distributor->compute($items, $tier);

    expect(collect($r->allocations)->sum('shareCents'))->toBe(2800);
});

it('liefert leere Allokation für leeren Warenkorb', function () {
    $tier = PriceTier::create([
        'product_id' => $this->product->id,
        'label' => '10 kg', 'package_amount' => 10.0,
        'price_cents' => 2800, 'is_divisible' => true,
    ]);

    $r = $this->distributor->compute(collect(), $tier);

    expect($r->packagesOrdered)->toBe(0)
        ->and($r->allocations)->toBe([])
        ->and($r->feasible)->toBeTrue();
});
