<?php

namespace App\Services\Groups;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Changes to who is in a group and with which role — each one lands in the
 * group history.
 */
class GroupMembership
{
    public function __construct(private OwnerTransfer $ownerTransfer) {}

    public function changeRole(Group $group, User $member, GroupRole $role, User $by): void
    {
        $this->ensure($by->can('manageMembers', $group), 'Du darfst keine Rollen vergeben.');
        $this->ensure(! $member->isOwnerOf($group), 'Die Owner-Rolle wechselt nur per Übergabe.');
        $this->ensure(in_array($role, GroupRole::assignable(), true), 'Diese Rolle lässt sich nicht vergeben.');

        $previousRole = $group->roleOf($member);
        $this->ensure($previousRole !== null, 'Diese Person ist kein Mitglied der Gruppe.');

        if ($previousRole === $role) {
            return;
        }

        $group->members()->updateExistingPivot($member->id, ['role' => $role->value]);

        $group->logActivity('role_changed', [
            'name' => $member->fullName(),
            'from' => $previousRole->value,
            'to' => $role->value,
        ], $by);
    }

    public function remove(Group $group, User $member, User $by): void
    {
        $this->ensure($group->hasMember($member), 'Diese Person ist kein Mitglied der Gruppe.');
        $this->ensure($by->can('removeMember', [$group, $member]), 'Nur der Owner kann Mitglieder entfernen.');

        $group->members()->detach($member->id);
        $this->ownerTransfer->forgetMember($group, $member);

        $group->logActivity('member_removed', ['name' => $member->fullName()], $by);
    }

    public function leave(Group $group, User $member): void
    {
        $this->ensure($member->can('leave', $group), 'Der Owner kann die Gruppe nicht verlassen — übergib erst die Owner-Rolle.');

        $group->members()->detach($member->id);
        $this->ownerTransfer->forgetMember($group, $member);

        if ($member->current_group_id === $group->id) {
            $member->forceFill(['current_group_id' => null])->save();
        }

        // Loaded memberships would still list the group.
        $member->unsetRelation('groups');

        $group->logActivity('member_left', [], $member);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['member' => $message]);
        }
    }
}
