<?php

namespace App\Services\Rounds;

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\Round;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PhaseTransitioner
{
    /**
     * Prüft, ob ein Übergang erlaubt ist — ohne Daten zu ändern.
     *
     * Wirft eine Exception mit lesbarer Fehlermeldung, wenn nicht.
     */
    public function assertCanTransition(Round $round, RoundPhase $to, ?User $user = null): void
    {
        if (! $round->phase->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'phase' => sprintf(
                    'Übergang von "%s" nach "%s" ist nicht erlaubt.',
                    $round->phase->getLabel(),
                    $to->getLabel(),
                ),
            ]);
        }

        if ($user) {
            $isLead = $round->lead_user_id === $user->id;
            $isOwner = $round->group?->owner_id === $user->id;
            if (! $isLead && ! $isOwner) {
                throw ValidationException::withMessages([
                    'phase' => 'Nur der Lead oder Gruppen-Owner darf die Phase wechseln.',
                ]);
            }
        }

        match ($to) {
            RoundPhase::Shopping => $this->guardEnterShopping($round),
            RoundPhase::Negotiating => $this->guardEnterNegotiating($round),
            RoundPhase::Finalizing => $this->guardEnterFinalizing($round),
            RoundPhase::Payment => $this->guardEnterPayment($round),
            default => null,
        };
    }

    /**
     * Führt den Übergang durch.
     */
    public function transition(Round $round, RoundPhase $to, ?User $user = null, ?string $reason = null): Round
    {
        $this->assertCanTransition($round, $to, $user);

        $from = $round->phase;
        $round->forceFill([
            'phase' => $to->value,
            'phase_changed_at' => now(),
        ])->save();

        $round->logActivity('phase_changed', [
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
        ]);

        return $round;
    }

    private function guardEnterShopping(Round $round): void
    {
        if ($round->pickupDates()->count() === 0) {
            throw ValidationException::withMessages([
                'phase' => 'Mindestens ein Abholtermin muss angelegt sein.',
            ]);
        }
        if (blank($round->pickup_location)) {
            throw ValidationException::withMessages([
                'phase' => 'Abholort muss gesetzt sein.',
            ]);
        }
    }

    private function guardEnterNegotiating(Round $round): void
    {
        $hasItems = $round->cartItems()->exists();
        if (! $hasItems) {
            throw ValidationException::withMessages([
                'phase' => 'Es liegt noch kein gefüllter Warenkorb vor.',
            ]);
        }
    }

    private function guardEnterFinalizing(Round $round): void
    {
        $hasPublished = $round->proposals()
            ->where('status', ProposalStatus::Published->value)
            ->orWhere('status', ProposalStatus::Chosen->value)
            ->exists();
        if (! $hasPublished) {
            throw ValidationException::withMessages([
                'phase' => 'Mindestens ein Vorschlag muss zur Abstimmung freigegeben sein.',
            ]);
        }
    }

    private function guardEnterPayment(Round $round): void
    {
        if ($round->chosen_proposal_id === null) {
            throw ValidationException::withMessages([
                'phase' => 'Bitte zuerst einen Vorschlag als endgültig markieren.',
            ]);
        }
    }
}
