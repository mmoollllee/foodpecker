<?php

use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\PriceTier;
use App\Models\Round;
use App\Models\RoundPackagePrice;
use App\Services\Rounds\CatalogPrices;
use Illuminate\Validation\ValidationException;

/**
 * Rule: once the final order stands, the lead takes the prices the
 * suppliers confirmed over into the catalog — only for products the lead
 * may change.
 */
beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead] = roundScenario(1, RoundPhase::Ordering);
    actingInGroup($this->lead, $this->group);

    $this->prices = app(CatalogPrices::class);
});

function confirmPrice(Round $round, PriceTier $tier, int $cents, bool $available = true): void
{
    RoundPackagePrice::create(['round_id' => $round->id, 'price_tier_id' => $tier->id, 'price_cents' => $cents, 'list_price_cents' => $tier->price_cents, 'is_available' => $available]);
}

it('takes the confirmed prices over into the catalog', function () {
    $tier = productWithTier($this->group, priceCents: 3000)->priceTiers->first();
    confirmPrice($this->round, $tier, 2600);

    expect($this->prices->adopt($this->round, $this->lead))->toBe(1)
        ->and($tier->fresh()->price_cents)->toBe(2600)
        ->and($this->round->activities()->where('action', 'catalog_prices_adopted')->sole()->properties)->toBe(['count' => 1]);
});

it('leaves prices alone that did not change, could not be delivered or belong to another group', function () {
    $unchanged = productWithTier($this->group, priceCents: 3000)->priceTiers->first();
    $undeliverable = productWithTier($this->group, priceCents: 3000)->priceTiers->first();
    $foreign = productWithTier(Group::factory()->create(), priceCents: 3000)->priceTiers->first();
    confirmPrice($this->round, $unchanged, 3000);
    confirmPrice($this->round, $undeliverable, 2000, available: false);
    confirmPrice($this->round, $foreign, 2000);

    expect($this->prices->pendingChanges($this->round, $this->lead))->toBeEmpty();
});

it('never overwrites a catalog price that changed after the round', function () {
    $tier = productWithTier($this->group, priceCents: 3000)->priceTiers->first();
    confirmPrice($this->round, $tier, 2600);
    $this->prices->adopt($this->round, $this->lead);
    $tier->update(['price_cents' => 2800]);

    expect($this->prices->pendingChanges($this->round, $this->lead))->toBeEmpty()
        ->and($this->prices->adopt($this->round, $this->lead))->toBe(0)
        ->and($tier->fresh()->price_cents)->toBe(2800);
});

it('waits with the catalog until the final order stands', function () {
    $this->round->update(['phase' => RoundPhase::Finalizing]);
    $tier = productWithTier($this->group, priceCents: 3000)->priceTiers->first();
    confirmPrice($this->round, $tier, 2600);

    expect(fn () => $this->prices->adopt($this->round, $this->lead))
        ->toThrow(ValidationException::class, 'sobald die finale Bestellung steht');

    expect($tier->fresh()->price_cents)->toBe(3000);
});
