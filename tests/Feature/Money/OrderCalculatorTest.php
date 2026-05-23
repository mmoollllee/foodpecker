<?php

use App\Enums\ProposalStatus;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\OrderProposal;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Models\User;
use App\Services\Money\OrderCalculator;

it('summiert Waren, Versand, Honorar und Vereinsbeitrag korrekt', function () {
    $owner = User::factory()->create();
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    $group = Group::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'owner_id' => $owner->id]);
    $manufacturer = Manufacturer::create([
        'visibility' => 'public', 'name' => 'M', 'slug' => 'm-'.uniqid(), 'created_by_user_id' => $owner->id,
    ]);
    $product = Product::create([
        'manufacturer_id' => $manufacturer->id,
        'visibility' => 'public',
        'name' => 'P', 'slug' => 'p-'.uniqid(),
        'unit' => 'kg', 'packaging_strategy' => 'tiered',
        'created_by_user_id' => $owner->id,
    ]);
    $tier = PriceTier::create([
        'product_id' => $product->id, 'label' => '10 kg',
        'package_amount' => 10, 'price_cents' => 5000, 'is_divisible' => true,
    ]);

    $round = Round::create([
        'group_id' => $group->id, 'lead_user_id' => $owner->id, 'title' => 'R',
        'phase' => 'finalizing',
        'lead_fee_percent' => 5.0,
        'platform_fee_percent' => 1.0,
    ]);
    RoundParticipant::create(['round_id' => $round->id, 'user_id' => $u1->id]);
    RoundParticipant::create(['round_id' => $round->id, 'user_id' => $u2->id]);

    $proposal = OrderProposal::create([
        'round_id' => $round->id,
        'proposed_by_user_id' => $owner->id,
        'title' => 'Vorschlag',
        'status' => ProposalStatus::Published->value,
        'shipping_cents' => 1000,
    ]);
    $item = ProposalItem::create([
        'proposal_id' => $proposal->id, 'product_id' => $product->id,
        'price_tier_id' => $tier->id, 'packages_ordered' => 2, 'total_price_cents' => 10000,
    ]);
    ProposalAllocation::create(['proposal_item_id' => $item->id, 'user_id' => $u1->id, 'quantity' => 5, 'share_cents' => 5000]);
    ProposalAllocation::create(['proposal_item_id' => $item->id, 'user_id' => $u2->id, 'quantity' => 5, 'share_cents' => 5000]);

    $totals = (new OrderCalculator)->calculate($proposal);

    expect($totals->goodsCents)->toBe(10000)
        ->and($totals->shippingCents)->toBe(1000)
        ->and($totals->leadFeeCents)->toBe(500)       // 5 % von 10000
        ->and($totals->platformFeeCents)->toBe(100)   // 1 % von 10000
        ->and($totals->grandTotalCents)->toBe(11600);

    expect($totals->perParticipant)->toHaveCount(2);

    $sumLead = collect($totals->perParticipant)->sum->leadFeeCents;
    $sumPlatform = collect($totals->perParticipant)->sum->platformFeeCents;
    $sumShipping = collect($totals->perParticipant)->sum->shippingShareCents;

    expect($sumLead)->toBe(500);
    expect($sumPlatform)->toBe(100);
    expect($sumShipping)->toBe(1000);
});
