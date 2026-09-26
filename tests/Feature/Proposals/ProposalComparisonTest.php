<?php

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\OrderProposal;
use App\Models\Product;
use App\Models\User;
use App\Services\Proposals\ItemChange;
use App\Services\Proposals\ProposalComparison;

/**
 * Rule: between two versions of a proposal everybody sees per product what
 * changed — packages, prices, who gets how much — plus shipping and what
 * everybody pays.
 */
beforeEach(function () {
    ['round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Finalizing);
    $this->round->update(['lead_fee_percent' => 0, 'platform_fee_percent' => 0]);
    $this->anna->update(['first_name' => 'Anna']);
    $this->ben->update(['first_name' => 'Ben']);

    $this->rice = productWithTier($this->round->group, packageAmount: 10);
    $this->rice->update(['name' => 'Reis']);
    $this->spelt = productWithTier($this->round->group, packageAmount: 10);
    $this->spelt->update(['name' => 'Dinkel']);
    $this->mustard = productWithTier($this->round->group, packageAmount: 1, portion: null);
    $this->mustard->update(['name' => 'Senf']);

    $this->comparison = app(ProposalComparison::class);
});

/**
 * A proposal with one position per entry: packages ordered at a price and
 * what everybody gets for how much.
 *
 * @param  array<int, array{product: Product, count: int, price: int, shares: array<int, array{0: User, 1: float, 2: int}>}>  $positions
 * @param  array<int, int>  $shipping  Shipping by supplier id.
 */
function proposalWith(string $title, array $positions, array $shipping = []): OrderProposal
{
    $round = test()->round;
    $proposal = $round->proposals()->create([
        'proposed_by_user_id' => $round->lead_user_id,
        'title' => $title,
        'status' => ProposalStatus::Published,
        'shipping_by_supplier' => $shipping,
    ]);

    foreach ($positions as $position) {
        $tier = $position['product']->priceTiers->first();
        $item = $proposal->items()->create([
            'product_id' => $position['product']->id,
            'total_price_cents' => $position['count'] * $position['price'],
        ]);
        $item->packages()->create([
            'price_tier_id' => $tier->id,
            'label' => $tier->label,
            'package_amount' => $tier->package_amount,
            'price_cents' => $position['price'],
            'list_price_cents' => $tier->price_cents,
            'count' => $position['count'],
        ]);

        foreach ($position['shares'] as [$user, $quantity, $cents]) {
            $item->allocations()->create(['user_id' => $user->id, 'quantity' => $quantity, 'share_cents' => $cents]);
        }
    }

    return $proposal;
}

it('lists per product what was added, dropped or changed', function () {
    $before = proposalWith('A', [
        ['product' => $this->rice, 'count' => 1, 'price' => 3000, 'shares' => [[$this->anna, 5, 1500], [$this->ben, 5, 1500]]],
        ['product' => $this->spelt, 'count' => 1, 'price' => 2000, 'shares' => [[$this->ben, 10, 2000]]],
    ]);
    $after = proposalWith('B', [
        ['product' => $this->rice, 'count' => 1, 'price' => 2500, 'shares' => [[$this->anna, 4, 1000], [$this->ben, 6, 1500]]],
        ['product' => $this->mustard, 'count' => 2, 'price' => 500, 'shares' => [[$this->ben, 2, 1000]]],
    ]);

    $changes = $this->comparison->compare($before, $after);

    expect($changes->items)->toEqual([
        new ItemChange(ItemChange::REMOVED, 'Dinkel', 'kg', '1 × 10 kg', null, [], [
            ['user_id' => $this->ben->id, 'name' => 'Ben', 'before' => 10.0, 'after' => 0.0],
        ]),
        new ItemChange(ItemChange::CHANGED, 'Reis', 'kg', '1 × 10 kg', '1 × 10 kg', [
            ['label' => '10 kg', 'before' => 3000, 'after' => 2500],
        ], [
            ['user_id' => $this->anna->id, 'name' => 'Anna', 'before' => 5.0, 'after' => 4.0],
            ['user_id' => $this->ben->id, 'name' => 'Ben', 'before' => 5.0, 'after' => 6.0],
        ]),
        new ItemChange(ItemChange::ADDED, 'Senf', 'kg', null, '2 × 1 kg', [], [
            ['user_id' => $this->ben->id, 'name' => 'Ben', 'before' => 0.0, 'after' => 2.0],
        ]),
    ])
        ->and($changes->subtotals)->toBe([
            ['user_id' => $this->anna->id, 'name' => 'Anna', 'before' => 1500, 'after' => 1000],
            ['user_id' => $this->ben->id, 'name' => 'Ben', 'before' => 3500, 'after' => 2500],
        ])
        ->and([$changes->totalBefore, $changes->totalAfter])->toBe([5000, 3500]);
});

it('notices changed shipping and what it does to everybody\'s share', function () {
    $positions = [['product' => $this->rice, 'count' => 1, 'price' => 3000, 'shares' => [[$this->anna, 5, 1500], [$this->ben, 5, 1500]]]];
    $supplier = $this->rice->supplier;

    $changes = $this->comparison->compare(
        proposalWith('A', $positions),
        proposalWith('B', $positions, [$supplier->id => 1200]),
    );

    expect($changes->items)->toBe([])
        ->and($changes->shipping)->toBe([['supplier' => $supplier->name, 'before' => 0, 'after' => 1200]])
        ->and(collect($changes->subtotals)->pluck('after')->all())->toBe([2100, 2100]);
});

it('finds nothing when a new version is a plain copy', function () {
    $positions = [['product' => $this->rice, 'count' => 1, 'price' => 3000, 'shares' => [[$this->anna, 5, 1500], [$this->ben, 5, 1500]]]];

    expect($this->comparison->compare(proposalWith('A', $positions), proposalWith('B', $positions))->isEmpty())->toBeTrue();
});
