<?php

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\Pages\CreateRound;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Mail\LeadHandoverRequestedMail;
use App\Models\Round;
use App\Models\User;
use App\Services\Rounds\LeadHandover;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Rule: the lead hands a round over only with the consent of the new lead.
 */
beforeEach(function () {
    Mail::fake();

    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Shopping);
    $this->handover = app(LeadHandover::class);
});

it('keeps the lead until the new lead accepts', function () {
    $this->handover->request($this->round, $this->anna, $this->lead, 'https://example.test/runde');

    expect($this->round->fresh()->lead_user_id)->toBe($this->lead->id)
        ->and($this->round->fresh()->pending_lead_user_id)->toBe($this->anna->id);

    Mail::assertSent(LeadHandoverRequestedMail::class, fn (LeadHandoverRequestedMail $mail) => $mail->hasTo($this->anna->email));

    $this->handover->accept($this->round->fresh(), $this->anna);

    expect($this->round->fresh()->lead_user_id)->toBe($this->anna->id)
        ->and($this->round->fresh()->pending_lead_user_id)->toBeNull()
        ->and($this->round->activities()->where('action', 'lead_handed_over')->exists())->toBeTrue();
});

it('lets the asked person decline and the lead withdraw the request', function () {
    $this->handover->request($this->round, $this->anna, $this->lead);
    $this->handover->decline($this->round->fresh(), $this->anna);

    expect($this->round->fresh()->pending_lead_user_id)->toBeNull()
        ->and($this->round->fresh()->lead_user_id)->toBe($this->lead->id);

    $this->handover->request($this->round->fresh(), $this->ben, $this->lead);
    $this->handover->cancel($this->round->fresh(), $this->lead);

    expect($this->round->fresh()->pending_lead_user_id)->toBeNull();
});

it('only lets the asked person accept', function () {
    $this->handover->request($this->round, $this->anna, $this->lead);

    expect(fn () => $this->handover->accept($this->round->fresh(), $this->ben))
        ->toThrow(ValidationException::class, 'Es gibt keine offene Übergabe an dich.');
});

it('only lets the lead or owner hand over, and only to members who take part', function () {
    expect(fn () => $this->handover->request($this->round, $this->ben, $this->anna))
        ->toThrow(ValidationException::class, 'Nur der Lead oder Gruppen-Owner kann die Lead-Rolle übergeben.');

    expect(fn () => $this->handover->request($this->round, User::factory()->create(), $this->lead))
        ->toThrow(ValidationException::class, 'Nur Mitglieder der Gruppe können Lead werden.');

    $this->round->participants()->where('user_id', $this->ben->id)->update(['removed' => true]);

    expect(fn () => $this->handover->request($this->round->fresh(), $this->ben, $this->lead))
        ->toThrow(ValidationException::class, 'Diese Person wurde aus der Runde ausgeschlossen.');
});

it('offers the request and the answer on the round page', function () {
    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->callAction('requestLeadHandover', data: ['user_id' => $this->anna->id])
        ->assertHasNoActionErrors();

    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertSee('möchte dir die Lead-Rolle für diese Runde übergeben')
        ->assertActionVisible('declineLeadHandover')
        ->callAction('acceptLeadHandover')
        ->assertNotified('Du bist jetzt Lead dieser Runde.');

    expect($this->round->fresh()->lead_user_id)->toBe($this->anna->id);

    actingInGroup($this->ben, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionHidden('acceptLeadHandover')
        ->assertActionHidden('requestLeadHandover');
});

it('makes the creator of a round its lead', function () {
    $undoRepeaterFake = Repeater::fake();
    actingInGroup($this->lead, $this->group);

    Livewire::test(CreateRound::class)
        ->fillForm([
            'title' => 'Herbst',
            'pickup_location' => 'Bei mir',
            'pickupDates' => [['window' => ['start' => '2026-12-05 10:00:00', 'end' => '2026-12-05 12:00:00']]],
            'lead_fee_percent' => 2,
            'platform_fee_percent' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $round = Round::where('title', 'Herbst')->firstOrFail();

    expect($round->lead_user_id)->toBe($this->lead->id)
        ->and($round->phase)->toBe(RoundPhase::Draft)
        ->and($round->participantFor($this->lead))->not->toBeNull()
        ->and($round->pickupDates()->sole()->label())->toBe('Sa. 05.12.2026 10:00–12:00');

    $undoRepeaterFake();
});
