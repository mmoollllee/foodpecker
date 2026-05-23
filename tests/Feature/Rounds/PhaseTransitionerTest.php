<?php

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\OrderProposal;
use App\Models\PickupDate;
use App\Models\Round;
use App\Models\User;
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
    $this->transitioner = new PhaseTransitioner;
});

it('verbietet illegale Phasen-Sprünge', function () {
    expect(fn () => $this->transitioner->assertCanTransition($this->round, RoundPhase::Completed))
        ->toThrow(ValidationException::class);
});

it('erlaubt Draft → Shopping nur mit Abholtermin und Abholort', function () {
    expect(fn () => $this->transitioner->assertCanTransition($this->round, RoundPhase::Shopping))
        ->toThrow(ValidationException::class);

    PickupDate::create(['round_id' => $this->round->id, 'scheduled_at' => '2026-12-01 18:00']);
    $this->round->update(['pickup_location' => 'Bei mir']);

    $this->transitioner->assertCanTransition($this->round, RoundPhase::Shopping);
    expect(true)->toBeTrue();
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

it('lässt nicht-Leads nicht weiterschalten', function () {
    PickupDate::create(['round_id' => $this->round->id, 'scheduled_at' => '2026-12-01 18:00']);
    $this->round->update(['pickup_location' => 'Bei mir']);

    $stranger = User::factory()->create();

    expect(fn () => $this->transitioner->assertCanTransition($this->round, RoundPhase::Shopping, $stranger))
        ->toThrow(ValidationException::class);
});
