<?php

use App\Enums\ManufacturerMailType;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Models\CartItem;
use App\Services\Notifications\ManufacturerMailComposer;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use Livewire\Livewire;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);
    $this->lead->update(['first_name' => 'Marie', 'last_name' => 'Lead', 'phone' => '030 123456']);

    $this->rice = productWithTier($this->group, packageAmount: 25, priceCents: 5500);
    $this->rice->update(['name' => 'Bio Reis']);
    $this->rice->manufacturer->update(['name' => 'Mühle', 'contact_email' => 'bestellung@muehle.example']);

    CartItem::factory()->for($this->round)->flexible(5, 10)->create(['user_id' => $this->anna->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->exact(10)->create(['user_id' => $this->ben->id, 'product_id' => $this->rice->id]);

    $this->composer = app(ManufacturerMailComposer::class);
});

it('drafts a price inquiry with the wanted quantities', function () {
    $mail = $this->composer->compose($this->round, $this->rice->manufacturer, ManufacturerMailType::PriceInquiry, $this->lead);

    expect($mail['subject'])->toBe('Preisanfrage Sammelbestellung — '.$this->group->name)
        ->and($mail['body'])->toContain('- Bio Reis: ca. 15–20 kg (z. B. als 25 kg)')
        ->and($mail['body'])->toContain('Marie Lead')
        ->and($mail['body'])->toContain('030 123456');

    expect($this->composer->canCompose($this->round, $this->rice->manufacturer, ManufacturerMailType::Order))->toBeFalse();
});

it('drafts the order of the final proposal with the negotiated prices', function () {
    $proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'P']);
    $item = $proposal->items()->firstOrFail();
    app(ProposalBuilder::class)->updateItem($item, $item->toPackageSpec()->withPrice(5200));

    $workflow = app(ProposalWorkflow::class);
    $workflow->publish($proposal, $this->lead);
    $this->round->update(['phase' => RoundPhase::Finalizing]);
    foreach ([$this->anna, $this->ben] as $voter) {
        $workflow->vote($item->fresh(), $voter, VoteValue::Up);
    }
    $workflow->choose($proposal->fresh(), $this->lead);

    $mail = $this->composer->compose($this->round->fresh(), $this->rice->manufacturer, ManufacturerMailType::Order, $this->lead);

    expect($mail['body'])->toContain('- 1 × 25 kg Bio Reis à 52,00 € = 52,00 €')
        ->and($mail['body'])->toContain($this->round->pickup_location);
});

it('prefills the mail on the round page for the lead only', function () {
    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->mountAction('composeManufacturerMail')
        ->assertActionDataSet([
            'manufacturer_id' => $this->rice->manufacturer->id,
            'type' => ManufacturerMailType::PriceInquiry,
            'to' => 'bestellung@muehle.example',
        ])
        ->assertMountedActionModalSee('Im Mailprogramm öffnen');

    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionHidden('composeManufacturerMail');
});
