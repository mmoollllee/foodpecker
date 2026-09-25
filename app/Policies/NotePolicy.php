<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Note;
use App\Models\Product;
use App\Models\Round;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes on rounds stay inside the group. Notes on shared manufacturers and
 * products are visible to every group, so others learn from experiences.
 */
class NotePolicy
{
    public function create(User $user, Model $notable): bool
    {
        if ($notable instanceof Round) {
            return $user->can('addNote', $notable);
        }

        $group = $this->currentGroup();

        return $group !== null
            && ($notable instanceof Manufacturer || $notable instanceof Product)
            && $user->can('view', $notable)
            && $user->canInGroup($group, 'note:create');
    }

    public function delete(User $user, Note $note): bool
    {
        if ($note->user_id === $user->id) {
            return true;
        }

        if ($note->notable instanceof Round) {
            return $user->can('manage', $note->notable);
        }

        $group = $this->currentGroup();

        return $group !== null
            && $note->group_id === $group->id
            && $user->canInGroup($group, 'note:delete');
    }

    private function currentGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }
}
