<?php

namespace App\Services\Rounds;

use App\Mail\LeadHandoverRequestedMail;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The lead may hand a round over — but only if the new lead agrees. The
 * lead fee moves along, since it belongs to whoever finishes the round.
 */
class LeadHandover
{
    public function request(Round $round, User $nominee, User $by, ?string $roundUrl = null): void
    {
        $this->ensure($round->phase->isActive(), 'Die Runde ist bereits beendet.');
        $this->ensure($round->isManagedBy($by), 'Nur der Lead oder Gruppen-Owner kann die Lead-Rolle übergeben.');
        $this->ensure(! $round->isLead($nominee), 'Diese Person ist bereits Lead.');
        $this->ensure($round->group->hasMember($nominee), 'Nur Mitglieder der Gruppe können Lead werden.');
        $this->ensure(! $round->isExcluded($nominee), 'Diese Person wurde aus der Runde ausgeschlossen.');

        $round->update([
            'pending_lead_user_id' => $nominee->id,
            'lead_handover_requested_at' => now(),
        ]);

        $round->logActivity('lead_handover_requested', [
            'from' => $round->lead?->fullName(),
            'to' => $nominee->fullName(),
        ]);

        if ($roundUrl !== null) {
            Mail::to($nominee)->send(new LeadHandoverRequestedMail($round, $by, $roundUrl));
        }
    }

    public function accept(Round $round, User $nominee): void
    {
        $this->ensure($round->pending_lead_user_id === $nominee->id, 'Es gibt keine offene Übergabe an dich.');
        $this->ensure($round->phase->isActive(), 'Die Runde ist bereits beendet.');
        $this->ensure(! $round->isExcluded($nominee), 'Du wurdest aus dieser Runde ausgeschlossen.');

        DB::transaction(function () use ($round, $nominee): void {
            $previousLead = $round->lead;

            $round->update([
                'lead_user_id' => $nominee->id,
                'pending_lead_user_id' => null,
                'lead_handover_requested_at' => null,
            ]);

            RoundParticipant::firstOrCreate(['round_id' => $round->id, 'user_id' => $nominee->id]);

            $round->logActivity('lead_handed_over', [
                'from' => $previousLead?->fullName(),
                'to' => $nominee->fullName(),
            ]);
        });

        $round->unsetRelation('lead');
    }

    public function decline(Round $round, User $nominee): void
    {
        $this->ensure($round->pending_lead_user_id === $nominee->id, 'Es gibt keine offene Übergabe an dich.');

        $this->clear($round);

        $round->logActivity('lead_handover_declined', ['to' => $nominee->fullName()]);
    }

    public function cancel(Round $round, User $by): void
    {
        $this->ensure($round->isManagedBy($by), 'Nur der Lead oder Gruppen-Owner kann die Übergabe zurückziehen.');
        $this->ensure($round->pending_lead_user_id !== null, 'Es gibt keine offene Übergabe.');

        $nominee = $round->pendingLead;
        $this->clear($round);

        $round->logActivity('lead_handover_cancelled', ['to' => $nominee?->fullName()]);
    }

    private function clear(Round $round): void
    {
        $round->update([
            'pending_lead_user_id' => null,
            'lead_handover_requested_at' => null,
        ]);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['lead' => $message]);
        }
    }
}
