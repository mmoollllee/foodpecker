<?php

use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Models\PriceTier;
use App\Models\RoundPackagePrice;
use App\Services\Rounds\SupplierFeedback;

/**
 * Rule: a supplier's answer is recorded per round; packages the answer
 * doesn't mention stay as they were saved.
 */
it('leaves packages alone that an answer does not mention', function () {
    ['group' => $group, 'round' => $round, 'lead' => $lead, 'members' => [$anna]] = roundScenario(1, RoundPhase::Negotiating);
    $rice = productWithTier($group, packageAmount: 10, priceCents: 2800);
    $sack = PriceTier::factory()->for($rice)->package(25, 5500, '25 kg Sack')->create();
    CartItem::factory()->for($round)->exact(35)->create(['user_id' => $anna->id, 'product_id' => $rice->id]);
    $small = $rice->priceTiers->first();
    $feedback = app(SupplierFeedback::class);

    $feedback->record($round, $rice->supplier, ['prices' => [$small->id => 2600, $sack->id => 5000]], $lead);
    $feedback->record($round, $rice->supplier, ['shipping_cents' => 900, 'prices' => [$small->id => 2500]], $lead);

    expect(RoundPackagePrice::query()->where('price_tier_id', $sack->id)->value('price_cents'))->toBe(5000)
        ->and(RoundPackagePrice::query()->where('price_tier_id', $small->id)->value('price_cents'))->toBe(2500)
        ->and(RoundPackagePrice::query()->where('price_tier_id', $small->id)->value('list_price_cents'))->toBe(2800);
});
