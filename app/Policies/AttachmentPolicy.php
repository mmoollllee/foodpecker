<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Documents follow the same sharing rules as notes: round documents stay in
 * the group, documents on shared manufacturers and products are for all.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        $attachable = $attachment->attachable;

        if (($attachable instanceof Manufacturer || $attachable instanceof Product) && $attachable->isPublic()) {
            return true;
        }

        $group = Group::find($attachment->group_id);

        return $group !== null && $group->hasMember($user);
    }

    public function create(User $user, Model $attachable): bool
    {
        if ($attachable instanceof Round) {
            return $user->can('addNote', $attachable);
        }

        $group = $this->currentGroup();

        return $group !== null
            && ($attachable instanceof Manufacturer || $attachable instanceof Product)
            && $user->can('view', $attachable)
            && $user->canInGroup($group, 'attachment:create');
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if ($attachment->uploaded_by_user_id === $user->id) {
            return true;
        }

        if ($attachment->attachable instanceof Round) {
            return $user->can('manage', $attachment->attachable);
        }

        $group = $this->currentGroup();

        return $group !== null
            && $attachment->group_id === $group->id
            && $user->canInGroup($group, 'attachment:delete');
    }

    private function currentGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }
}
