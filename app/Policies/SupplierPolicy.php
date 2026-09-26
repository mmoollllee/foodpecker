<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Suppliers can be shared between groups, but only moderators of the
 * owning group may change them.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Supplier $supplier): bool
    {
        $group = $this->currentGroup();

        return $supplier->isPublic() || ($group !== null && $supplier->group_id === $group->id);
    }

    public function create(User $user): bool
    {
        $group = $this->currentGroup();

        return $group !== null && $user->canInGroup($group, 'supplier:create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        $group = $this->currentGroup();

        return $group !== null
            && ($supplier->group_id === null || $supplier->group_id === $group->id)
            && $user->canInGroup($group, 'supplier:update');
    }

    /**
     * Suppliers with products (even archived ones) stay, so orders keep
     * their history.
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier)
            && ! $supplier->products()->withTrashed()->exists();
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
