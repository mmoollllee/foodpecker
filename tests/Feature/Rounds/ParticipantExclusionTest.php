<?php

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Models\CartItem;
use App\Models\OrderProposal;
use App\Models\Payment;
use App\Services\Distribution\PackageSpec;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\ParticipantExclusion;
use Illuminate\Validation\ValidationException;

/**
 * Rule: the lead may exclude single participants from the order, so one
 * person can't block everybody else. Proposals that still contain them are
 * withdrawn; the recalculated version needs a new vote.
 */
beforeEach(function () {
    ['group' => $this->group, 'round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);

    $this->rice = productWithTier($this->group, packageAmount: 10, priceCents: 3000);

    CartItem::factory()->for($this->round)->exact(4)->create(['user_id' => $this->lead->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->flexible(2, 6)->create(['user_id' => $this->anna->id, 'product_id' => $this->rice->id]);
    CartItem::factory()->for($this->round)->exact(4)->create(['user_id' => $this->ben->id, 'product_id' => $this->rice->id]);

    $this->workflow = app(ProposalWorkflow::class);
    $this->exclusion = app(ParticipantExclusion::class);

    $this->proposal = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'Vorschlag A']);
    $this->workflow->publish($this->proposal, $this->lead);
    $this->round->update(['phase' => RoundPhase::Finalizing]);
});

it('excludes a blocking participant with a visible reason and withdraws the proposals that contain them', function () {
    $item = $this->proposal->items()->firstOrFail();
    $this->workflow->vote($item, $this->ben, VoteValue::Down, 'Will doch keinen Reis');

    $withdrawn = $this->exclusion->exclude($this->round, $this->ben, 'Blockiert ohne Alternative', $this->lead);

    $participant = $this->round->participants()->where('user_id', $this->ben->id)->firstOrFail();

    expect($withdrawn)->toBe(1)
        ->and($participant->removed)->toBeTrue()
        ->and($participant->remove_reason)->toBe('Blockiert ohne Alternative')
        ->and($participant->removed_by_user_id)->toBe($this->lead->id)
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Withdrawn)
        ->and($this->group->hasMember($this->ben))->toBeTrue()
        ->and($this->round->activities()->where('action', 'participant_excluded')->exists())->toBeTrue();
});

it('recalculates a new version without the excluded participant that everybody else votes on again', function () {
    $this->exclusion->exclude($this->round, $this->ben, 'Blockiert', $this->lead);

    $version = app(ProposalBuilder::class)->createNewVersion($this->proposal->fresh(), $this->lead);

    expect($version->title)->toBe('Vorschlag A (Version 2)')
        ->and($version->status)->toBe(ProposalStatus::Draft)
        ->and($version->includedUserIds()->all())->toEqualCanonicalizing([$this->lead->id, $this->anna->id])
        ->and($version->votes()->count())->toBe(0);

    // 4 kg exact + 2–6 kg flexible → the 10 kg sack is filled by Anna's flexibility.
    $allocations = $version->items()->firstOrFail()->allocations()->pluck('quantity', 'user_id');

    expect((float) $allocations[$this->lead->id])->toBe(4.0)
        ->and((float) $allocations[$this->anna->id])->toBe(6.0);

    $this->workflow->publish($version, $this->lead);

    foreach ($version->items()->with('allocations.user')->get() as $item) {
        foreach ($item->allocations as $allocation) {
            $this->workflow->vote($item, $allocation->user, VoteValue::Up);
        }
    }

    $this->workflow->choose($version->fresh(), $this->lead);

    expect(Payment::where('round_id', $this->round->id)->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->lead->id, $this->anna->id]);
});

it('recalculates drafts without the excluded participant instead of withdrawing them', function () {
    $draft = app(ProposalBuilder::class)->createNewVersion($this->proposal->fresh(), $this->lead);

    $withdrawn = $this->exclusion->exclude($this->round, $this->ben, 'Keine Rückmeldung seit zwei Wochen', $this->lead);

    $draft->refresh();
    $allocations = $draft->items()->firstOrFail()->allocations()->pluck('quantity', 'user_id');

    expect($withdrawn)->toBe(1)
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Withdrawn)
        ->and($draft->status)->toBe(ProposalStatus::Draft)
        ->and($draft->includedUserIds()->all())->toEqualCanonicalizing([$this->lead->id, $this->anna->id])
        ->and((float) $allocations[$this->anna->id])->toBe(6.0);

    $this->workflow->publish($draft, $this->lead);

    expect($draft->fresh()->status)->toBe(ProposalStatus::Published);
});

it('puts a readmitted participant back into the drafts', function () {
    $mustard = productWithTier($this->group, packageAmount: 12, priceCents: 3600, step: 1);
    CartItem::factory()->for($this->round)->exact(12)->create(['user_id' => $this->ben->id, 'product_id' => $mustard->id]);

    $draft = app(ProposalBuilder::class)->createNewVersion($this->proposal->fresh(), $this->lead);
    $this->exclusion->exclude($this->round, $this->ben, 'Pause', $this->lead);

    expect($draft->fresh()->items()->where('product_id', $mustard->id)->exists())->toBeFalse();

    $this->exclusion->readmit($this->round, $this->ben, $this->lead);

    $mustardItem = $draft->fresh()->items()->where('product_id', $mustard->id)->first();

    expect($draft->fresh()->includedUserIds()->all())->toContain($this->ben->id)
        ->and($mustardItem?->stakeholderIds()->all())->toBe([$this->ben->id])
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Withdrawn);
});

it('offers only people who did not agree to an earlier proposal for exclusion', function () {
    $draft = app(ProposalBuilder::class)->createNewVersion($this->proposal->fresh(), $this->lead);

    expect($this->exclusion->candidatesFor($draft))->toEqual([
        $this->anna->id => 'keine Rückmeldung zu „Vorschlag A“',
        $this->ben->id => 'keine Rückmeldung zu „Vorschlag A“',
    ]);

    $item = $this->proposal->items()->firstOrFail();
    $this->workflow->vote($item, $this->anna, VoteValue::Up);
    $this->workflow->vote($item, $this->ben, VoteValue::Down, 'Zu viel Reis');

    expect($this->exclusion->candidatesFor($draft))->toBe([
        $this->ben->id => '👎 '.$this->rice->name.' in „Vorschlag A“',
    ]);
});

it('offers nobody for exclusion before a proposal was up for a vote', function () {
    $firstDraft = app(ProposalBuilder::class)->createFromCarts($this->round, $this->lead, ['title' => 'Erster Entwurf']);
    $this->proposal->delete();

    expect($this->exclusion->candidatesFor($firstDraft))->toBe([]);
});

it('undoes an already chosen final order that contains the excluded participant', function () {
    foreach ($this->proposal->items()->with('allocations.user')->get() as $item) {
        foreach ($item->allocations as $allocation) {
            $this->workflow->vote($item, $allocation->user, VoteValue::Up);
        }
    }
    $this->workflow->choose($this->proposal->fresh(), $this->lead);

    expect(Payment::where('round_id', $this->round->id)->count())->toBe(3);

    $this->exclusion->exclude($this->round, $this->ben, 'Zieht zurück', $this->lead);

    expect($this->round->fresh()->chosen_proposal_id)->toBeNull()
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Withdrawn)
        ->and(Payment::where('round_id', $this->round->id)->count())->toBe(0);
});

it('keeps proposals that do not contain the excluded participant', function () {
    $otherProduct = productWithTier($this->group, packageAmount: 1, priceCents: 500, step: 1);
    CartItem::factory()->for($this->round)->exact(2)->create(['user_id' => $this->anna->id, 'product_id' => $otherProduct->id]);

    $onlyAnna = OrderProposal::create([
        'round_id' => $this->round->id,
        'proposed_by_user_id' => $this->lead->id,
        'title' => 'Nur Anna',
        'status' => ProposalStatus::Draft,
    ]);
    app(ProposalBuilder::class)->createItem(
        $onlyAnna,
        $otherProduct,
        PackageSpec::fromTier($otherProduct->priceTiers->first()),
        $this->round->cartItems()->where('product_id', $otherProduct->id)->get(),
    );

    $this->exclusion->exclude($this->round, $this->ben, 'Blockiert', $this->lead);

    expect($onlyAnna->fresh()->status)->toBe(ProposalStatus::Draft);
});

it('never excludes the lead and always needs a reason', function () {
    expect(fn () => $this->exclusion->exclude($this->round, $this->lead, 'Lead raus', $this->lead))
        ->toThrow(ValidationException::class, 'Der Lead kann nicht ausgeschlossen werden.');

    expect(fn () => $this->exclusion->exclude($this->round, $this->ben, ' ', $this->lead))
        ->toThrow(ValidationException::class, 'Bitte gib einen Grund für den Ausschluss an.');
});

it('excludes only while a new proposal is prepared, before the payment phase', function (RoundPhase $phase) {
    $this->round->update(['phase' => $phase]);

    expect(fn () => $this->exclusion->exclude($this->round, $this->ben, 'Zu spät', $this->lead))
        ->toThrow(ValidationException::class, 'Ausschließen geht nur, während ein neuer Vorschlag vorbereitet wird — in der Verhandlungs- oder Bestätigungsphase.');
})->with([
    'shopping' => RoundPhase::Shopping,
    'payment' => RoundPhase::Payment,
]);

it('takes excluded participants back in after going back to shopping', function () {
    $this->exclusion->exclude($this->round, $this->ben, 'Pause', $this->lead);
    $this->round->update(['phase' => RoundPhase::Shopping]);

    $this->exclusion->readmit($this->round, $this->ben, $this->lead);

    expect($this->round->fresh()->isExcluded($this->ben))->toBeFalse();
});

it('ignores the carts of excluded participants and lets the lead take them back in', function () {
    $this->exclusion->exclude($this->round, $this->ben, 'Pause', $this->lead);

    expect($this->round->activeCartItems()->pluck('user_id')->all())->not->toContain($this->ben->id);

    $this->exclusion->readmit($this->round, $this->ben, $this->lead);

    expect($this->round->participants()->where('user_id', $this->ben->id)->value('removed'))->toBeFalsy()
        ->and($this->round->activeCartItems()->pluck('user_id')->all())->toContain($this->ben->id);
});
