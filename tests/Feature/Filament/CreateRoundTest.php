<?php

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\Pages\CreateRound;
use App\Models\Round;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Wizard;
use Livewire\Livewire;

beforeEach(function () {
    ['group' => $this->group, 'lead' => $this->lead] = roundScenario(1, RoundPhase::Completed);

    actingInGroup($this->lead, $this->group);
});

it('asks for the finances in the first step of the wizard', function () {
    $form = Livewire::test(CreateRound::class)->instance()->getSchema('form');
    $wizard = $form->getComponent(fn ($component): bool => $component instanceof Wizard);
    $firstStep = array_values($wizard->getChildSchema()->getComponents())[0];

    expect($firstStep->getLabel())->toBe('Eckdaten')
        ->and(array_keys($firstStep->getChildSchema()->getFlatFields(withHidden: true)))
        ->toContain('title', 'lead_fee_percent', 'platform_fee_percent');
});

it('saves the finances set in the wizard', function () {
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateRound::class)
        ->fillForm([
            'title' => 'Herbst',
            'lead_fee_percent' => 80,
            'platform_fee_percent' => 1.5,
            'pickup_location' => 'Bei mir',
            'pickupDates' => [['scheduled_at' => now()->addDays(30)->format('Y-m-d H:i:s')]],
        ])
        ->call('create')
        ->assertHasFormErrors(['lead_fee_percent' => 'max'])
        ->fillForm(['lead_fee_percent' => 3])
        ->call('create')
        ->assertHasNoFormErrors();

    $round = Round::where('title', 'Herbst')->firstOrFail();

    expect((float) $round->lead_fee_percent)->toBe(3.0)
        ->and((float) $round->platform_fee_percent)->toBe(1.5);

    $undoRepeaterFake();
});

it('fits into four steps, so the wizard header shows all of them', function () {
    Livewire::test(CreateRound::class)
        ->assertWizardStepExists(4)
        ->assertSee('Eckdaten')
        ->assertSee('Finanzen')
        ->assertSee('Aufwandsentschädigung für den Lead');
});
