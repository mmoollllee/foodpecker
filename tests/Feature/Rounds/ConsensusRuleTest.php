<?php

use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Models\CartItem;
use App\Models\OrderProposal;
use App\Models\Payment;
use App\Models\Pickup;
use App\Models\User;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\ConsensusChecker;
use App\Services\Rounds\PhaseTransitioner;
use Illuminate\Validation\ValidationException;

/**
 * Rule: only people who receive a share of an item decide about it, and a
 * proposal can only become the final order when every one of them agreed.
 */
beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben, $this->cleo]] = roundScenario(3, RoundPhase::Negotiating);

    $this->rice = productWithTier($this->group, packageAmount: 10, priceCents: 3000);
    $this->mustard = productWithTier($this->group, packageAmount: 1, priceCents: 400, step: 1);

    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->anna->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->exact(5)->create(['user_id' => $this->ben->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->exact(2)->create(['user_id' => $this->cleo->id, 'product_id' => $this->mustard->id]);

    $this->proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'Vorschlag']);
    $this->workflow = app(ProposalWorkflow::class);
    $this->workflow->publish($this->proposal, $this->lead);
    $this->round->update(['phase' => RoundPhase::Finalizing]);

    $this->riceItem = $this->proposal->items()->where('product_id', $this->rice->id)->firstOrFail();
    $this->mustardItem = $this->proposal->items()->where('product_id', $this->mustard->id)->firstOrFail();
});

function approveAll(OrderProposal $proposal, ProposalWorkflow $workflow): void
{
    foreach ($proposal->items()->with('allocations.user')->get() as $item) {
        foreach ($item->allocations as $allocation) {
            $workflow->vote($item, $allocation->user, VoteValue::Up);
        }
    }
}

it('counts only the votes of the people who receive a share of an item', function () {
    // Cleo gets no rice: her thumbs down on the rice is only an opinion.
    $this->workflow->vote($this->riceItem, $this->cleo, VoteValue::Down, 'Zu viel Reis für die Gruppe');

    approveAll($this->proposal, $this->workflow);

    $consensus = app(ConsensusChecker::class)->evaluate($this->proposal->fresh());

    expect($consensus->forItem($this->riceItem->id)->stakeholderIds)->toEqualCanonicalizing([$this->anna->id, $this->ben->id])
        ->and($consensus->forItem($this->mustardItem->id)->stakeholderIds)->toBe([$this->cleo->id])
        ->and($consensus->isUnanimous())->toBeTrue();
});

it('blocks the proposal while a stakeholder has not voted or voted thumbs down', function () {
    $this->workflow->vote($this->riceItem, $this->anna, VoteValue::Up);
    $this->workflow->vote($this->mustardItem, $this->cleo, VoteValue::Up);

    $consensus = app(ConsensusChecker::class)->evaluate($this->proposal->fresh());
    expect($consensus->isUnanimous())->toBeFalse()
        ->and($consensus->pendingUserIds())->toBe([$this->ben->id]);

    $this->workflow->vote($this->riceItem, $this->ben, VoteValue::Down, 'Lieber nur 3 kg');

    $consensus = app(ConsensusChecker::class)->evaluate($this->proposal->fresh());
    expect($consensus->isUnanimous())->toBeFalse()
        ->and($consensus->rejections())->toBe([$this->ben->id => [$this->riceItem->id => 'Lieber nur 3 kg']]);

    expect(fn () => $this->workflow->choose($this->proposal->fresh(), $this->lead))
        ->toThrow(ValidationException::class, 'Noch nicht einstimmig');
});

it('requires a reason for a thumbs down', function () {
    expect(fn () => $this->workflow->vote($this->riceItem, $this->anna, VoteValue::Down))
        ->toThrow(ValidationException::class, 'Bitte begründe dein Daumen runter.');

    expect(fn () => $this->workflow->vote($this->riceItem, $this->anna, VoteValue::Down, '  '))
        ->toThrow(ValidationException::class);

    $vote = $this->workflow->vote($this->riceItem, $this->anna, VoteValue::Down, 'Zu teuer');

    expect($vote->reason)->toBe('Zu teuer');
});

it('only lets active participants of the round vote', function () {
    $outsider = User::factory()->create();
    $this->group->members()->attach($outsider->id, ['role' => 'participant']);

    expect(fn () => $this->workflow->vote($this->riceItem, $outsider, VoteValue::Up))
        ->toThrow(ValidationException::class, 'Nur Teilnehmer der Runde können abstimmen.');

    $this->round->participants()->where('user_id', $this->cleo->id)->update(['removed' => true]);

    expect(fn () => $this->workflow->vote($this->mustardItem, $this->cleo->fresh(), VoteValue::Up))
        ->toThrow(ValidationException::class, 'Du wurdest aus dieser Runde ausgeschlossen.');
});

it('only accepts votes on published proposals', function () {
    $this->workflow->withdraw($this->proposal, $this->lead);

    expect(fn () => $this->workflow->vote($this->riceItem->fresh(), $this->anna, VoteValue::Up))
        ->toThrow(ValidationException::class, 'Abstimmen ist nur bei freigegebenen Vorschlägen möglich.');
});

it('chooses a unanimous proposal and creates payments and pickups for everybody in it', function () {
    approveAll($this->proposal, $this->workflow);

    $this->workflow->choose($this->proposal->fresh(), $this->lead);

    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Chosen)
        ->and($this->round->fresh()->chosen_proposal_id)->toBe($this->proposal->id);

    $payments = Payment::where('round_id', $this->round->id)->get();

    expect($payments->pluck('user_id')->all())->toEqualCanonicalizing([$this->anna->id, $this->ben->id, $this->cleo->id])
        ->and($payments->every(fn (Payment $payment) => $payment->status === PaymentStatus::Pending))->toBeTrue()
        ->and(Pickup::where('round_id', $this->round->id)->count())->toBe(3);
});

it('only chooses the final order during the confirmation phase', function () {
    approveAll($this->proposal, $this->workflow);
    $this->round->update(['phase' => RoundPhase::Negotiating]);

    expect(fn () => $this->workflow->choose($this->proposal->fresh(), $this->lead))
        ->toThrow(ValidationException::class, 'Die finale Bestellung wird in der Bestätigungsphase gewählt.');
});

it('does not let the round enter the payment phase without a unanimous choice', function () {
    $transitioner = app(PhaseTransitioner::class);

    expect($transitioner->missingRequirements($this->round, RoundPhase::Payment))
        ->toContain('Bitte zuerst einen einstimmig bestätigten Vorschlag als finale Bestellung wählen.');

    approveAll($this->proposal, $this->workflow);
    $this->workflow->choose($this->proposal->fresh(), $this->lead);

    $transitioner->transition($this->round->fresh(), RoundPhase::Payment, $this->lead);

    expect($this->round->fresh()->phase)->toBe(RoundPhase::Payment);
});
