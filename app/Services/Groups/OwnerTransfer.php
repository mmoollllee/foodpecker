<?php

namespace App\Services\Groups;

use App\Enums\GroupRole;
use App\Mail\OwnerTransferRequestedMail;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The owner may hand the group over — but only if the new owner agrees.
 * The previous owner stays as a moderator and may leave the group then.
 */
class OwnerTransfer
{
    public function request(Group $group, User $nominee, User $by, ?string $membersUrl = null): void
    {
        $this->ensure($by->isOwnerOf($group), 'Nur der Owner kann die Owner-Rolle übergeben.');
        $this->ensure(! $nominee->isOwnerOf($group), 'Diese Person ist bereits Owner.');
        $this->ensure($group->hasMember($nominee), 'Nur Mitglieder der Gruppe können Owner werden.');
        $this->ensure($group->pending_owner_id === null, 'Es gibt schon eine offene Übergabe — zieh sie erst zurück.');

        $group->update([
            'pending_owner_id' => $nominee->id,
            'owner_transfer_requested_at' => now(),
        ]);
        $group->setRelation('pendingOwner', $nominee);

        $group->logActivity('owner_transfer_requested', [
            'from' => $by->fullName(),
            'to' => $nominee->fullName(),
        ], $by);

        if ($membersUrl !== null) {
            Mail::to($nominee)->send(new OwnerTransferRequestedMail($group, $by, $membersUrl));
        }
    }

    public function accept(Group $group, User $nominee): void
    {
        $this->ensure($group->pending_owner_id === $nominee->id, 'Es gibt keine offene Übergabe an dich.');
        $this->ensure($group->hasMember($nominee), 'Du bist kein Mitglied dieser Gruppe mehr.');

        DB::transaction(function () use ($group, $nominee): void {
            $previousOwner = $group->owner;

            $group->update([
                'owner_id' => $nominee->id,
                'pending_owner_id' => null,
                'owner_transfer_requested_at' => null,
            ]);

            $group->members()->updateExistingPivot($nominee->id, ['role' => GroupRole::Owner->value]);

            if ($previousOwner !== null) {
                $group->members()->updateExistingPivot($previousOwner->id, ['role' => GroupRole::Moderator->value]);
            }

            $group->logActivity('owner_transferred', [
                'from' => $previousOwner?->fullName(),
                'to' => $nominee->fullName(),
            ], $nominee);
        });

        $group->setRelation('owner', $nominee)->setRelation('pendingOwner', null);
    }

    public function decline(Group $group, User $nominee): void
    {
        $this->ensure($group->pending_owner_id === $nominee->id, 'Es gibt keine offene Übergabe an dich.');

        $this->clear($group);

        $group->logActivity('owner_transfer_declined', ['to' => $nominee->fullName()], $nominee);
    }

    public function cancel(Group $group, User $by): void
    {
        $this->ensure($by->isOwnerOf($group), 'Nur der Owner kann die Übergabe zurückziehen.');
        $this->ensure($group->pending_owner_id !== null, 'Es gibt keine offene Übergabe.');

        $nominee = $group->pendingOwner;
        $this->clear($group);

        $group->logActivity('owner_transfer_cancelled', ['to' => $nominee?->fullName()], $by);
    }

    /**
     * Whoever leaves the group can't take it over anymore.
     */
    public function forgetMember(Group $group, User $member): void
    {
        if ($group->pending_owner_id === $member->id) {
            $this->clear($group);

            $group->logActivity('owner_transfer_cancelled', ['to' => $member->fullName()]);
        }
    }

    private function clear(Group $group): void
    {
        $group->update([
            'pending_owner_id' => null,
            'owner_transfer_requested_at' => null,
        ]);

        $group->setRelation('pendingOwner', null);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['owner' => $message]);
        }
    }
}
