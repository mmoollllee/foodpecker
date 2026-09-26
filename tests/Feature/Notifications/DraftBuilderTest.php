<?php

use App\Enums\NotificationKind;
use App\Enums\RoundPhase;
use App\Models\CartItem;
use App\Services\Notifications\DraftBuilder;
use App\Services\Rounds\SupplierFeedback;

it('sums up what the suppliers changed when the proposal goes up for a vote', function () {
    ['group' => $group, 'round' => $round, 'lead' => $lead, 'members' => [$anna]] = roundScenario(1, RoundPhase::Negotiating);
    $rice = productWithTier($group, packageAmount: 25, priceCents: 5500);
    $rice->update(['name' => 'Bio Reis']);
    $rice->supplier->update(['name' => 'Mühle']);
    CartItem::factory()->for($round)->exact(25)->create(['user_id' => $anna->id, 'product_id' => $rice->id]);

    app(SupplierFeedback::class)->record($round, $rice->supplier, [
        'shipping_cents' => 1200,
        'prices' => [$rice->priceTiers->first()->id => 5280],
    ], $lead);

    $body = app(DraftBuilder::class)->compose($round, NotificationKind::ProposalReady, $lead)['body'];

    expect($body)->toContain('- Bio Reis, 25 kg: 55,00 € → 52,80 € (-4 %)')
        ->and($body)->toContain('- Versand Mühle: 12,00 €');
});

it('tells everybody where to transfer their share when payment is due', function () {
    ['round' => $round, 'lead' => $lead] = roundScenario(1, RoundPhase::Payment);
    $lead->update(['iban' => 'DE89370400440532013000', 'bank_account_holder' => 'Lea Lead']);

    $body = app(DraftBuilder::class)->compose($round, NotificationKind::PaymentDue, $lead)['body'];

    expect($body)->toContain('- Empfänger: Lea Lead')
        ->toContain('- IBAN: DE89 3704 0044 0532 0130 00')
        ->toContain('- Verwendungszweck: '.$round->title.' – euer Name');
});

it('names archived products among what the suppliers changed', function () {
    ['group' => $group, 'round' => $round, 'lead' => $lead, 'members' => [$anna]] = roundScenario(1, RoundPhase::Negotiating);
    $rice = productWithTier($group, packageAmount: 25, priceCents: 5500);
    $rice->update(['name' => 'Bio Reis']);
    CartItem::factory()->for($round)->exact(25)->create(['user_id' => $anna->id, 'product_id' => $rice->id]);
    app(SupplierFeedback::class)->record($round, $rice->supplier, ['prices' => [$rice->priceTiers->first()->id => 5280]], $lead);

    $rice->delete();

    expect(app(DraftBuilder::class)->compose($round, NotificationKind::ProposalReady, $lead)['body'])
        ->toContain('- Bio Reis, 25 kg: 55,00 € → 52,80 € (-4 %)');
});
