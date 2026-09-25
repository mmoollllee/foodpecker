<?php

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Models\Payment;
use App\Services\Rounds\RoundUpDonation;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Payment);
    $this->payment = Payment::create(['round_id' => $this->round->id, 'user_id' => $this->anna->id, 'amount_cents' => 4321]);
    $this->donation = app(RoundUpDonation::class);
});

it('rounds a share up to full euros, ten euros or a chosen total', function () {
    expect($this->donation->options($this->payment))->toBe(['none' => 0, 'euro' => 79, 'ten' => 679]);

    $this->donation->apply($this->payment, $this->anna, RoundUpDonation::TEN_EUROS);

    expect($this->payment->fresh()->round_up_donation_cents)->toBe(679)
        ->and($this->payment->fresh()->totalCents())->toBe(5000)
        ->and($this->round->participantFor($this->anna)->round_up_to_cents)->toBe(1000);

    $this->donation->apply($this->payment->fresh(), $this->anna, RoundUpDonation::CUSTOM, 4500);

    expect($this->payment->fresh()->round_up_donation_cents)->toBe(179);
});

it('only lets people round up their own open share during the payment phase', function () {
    expect(fn () => $this->donation->apply($this->payment, $this->ben, RoundUpDonation::FULL_EURO))
        ->toThrow(ValidationException::class, 'Du kannst nur deinen eigenen Anteil aufrunden.');

    expect(fn () => $this->donation->apply($this->payment, $this->anna, RoundUpDonation::CUSTOM, 4000))
        ->toThrow(ValidationException::class, 'Der Betrag muss mindestens so hoch sein wie dein Anteil von 43,21 €.');

    $this->payment->update(['status' => 'paid']);

    expect(fn () => $this->donation->apply($this->payment->fresh(), $this->anna, RoundUpDonation::FULL_EURO))
        ->toThrow(ValidationException::class, 'Dein Anteil ist schon bezahlt');
});

it('offers rounding up on the round page to the person who pays', function () {
    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionVisible('roundUp')
        ->callAction('roundUp', data: ['mode' => RoundUpDonation::FULL_EURO])
        ->assertNotified('Danke für deine Spende von 0,79 €!');

    actingInGroup($this->ben, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])->assertActionHidden('roundUp');
});
