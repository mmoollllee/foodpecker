<?php

use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Models\RoundSupplier;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Rounds\SupplierOrders;
use Illuminate\Validation\ValidationException;

/**
 * Rule: per supplier of the final order the lead ticks off when the order
 * went out and when the goods arrived.
 */
beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna]] = roundScenario(1, RoundPhase::Negotiating);

    $this->rice = productWithTier($this->group, packageAmount: 10, priceCents: 3000);
    $this->mustard = productWithTier($this->group, packageAmount: 1, priceCents: 500, portion: null);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->anna->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->exact(1)->create(['user_id' => $this->lead->id, 'product_id' => $this->mustard->id]);

    $proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'Final']);
    $this->round->update(['chosen_proposal_id' => $proposal->id, 'phase' => RoundPhase::Ordering]);

    $this->orders = app(SupplierOrders::class);
});

it('ticks off per supplier of the final order when it went out and when it arrived', function () {
    $this->orders->markOrdered($this->round, $this->rice->supplier, $this->lead);

    expect($this->orders->notOrdered($this->round)->pluck('id')->all())->toBe([$this->mustard->supplier_id]);

    $this->orders->markDelivered($this->round, $this->rice->supplier, $this->lead);

    expect($this->orders->notDelivered($this->round)->pluck('id')->all())->toBe([$this->mustard->supplier_id])
        ->and($this->round->activities()->pluck('action')->all())->toContain('supplier_ordered', 'supplier_delivered');
});

it('counts goods that arrived as ordered', function () {
    $record = $this->orders->markDelivered($this->round, $this->rice->supplier, $this->lead);

    expect($record->ordered_at)->not->toBeNull()
        ->and($record->delivered_at)->not->toBeNull();
});

it('takes the delivery back together with the order', function () {
    $this->orders->markDelivered($this->round, $this->rice->supplier, $this->lead);
    $this->orders->markOrdered($this->round, $this->rice->supplier, $this->lead, false);

    $record = RoundSupplier::query()->where('supplier_id', $this->rice->supplier_id)->firstOrFail();

    expect($record->ordered_at)->toBeNull()
        ->and($record->delivered_at)->toBeNull();
});

it('refuses suppliers that deliver nothing of the final order', function () {
    $other = productWithTier($this->group);

    expect(fn () => $this->orders->markOrdered($this->round, $other->supplier, $this->lead))
        ->toThrow(ValidationException::class, 'liefert nichts aus der finalen Bestellung');
});

it('ticks off orders until the delivery and deliveries until the pickup', function () {
    $this->round->update(['phase' => RoundPhase::Pickup]);

    expect(fn () => $this->orders->markOrdered($this->round, $this->rice->supplier, $this->lead))
        ->toThrow(ValidationException::class, 'Bestell- oder Lieferphase');

    $this->orders->markDelivered($this->round, $this->rice->supplier, $this->lead);

    $this->round->update(['phase' => RoundPhase::Payment]);

    expect(fn () => $this->orders->markDelivered($this->round, $this->mustard->supplier, $this->lead))
        ->toThrow(ValidationException::class, 'zwischen Bestellung und Abholung');
});
