<?php

use App\Enums\RoundPhase;
use App\Enums\SupplierMailType;
use App\Enums\VoteValue;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Models\CartItem;
use App\Services\Notifications\SupplierMailComposer;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\SupplierFeedback;
use Livewire\Livewire;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);
    $this->lead->update(['first_name' => 'Marie', 'last_name' => 'Lead', 'phone' => '030 123456']);

    $this->rice = productWithTier($this->group, packageAmount: 25, priceCents: 5500);
    $this->rice->update(['name' => 'Bio Reis']);
    $this->rice->supplier->update(['name' => 'Mühle', 'contact_email' => 'bestellung@muehle.example']);
    $this->rice->priceTiers->first()->update(['article_number' => 'R-25']);

    CartItem::factory()->for($this->round)->flexible(5, 10)->create(['user_id' => $this->anna->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->ben->id, 'product_id' => $this->rice->id]);

    $this->composer = app(SupplierMailComposer::class);
});

it('drafts a price inquiry with the wanted quantities', function () {
    $mail = $this->composer->compose($this->round, $this->rice->supplier, SupplierMailType::PriceInquiry, $this->lead);

    expect($mail['subject'])->toBe('Preisanfrage Sammelbestellung — '.$this->group->name)
        ->and($mail['body'])->toContain('- Bio Reis: ca. 15–20 kg — Gebinde: 25 kg (Art.-Nr. R-25)')
        ->and($mail['body'])->toContain('Marie Lead')
        ->and($mail['body'])->toContain('030 123456');

    expect($this->composer->canCompose($this->round, $this->rice->supplier, SupplierMailType::Order))->toBeFalse();
});

it('drafts a reminder with the wanted quantities', function () {
    $mail = $this->composer->compose($this->round, $this->rice->supplier, SupplierMailType::FollowUp, $this->lead);

    expect($mail['subject'])->toBe('Nachfrage: Preisanfrage Sammelbestellung — '.$this->group->name)
        ->and($mail['body'])->toContain('- Bio Reis: ca. 15–20 kg — Gebinde: 25 kg (Art.-Nr. R-25)');
});

it('drafts the order of the final proposal with the confirmed prices and shipping', function (int $shippingCents, string $shippingLine) {
    $proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    app(SupplierFeedback::class)->record($this->round, $this->rice->supplier, [
        'shipping_cents' => $shippingCents,
        'prices' => [$this->rice->priceTiers->first()->id => 5200],
    ], $this->lead);
    $item = $proposal->items()->firstOrFail();

    $workflow = app(ProposalWorkflow::class);
    $workflow->publish($proposal, $this->lead);
    $this->round->update(['phase' => RoundPhase::Finalizing]);
    foreach ([$this->anna, $this->ben] as $voter) {
        $workflow->vote($item->fresh(), $voter, VoteValue::Up);
    }
    $workflow->choose($proposal->fresh(), $this->lead);

    $mail = $this->composer->compose($this->round->fresh(), $this->rice->supplier, SupplierMailType::Order, $this->lead);

    expect($mail['body'])->toContain('bestellen wir hiermit verbindlich (alle Preise brutto, inkl. MwSt. und ggf. Pfand):')
        ->and($mail['body'])->toContain('- 1 × 25 kg Bio Reis (Art.-Nr. R-25) à 52,00 € = 52,00 €')
        ->and($mail['body'])->toContain('Warenwert laut Absprache: 52,00 € brutto, '.$shippingLine)
        ->and($mail['body'])->toContain($this->round->pickup_location);
})->with([
    'with shipping' => [900, 'zuzüglich Versandkosten von 9,00 €'],
    'with free shipping' => [0, 'versandkostenfrei wie besprochen'],
]);

it('prefills the mail on the round page for the lead only', function () {
    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->mountAction('composeSupplierMail')
        ->assertActionDataSet([
            'supplier_id' => $this->rice->supplier->id,
            'type' => SupplierMailType::PriceInquiry,
            'to' => 'bestellung@muehle.example',
        ])
        ->assertMountedActionModalSee('Im Mailprogramm öffnen');

    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionHidden('composeSupplierMail');
});
