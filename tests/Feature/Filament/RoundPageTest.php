<?php

use App\Enums\NotificationKind;
use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Mail\RoundNotificationMail;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\NotificationDraft;
use App\Models\Payment;
use App\Models\ProposalVote;
use App\Models\Round;
use App\Models\User;
use App\Services\Notifications\DraftBuilder;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\ParticipantExclusion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);

    $this->rice = productWithTier($this->group, packageAmount: 10, priceCents: 3000);
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->anna->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->ben->id, 'product_id' => $this->rice->id]);

    $this->proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'Vorschlag A']);
    $this->item = $this->proposal->items()->firstOrFail();
});

function publishAndConfirm(Round $round, $proposal, User $lead): void
{
    app(ProposalWorkflow::class)->publish($proposal, $lead);
    $round->update(['phase' => RoundPhase::Finalizing]);
}

it('hides lead actions from participants', function () {
    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertOk()
        ->assertActionHidden('nextPhase')
        ->assertActionHidden('editRound')
        ->assertActionHidden('generateNotification')
        ->assertActionHidden('cancelRound')
        ->assertActionHidden('createProposal')
        ->assertActionHidden(TestAction::make('editProposalItem')->arguments(['item' => $this->item->id]))
        ->assertActionHidden(TestAction::make('excludeParticipant')->arguments(['proposal' => $this->proposal->id]));
});

it('refuses lead actions that a participant calls anyway', function () {
    publishAndConfirm($this->round, $this->proposal, $this->lead);
    $this->round->update(['phase' => RoundPhase::Payment]);
    $payment = Payment::create(['round_id' => $this->round->id, 'user_id' => $this->anna->id, 'amount_cents' => 1500]);

    actingInGroup($this->anna, $this->group);

    // A crafted request that mounts hidden actions directly must not change anything.
    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionHidden(TestAction::make('markPayment')->arguments(['payment' => $payment->id]))
        ->call('mountAction', 'markPayment', ['payment' => $payment->id])
        ->call('callMountedAction')
        ->call('mountAction', 'nextPhase')
        ->call('callMountedAction');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($this->round->fresh()->phase)->toBe(RoundPhase::Payment);
});

it('keeps members of other groups out of the round', function () {
    $stranger = User::factory()->create();
    $otherGroup = Group::factory()->create(['owner_id' => $stranger->id]);

    actingInGroup($stranger, $otherGroup);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertNotFound();
});

it('shows drafts only to their lead', function () {
    $this->round->update(['phase' => RoundPhase::Draft]);

    actingInGroup($this->lead, $this->group);
    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertOk()
        ->assertActionVisible('startRound');

    actingInGroup($this->anna, $this->group);
    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertNotFound();
});

it('lets participants vote and asks for a reason for a thumbs down', function () {
    publishAndConfirm($this->round, $this->proposal, $this->lead);
    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->callAction(TestAction::make('voteDown')->arguments(['item' => $this->item->id]), data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->callAction(TestAction::make('voteUp')->arguments(['item' => $this->item->id]))
        ->assertHasNoActionErrors();

    expect(ProposalVote::where('user_id', $this->anna->id)->value('value'))->toBe(VoteValue::Up);
});

it('only offers the final choice once everybody involved agreed', function () {
    publishAndConfirm($this->round, $this->proposal, $this->lead);
    $arguments = ['proposal' => $this->proposal->id];

    actingInGroup($this->lead, $this->group);
    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionVisible(TestAction::make('chooseProposal')->arguments($arguments))
        ->assertActionDisabled(TestAction::make('chooseProposal')->arguments($arguments));

    foreach ([$this->anna, $this->ben] as $voter) {
        app(ProposalWorkflow::class)->vote($this->item, $voter, VoteValue::Up);
    }

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionEnabled(TestAction::make('chooseProposal')->arguments($arguments))
        ->callAction(TestAction::make('chooseProposal')->arguments($arguments))
        ->assertNotified('Finale Bestellung gewählt.');

    expect($this->round->fresh()->chosen_proposal_id)->toBe($this->proposal->id);
});

it('lets the lead exclude somebody who did not agree while preparing a new version', function () {
    publishAndConfirm($this->round, $this->proposal, $this->lead);
    app(ProposalWorkflow::class)->vote($this->item, $this->anna, VoteValue::Up);
    $draft = app(ProposalBuilder::class)->createNewVersion($this->proposal->fresh(), $this->lead);
    $exclude = TestAction::make('excludeParticipant')->arguments(['proposal' => $draft->id]);

    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionHidden(TestAction::make('excludeParticipant')->arguments(['proposal' => $this->proposal->id]))
        ->assertActionVisible($exclude)
        ->callAction($exclude, data: ['user_id' => $this->anna->id, 'reason' => 'Hat doch zugestimmt'])
        ->assertHasActionErrors(['user_id']);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->callAction($exclude, data: ['user_id' => $this->ben->id, 'reason' => 'Seit zwei Wochen keine Rückmeldung'])
        ->assertHasNoActionErrors()
        ->assertNotified($this->ben->fullName().' ist ausgeschlossen.');

    expect($this->round->fresh()->isExcluded($this->ben))->toBeTrue()
        ->and($this->round->fresh()->isExcluded($this->anna))->toBeFalse()
        ->and($draft->fresh()->includedUserIds()->all())->toBe([$this->anna->id])
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Withdrawn);
});

it('lets the lead take an excluded participant back in from the proposals tab', function () {
    publishAndConfirm($this->round, $this->proposal, $this->lead);
    app(ParticipantExclusion::class)->exclude($this->round, $this->ben, 'Keine Rückmeldung', $this->lead);
    $participant = $this->round->participantFor($this->ben);

    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertSee('Keine Rückmeldung')
        ->assertActionHidden(TestAction::make('readmitParticipant')->arguments(['participant' => $participant->id]));

    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->callAction(TestAction::make('readmitParticipant')->arguments(['participant' => $participant->id]))
        ->assertNotified($this->ben->fullName().' ist wieder dabei.');

    expect($this->round->fresh()->isExcluded($this->ben))->toBeFalse();
});

it('shows a proposal as a table with a column per person, starting with you', function () {
    publishAndConfirm($this->round, $this->proposal, $this->lead);
    actingInGroup($this->ben, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertSeeInOrder(['Position', 'Gesamt', $this->ben->first_name, 'du', 'Waren', 'Versand', 'Aufwandsentschädigung Lead (2,5 %)', 'Vereinsbeitrag (1,0 %)', 'Summe']);
});

it('records a negotiated price entered in euros', function () {
    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->callAction(
            TestAction::make('editProposalItem')->arguments(['item' => $this->item->id]),
            data: ['package_price_cents' => '27,50', 'packages' => null],
        )
        ->assertHasNoActionErrors();

    expect($this->item->fresh()->package_price_cents)->toBe(2750)
        ->and($this->item->fresh()->total_price_cents)->toBe(2750);
});

it('switches to the next phase and opens the prepared notification', function () {
    $this->round->update(['phase' => RoundPhase::Shopping]);
    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertActionHasLabel('nextPhase', 'Weiter zu: Verhandlung')
        ->callAction('nextPhase', data: ['prepare_notification' => true])
        ->assertActionMounted('sendDraft');

    expect($this->round->fresh()->phase)->toBe(RoundPhase::Negotiating)
        ->and(NotificationDraft::where('round_id', $this->round->id)->value('subject'))->toStartWith('📞 Verhandlungen laufen');
});

it('sends a notification by mail to the participants and remembers it', function () {
    Mail::fake();
    actingInGroup($this->lead, $this->group);

    $draft = app(DraftBuilder::class)->buildDraft($this->round, NotificationKind::Custom, $this->lead);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->callAction(TestAction::make('sendDraft')->arguments(['draft' => $draft->id]), data: [
            'subject' => 'Kurzes Update',
            'body' => 'Hallo zusammen, **bitte** abstimmen.',
            'all_members' => false,
        ])
        ->assertHasNoActionErrors();

    Mail::assertSent(RoundNotificationMail::class, 3);
    Mail::assertSent(RoundNotificationMail::class, fn (RoundNotificationMail $mail) => $mail->hasTo($this->anna->email)
        && $mail->hasReplyTo($this->lead->email)
        && $mail->envelope()->subject === 'Kurzes Update');

    expect($draft->fresh()->isSent())->toBeTrue()
        ->and($draft->fresh()->recipient_count)->toBe(3);
});

it('does not render lead buttons for participants', function () {
    publishAndConfirm($this->round, $this->proposal, $this->lead);

    actingInGroup($this->anna, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertSee('👍')
        ->assertDontSee('Als finale Bestellung wählen')
        ->assertDontSee('Zur Abstimmung freigeben')
        ->assertDontSee('Person ausschließen')
        ->assertDontSee('Anpassen');

    // Excluding is only offered while preparing a new version.
    actingInGroup($this->lead, $this->group);

    Livewire::test(ViewRound::class, ['record' => $this->round->id])
        ->assertSee('Als finale Bestellung wählen')
        ->assertDontSee('Person ausschließen');
});
