<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Manufacturers can be shared between groups, but only moderators of the
 * owning group may change them.
 */
class ManufacturerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Manufacturer $manufacturer): bool
    {
        $group = $this->currentGroup();

        return $manufacturer->isPublic() || ($group !== null && $manufacturer->group_id === $group->id);
    }

    public function create(User $user): bool
    {
        $group = $this->currentGroup();

        return $group !== null && $user->canInGroup($group, 'manufacturer:create');
    }

    public function update(User $user, Manufacturer $manufacturer): bool
    {
        $group = $this->currentGroup();

        return $group !== null
            && ($manufacturer->group_id === null || $manufacturer->group_id === $group->id)
            && $user->canInGroup($group, 'manufacturer:update');
    }

    /**
     * Manufacturers with products (even archived ones) stay, so orders keep
     * their history.
     */
    public function delete(User $user, Manufacturer $manufacturer): bool
    {
        return $this->update($user, $manufacturer)
            && ! $manufacturer->products()->withTrashed()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function currentGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }
}
