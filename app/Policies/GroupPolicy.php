<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $group->hasMember($user);
    }

    public function update(User $user, Group $group): bool
    {
        return $user->canInGroup($group, 'group:update');
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->isOwnerOf($group);
    }

    /**
     * Only the owner hands the group over — the new owner has to agree.
     */
    public function transferOwnership(User $user, Group $group): bool
    {
        return $user->isOwnerOf($group);
    }

    public function invite(User $user, Group $group): bool
    {
        return $user->canInGroup($group, 'group:invite');
    }

    public function manageMembers(User $user, Group $group): bool
    {
        return $user->canInGroup($group, 'group:manage-members');
    }

    /**
     * Only the owner may remove people from the group.
     */
    public function removeMember(User $user, Group $group, User $member): bool
    {
        return $user->isOwnerOf($group) && ! $member->isOwnerOf($group);
    }

    /**
     * Everybody except the owner may leave a group.
     */
    public function leave(User $user, Group $group): bool
    {
        return $group->hasMember($user) && ! $user->isOwnerOf($group);
    }
}
