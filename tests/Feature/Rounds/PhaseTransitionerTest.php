<?php

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\OrderProposal;
use App\Models\Payment;
use App\Models\PickupDate;
use App\Models\Round;
use App\Models\RoundPackagePrice;
use App\Models\User;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\PhaseTransitioner;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->group = Group::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'owner_id' => $this->owner->id]);
    $this->round = Round::create([
        'group_id' => $this->group->id,
        'lead_user_id' => $this->owner->id,
        'title' => 'R',
        'phase' => RoundPhase::Draft->value,
    ]);
    $this->transitioner = app(PhaseTransitioner::class);
});

it('verbietet illegale Phasen-Sprünge', function () {
    expect(fn () => $this->transitioner->assertCanTransition($this->round, RoundPhase::Completed))
        ->toThrow(ValidationException::class);
});

it('starts shopping without pickup dates, but not without a pickup location', function () {
    expect($this->transitioner->missingRequirements($this->round, RoundPhase::Shopping))
        ->toBe(['Abholort muss gesetzt sein.']);

    $this->round->update(['pickup_location' => 'Bei mir']);

    $this->transitioner->transition($this->round, RoundPhase::Shopping, $this->owner);

    expect($this->round->fresh()->phase)->toBe(RoundPhase::Shopping);
});

it('needs a pickup date before the pickup begins', function () {
    ['round' => $round, 'lead' => $lead] = roundScenario(1, RoundPhase::Delivery);

    expect($this->transitioner->missingRequirements($round, RoundPhase::Pickup))
        ->toBe(['Mindestens ein Abholtermin muss angelegt sein — unter „Eckdaten bearbeiten“.']);

    PickupDate::create(['round_id' => $round->id, 'scheduled_at' => '2026-12-01 18:00']);

    $this->transitioner->transition($round, RoundPhase::Pickup, $lead);

    expect($round->fresh()->phase)->toBe(RoundPhase::Pickup);
});

it('führt Übergänge mit Activity-Eintrag durch', function () {
    PickupDate::create(['round_id' => $this->round->id, 'scheduled_at' => '2026-12-01 18:00']);
    $this->round->update(['pickup_location' => 'Bei mir']);

    $this->transitioner->transition($this->round, RoundPhase::Shopping, $this->owner, 'Los gehts');

    $this->round->refresh();
    expect($this->round->phase)->toBe(RoundPhase::Shopping);
    expect($this->round->activities()->where('action', 'phase_changed')->exists())->toBeTrue();
});

it('verbietet Übergang nach Finalizing ohne publizierten Vorschlag', function () {
    $this->round->update(['phase' => RoundPhase::Negotiating->value]);

    expect(fn () => $this->transitioner->assertCanTransition($this->round, RoundPhase::Finalizing))
        ->toThrow(ValidationException::class);

    OrderProposal::create([
        'round_id' => $this->round->id,
        'proposed_by_user_id' => $this->owner->id,
        'title' => 'P', 'status' => ProposalStatus::Published->value,
    ]);

    $this->transitioner->assertCanTransition($this->round, RoundPhase::Finalizing);
    expect(true)->toBeTrue();
});

it('verbietet Übergang nach Payment ohne gewählten Vorschlag', function () {
    $this->round->update(['phase' => RoundPhase::Finalizing->value]);

    expect(fn () => $this->transitioner->assertCanTransition($this->round, RoundPhase::Payment))
        ->toThrow(ValidationException::class);
});

it('starts a round only while no other round of the group is running', function () {
    PickupDate::create(['round_id' => $this->round->id, 'scheduled_at' => '2026-12-01 18:00']);
    $this->round->update(['pickup_location' => 'Bei mir']);

    $running = Round::factory()->for($this->group)->inPhase(RoundPhase::Pickup)->create(['title' => 'Frühjahr']);
    Round::factory()->for($this->group)->draft()->create(['title' => 'Noch ein Entwurf']);

    expect($this->transitioner->missingRequirements($this->round, RoundPhase::Shopping))
        ->toBe(['Es läuft noch die Bestellrunde „Frühjahr“. Eine Gruppe hat immer nur eine laufende Runde — starte diese, sobald die laufende abgeschlossen oder abgebrochen ist.']);

    $running->update(['phase' => RoundPhase::Completed]);

    $this->transitioner->transition($this->round, RoundPhase::Shopping, $this->owner);

    expect($this->round->fresh()->phase)->toBe(RoundPhase::Shopping)
        ->and($this->group->runningRound()?->is($this->round))->toBeTrue();
});

it('lässt nicht-Leads nicht weiterschalten', function () {
    PickupDate::create(['round_id' => $this->round->id, 'scheduled_at' => '2026-12-01 18:00']);
    $this->round->update(['pickup_location' => 'Bei mir']);

    $stranger = User::factory()->create();

    expect(fn () => $this->transitioner->assertCanTransition($this->round, RoundPhase::Shopping, $stranger))
        ->toThrow(ValidationException::class);
});

it('requires every payment to be settled before the order is placed', function () {
    ['round' => $round, 'lead' => $lead, 'members' => [$anna, $ben]] = roundScenario(2, RoundPhase::Payment);
    $transitioner = app(PhaseTransitioner::class);

    Payment::create(['round_id' => $round->id, 'user_id' => $anna->id, 'amount_cents' => 1000, 'status' => 'paid']);
    Payment::create(['round_id' => $round->id, 'user_id' => $ben->id, 'amount_cents' => 1000, 'status' => 'pending']);

    expect($transitioner->missingRequirements($round, RoundPhase::Ordering))
        ->toBe(['Noch offene Zahlungen: '.$ben->fullName().'.']);

    Payment::where('user_id', $ben->id)->update(['status' => 'waived']);

    $transitioner->transition($round, RoundPhase::Ordering, $lead);

    expect($round->fresh()->phase)->toBe(RoundPhase::Ordering);
});

it('needs a reason to cancel a round', function () {
    ['round' => $round, 'lead' => $lead] = roundScenario(1);
    $transitioner = app(PhaseTransitioner::class);

    expect(fn () => $transitioner->transition($round, RoundPhase::Cancelled, $lead))
        ->toThrow(ValidationException::class, 'Bitte gib einen Grund für den Abbruch an.');

    $transitioner->transition($round, RoundPhase::Cancelled, $lead, 'Lieferant liefert nicht');

    expect($round->fresh()->phase)->toBe(RoundPhase::Cancelled)
        ->and($round->activities()->where('action', 'phase_changed')->latest('id')->first()->describeAction())
        ->toBe('Phase Einkauf → Abgebrochen (Grund: Lieferant liefert nicht)');
});

it('knows the next and previous step of a round', function () {
    ['round' => $round] = roundScenario(1, RoundPhase::Negotiating);
    $transitioner = app(PhaseTransitioner::class);

    expect($transitioner->nextPhase($round))->toBe(RoundPhase::Finalizing)
        ->and($transitioner->previousPhase($round))->toBe(RoundPhase::Shopping);

    $round->phase = RoundPhase::Pickup;

    expect($transitioner->nextPhase($round))->toBe(RoundPhase::Completed)
        ->and($transitioner->previousPhase($round))->toBeNull();
});

it('records the paid prices as reference values when the order is placed', function () {
    ['group' => $group, 'round' => $round, 'lead' => $lead, 'members' => [$anna]] = roundScenario(1, RoundPhase::Negotiating);
    $rice = productWithTier($group, packageAmount: 25, priceCents: 5500);
    CartItem::factory()->for($round)->exact(25)->create(['user_id' => $anna->id, 'product_id' => $rice->id]);

    RoundPackagePrice::create(['round_id' => $round->id, 'price_tier_id' => $rice->priceTiers->first()->id, 'price_cents' => 5100]);
    $proposal = app(ProposalBuilder::class)->createFromCarts($round, $lead, ['title' => 'P']);
    $item = $proposal->items()->firstOrFail();

    $workflow = app(ProposalWorkflow::class);
    $workflow->publish($proposal, $lead);
    $round->update(['phase' => RoundPhase::Finalizing]);
    $workflow->vote($item->fresh(), $anna, VoteValue::Up);
    $workflow->choose($proposal->fresh(), $lead);

    $transitioner = app(PhaseTransitioner::class);
    $transitioner->transition($round->fresh(), RoundPhase::Payment, $lead);
    Payment::where('round_id', $round->id)->update(['status' => 'paid']);
    $transitioner->transition($round->fresh(), RoundPhase::Ordering, $lead);

    $observation = $rice->priceObservations()->firstOrFail();

    expect($observation->observed_price_cents)->toBe(5100)
        ->and((float) $observation->package_amount)->toBe(25.0)
        ->and($observation->group_id)->toBe($group->id);
});

it('undoes the final choice when the lead goes back to negotiating', function () {
    ['round' => $round, 'lead' => $lead, 'members' => [$anna]] = roundScenario(1, RoundPhase::Finalizing);

    $proposal = OrderProposal::create([
        'round_id' => $round->id, 'proposed_by_user_id' => $lead->id,
        'title' => 'P', 'status' => ProposalStatus::Chosen->value,
    ]);
    $round->update(['chosen_proposal_id' => $proposal->id]);
    Payment::create(['round_id' => $round->id, 'user_id' => $anna->id, 'amount_cents' => 1000]);

    app(PhaseTransitioner::class)->transition($round, RoundPhase::Negotiating, $lead);

    expect($round->fresh()->chosen_proposal_id)->toBeNull()
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Published)
        ->and(Payment::where('round_id', $round->id)->count())->toBe(0);
});

it('drafts the order proposal as soon as shopping ends', function () {
    ['group' => $group, 'round' => $round, 'lead' => $lead, 'members' => [$anna]] = roundScenario(1, RoundPhase::Shopping);
    $rice = productWithTier($group, packageAmount: 10, priceCents: 2800);
    CartItem::factory()->for($round)->exact(10)->create(['user_id' => $anna->id, 'product_id' => $rice->id]);

    app(PhaseTransitioner::class)->transition($round, RoundPhase::Negotiating, $lead);

    $draft = $round->proposals()->sole();

    expect($draft->title)->toBe('Bestellvorschlag')
        ->and($draft->isDraft())->toBeTrue()
        ->and($draft->proposed_by_user_id)->toBe($lead->id)
        ->and($draft->items()->sole()->product_id)->toBe($rice->id);
});

it('continues with a new version of the voted proposal when the lead goes back to the adjustment', function () {
    ['group' => $group, 'round' => $round, 'lead' => $lead, 'members' => [$anna, $ben]] = roundScenario(2, RoundPhase::Negotiating);
    $rice = productWithTier($group, packageAmount: 10, priceCents: 2800);
    CartItem::factory()->for($round)->exact(5)->create(['user_id' => $anna->id, 'product_id' => $rice->id]);
    CartItem::factory()->for($round)->exact(5)->create(['user_id' => $ben->id, 'product_id' => $rice->id]);
    $builder = app(ProposalBuilder::class);
    $voted = $builder->createFromCarts($round, $lead, ['title' => 'Bestellvorschlag']);
    $builder->setAllocation($voted->items()->sole(), $anna->id, 4);
    app(ProposalWorkflow::class)->publish($voted->fresh(), $lead);
    $round->update(['phase' => RoundPhase::Finalizing]);

    app(PhaseTransitioner::class)->transition($round, RoundPhase::Negotiating, $lead);

    $draft = $round->proposals()->where('status', ProposalStatus::Draft->value)->sole();

    expect($draft->based_on_proposal_id)->toBe($voted->id)
        ->and((float) $draft->items()->sole()->allocations()->where('user_id', $anna->id)->value('quantity'))->toBe(4.0);
});
