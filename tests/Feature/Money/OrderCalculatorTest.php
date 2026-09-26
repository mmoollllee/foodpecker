<?php

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\OrderProposal;
use App\Models\Product;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Services\Money\OrderCalculator;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Finalizing);
    $this->round->update(['lead_fee_percent' => 5.0, 'platform_fee_percent' => 1.0]);
});

/**
 * A position of the proposal with the given shares: user id => [quantity, cents].
 *
 * @param  array<int, array{0: float, 1: int}>  $shares
 */
function positionOf(OrderProposal $proposal, Supplier $supplier, array $shares): ProposalItem
{
    $product = Product::factory()->create(['supplier_id' => $supplier->id]);
    $item = ProposalItem::create([
        'proposal_id' => $proposal->id,
        'product_id' => $product->id,
        'total_price_cents' => array_sum(array_column($shares, 1)),
    ]);

    foreach ($shares as $userId => [$quantity, $cents]) {
        ProposalAllocation::create(['proposal_item_id' => $item->id, 'user_id' => $userId, 'quantity' => $quantity, 'share_cents' => $cents]);
    }

    return $item;
}

it('adds up goods, shipping, lead fee and platform fee', function () {
    $supplier = Supplier::factory()->create();
    $proposal = OrderProposal::create([
        'round_id' => $this->round->id,
        'proposed_by_user_id' => $this->lead->id,
        'title' => 'Vorschlag',
        'status' => ProposalStatus::Published,
        'shipping_by_supplier' => [$supplier->id => 1000],
    ]);
    positionOf($proposal, $supplier, [$this->anna->id => [5, 5000], $this->ben->id => [5, 5000]]);

    $totals = app(OrderCalculator::class)->calculate($proposal);

    expect($totals->goodsCents)->toBe(10000)
        ->and($totals->shippingCents)->toBe(1000)
        ->and($totals->leadFeeCents)->toBe(500)
        ->and($totals->platformFeeCents)->toBe(100)
        ->and($totals->grandTotalCents)->toBe(11600)
        ->and(collect($totals->perParticipant)->sum->shippingShareCents)->toBe(1000)
        ->and(collect($totals->perParticipant)->sum->leadFeeCents)->toBe(500)
        ->and(collect($totals->perParticipant)->sum->platformFeeCents)->toBe(100);
});

it('splits the shipping of a supplier in proportion to what everybody gets from it', function () {
    $mill = Supplier::factory()->create();
    $mustardMaker = Supplier::factory()->create();
    $proposal = OrderProposal::create([
        'round_id' => $this->round->id,
        'proposed_by_user_id' => $this->lead->id,
        'title' => 'Vorschlag',
        'status' => ProposalStatus::Published,
        'shipping_by_supplier' => [$mill->id => 800, $mustardMaker->id => 500],
    ]);
    positionOf($proposal, $mill, [$this->anna->id => [15, 3000], $this->ben->id => [5, 1000]]);
    positionOf($proposal, $mustardMaker, [$this->ben->id => [12, 2000]]);

    $shipping = collect(app(OrderCalculator::class)->calculate($proposal)->perParticipant)->pluck('shippingShareCents', 'userId');

    expect($shipping[$this->anna->id])->toBe(600)
        ->and($shipping[$this->ben->id])->toBe(700);
});

it('leaves out people who get nothing', function () {
    $supplier = Supplier::factory()->create();
    $proposal = OrderProposal::create([
        'round_id' => $this->round->id,
        'proposed_by_user_id' => $this->lead->id,
        'title' => 'Vorschlag',
        'status' => ProposalStatus::Published,
    ]);
    positionOf($proposal, $supplier, [$this->anna->id => [10, 5000], $this->ben->id => [0, 0]]);

    expect(collect(app(OrderCalculator::class)->calculate($proposal)->perParticipant)->pluck('userId')->all())
        ->toBe([$this->anna->id]);
});
