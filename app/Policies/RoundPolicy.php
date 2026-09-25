<?php

namespace App\Policies;

use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\Round;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Rounds are visible to all group members (drafts only to their lead) and
 * managed by the lead, with the group owner as fallback.
 */
class RoundPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Round $round): bool
    {
        if (! $round->group->hasMember($user)) {
            return false;
        }

        return $round->phase !== RoundPhase::Draft || $round->isLead($user);
    }

    public function create(User $user): bool
    {
        $group = Filament::getTenant();

        return $group instanceof Group && $user->canInGroup($group, 'round:create');
    }

    public function update(User $user, Round $round): bool
    {
        return $this->manage($user, $round) && $round->phase->isActive();
    }

    /**
     * Only drafts and cancelled rounds can be deleted; everything else is history.
     */
    public function delete(User $user, Round $round): bool
    {
        return $this->manage($user, $round)
            && in_array($round->phase, [RoundPhase::Draft, RoundPhase::Cancelled], true);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Lead actions: switching phases, proposals, payments, notifications,
     * excluding participants.
     */
    public function manage(User $user, Round $round): bool
    {
        return $round->isManagedBy($user) && $round->group->hasMember($user);
    }

    /**
     * Filling the own cart during the shopping phase.
     */
    public function shop(User $user, Round $round): bool
    {
        if ($round->phase !== RoundPhase::Shopping || ! $round->group->hasMember($user)) {
            return false;
        }

        $participant = $round->participantFor($user);

        if ($participant?->removed) {
            return false;
        }

        return $participant !== null || ! $round->hasReachedParticipantLimit();
    }

    /**
     * Creating proposals: the lead while negotiating and confirming,
     * everybody else as counter-proposal while confirming.
     */
    public function propose(User $user, Round $round): bool
    {
        if ($this->manage($user, $round)) {
            return in_array($round->phase, [RoundPhase::Negotiating, RoundPhase::Finalizing], true);
        }

        return $round->phase === RoundPhase::Finalizing && $this->isActiveParticipant($user, $round);
    }

    public function vote(User $user, Round $round): bool
    {
        return in_array($round->phase, [RoundPhase::Negotiating, RoundPhase::Finalizing], true)
            && $this->isActiveParticipant($user, $round);
    }

    /**
     * Notes are open to every member, e.g. to learn from a completed round.
     */
    public function addNote(User $user, Round $round): bool
    {
        return $this->view($user, $round);
    }

    private function isActiveParticipant(User $user, Round $round): bool
    {
        $participant = $round->participantFor($user);

        return $participant !== null && ! $participant->removed;
    }
}
