<?php

namespace App\Services\Rounds;

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\OrderProposal;
use App\Models\Payment;
use App\Models\Round;
use App\Models\User;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use Illuminate\Validation\ValidationException;

class PhaseTransitioner
{
    public function __construct(
        private ConsensusChecker $consensus,
        private ProposalWorkflow $workflow,
        private PriceObservationRecorder $priceObservations,
        private ProposalBuilder $builder,
    ) {}

    /**
     * The regular next step of the round, if there is one.
     */
    public function nextPhase(Round $round): ?RoundPhase
    {
        return collect($round->phase->allowedTransitions())
            ->first(fn (RoundPhase $phase): bool => $phase !== RoundPhase::Cancelled
                && $phase->order() > $round->phase->order());
    }

    /**
     * The step back that is allowed for corrections, if there is one.
     */
    public function previousPhase(Round $round): ?RoundPhase
    {
        return collect($round->phase->allowedTransitions())
            ->first(fn (RoundPhase $phase): bool => $phase !== RoundPhase::Cancelled
                && $phase->order() < $round->phase->order());
    }

    /**
     * What is still missing before the round may enter the given phase.
     *
     * @return array<int, string>
     */
    public function missingRequirements(Round $round, RoundPhase $to): array
    {
        return match ($to) {
            RoundPhase::Shopping => $round->phase === RoundPhase::Draft ? $this->missingForShopping($round) : [],
            RoundPhase::Negotiating => $round->phase === RoundPhase::Shopping ? $this->missingForNegotiating($round) : [],
            RoundPhase::Finalizing => $round->phase === RoundPhase::Negotiating ? $this->missingForFinalizing($round) : [],
            RoundPhase::Payment => $this->missingForPayment($round),
            RoundPhase::Ordering => $this->missingForOrdering($round),
            RoundPhase::Pickup => $this->missingForPickup($round),
            default => [],
        };
    }

    /**
     * Prüft, ob ein Übergang erlaubt ist — ohne Daten zu ändern.
     *
     * Wirft eine Exception mit lesbarer Fehlermeldung, wenn nicht.
     */
    public function assertCanTransition(Round $round, RoundPhase $to, ?User $user = null, ?string $reason = null): void
    {
        if (! $round->phase->canTransitionTo($to)) {
            $this->fail(sprintf(
                'Übergang von "%s" nach "%s" ist nicht erlaubt.',
                $round->phase->getLabel(),
                $to->getLabel(),
            ));
        }

        if ($user && ! $round->isManagedBy($user)) {
            $this->fail('Nur der Lead oder Gruppen-Owner darf die Phase wechseln.');
        }

        if ($to === RoundPhase::Cancelled && blank($reason)) {
            $this->fail('Bitte gib einen Grund für den Abbruch an.');
        }

        if ($missing = $this->missingRequirements($round, $to)) {
            $this->fail($missing[0]);
        }
    }

    /**
     * Führt den Übergang durch.
     */
    public function transition(Round $round, RoundPhase $to, ?User $user = null, ?string $reason = null): Round
    {
        $this->assertCanTransition($round, $to, $user, $reason);

        $from = $round->phase;

        if ($from === RoundPhase::Finalizing && $to === RoundPhase::Negotiating) {
            $this->workflow->unchoose($round);
        }

        $round->forceFill([
            'phase' => $to->value,
            'phase_changed_at' => now(),
        ])->save();

        $round->logActivity('phase_changed', [
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
        ]);

        if ($to === RoundPhase::Negotiating) {
            $this->prepareDraft($round);
        }

        if ($to === RoundPhase::Ordering) {
            $this->priceObservations->record($round);
        }

        return $round;
    }

    /**
     * The order proposal is drafted as soon as shopping ends; existing
     * drafts catch up with the carts. Coming back from the vote, the lead
     * continues with a new version of the proposal that was up for it, so
     * its corrections stay.
     */
    private function prepareDraft(Round $round): void
    {
        $drafts = $round->proposals()->where('status', ProposalStatus::Draft->value)->get();
        $drafts->each(fn (OrderProposal $draft) => $this->builder->recalculate($draft));

        if ($drafts->contains('proposed_by_user_id', $round->lead_user_id)) {
            return;
        }

        $votedOn = $round->proposals()->openForVoting()->latest('id')->get();
        $base = $votedOn->firstWhere('proposed_by_user_id', $round->lead_user_id) ?? $votedOn->first();

        $base !== null
            ? $this->builder->createNewVersion($base, $round->lead)
            : $this->builder->createFromCarts($round, $round->lead, ['title' => 'Bestellvorschlag']);
    }

    /**
     * @return array<int, string>
     */
    private function missingForShopping(Round $round): array
    {
        $missing = [];
        $running = $round->group?->runningRound();

        if ($running !== null && ! $running->is($round)) {
            $missing[] = "Es läuft noch die Bestellrunde „{$running->title}“. Eine Gruppe hat immer nur eine laufende Runde — starte diese, sobald die laufende abgeschlossen oder abgebrochen ist.";
        }

        if (blank($round->pickup_location)) {
            $missing[] = 'Abholort muss gesetzt sein.';
        }

        return $missing;
    }

    /**
     * Pickup dates may be set late, once the delivery date is known — but
     * nobody can pick up without one.
     *
     * @return array<int, string>
     */
    private function missingForPickup(Round $round): array
    {
        return $round->pickupDates()->exists()
            ? []
            : ['Mindestens ein Abholtermin muss angelegt sein — unter „Eckdaten bearbeiten“.'];
    }

    /**
     * @return array<int, string>
     */
    private function missingForNegotiating(Round $round): array
    {
        return $round->activeCartItems()->exists()
            ? []
            : ['Es liegt noch kein gefüllter Warenkorb vor.'];
    }

    /**
     * @return array<int, string>
     */
    private function missingForFinalizing(Round $round): array
    {
        return $round->proposals()->openForVoting()->exists()
            ? []
            : ['Mindestens ein Vorschlag muss zur Abstimmung freigegeben sein.'];
    }

    /**
     * @return array<int, string>
     */
    private function missingForPayment(Round $round): array
    {
        $proposal = $round->chosenProposal;

        if ($proposal === null) {
            return ['Bitte zuerst einen einstimmig bestätigten Vorschlag als finale Bestellung wählen.'];
        }

        $consensus = $this->consensus->evaluate($proposal);

        return $consensus->isUnanimous()
            ? []
            : [$this->workflow->explainMissingConsensus($consensus)];
    }

    /**
     * @return array<int, string>
     */
    private function missingForOrdering(Round $round): array
    {
        $payments = $round->payments()->with('user')->get();

        if ($payments->isEmpty()) {
            return ['Es gibt noch keine Zahlungen — ist eine finale Bestellung gewählt?'];
        }

        $open = $payments->reject(fn (Payment $payment): bool => $payment->isSettled());

        if ($open->isEmpty()) {
            return [];
        }

        return [sprintf(
            'Noch offene Zahlungen: %s.',
            $open->map(fn (Payment $payment): string => $payment->user?->fullName() ?? '—')->sort()->implode(', '),
        )];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['phase' => $message]);
    }
}
