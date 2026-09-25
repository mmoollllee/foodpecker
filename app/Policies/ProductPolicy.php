<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Products can be shared between groups, but only moderators of the owning
 * group may change them. Deleting archives (soft delete); only products
 * nobody ever ordered can be removed for good.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        $group = $this->currentGroup();

        return $product->isPublic() || ($group !== null && $product->group_id === $group->id);
    }

    public function create(User $user): bool
    {
        $group = $this->currentGroup();

        return $group !== null && $user->canInGroup($group, 'product:create');
    }

    public function update(User $user, Product $product): bool
    {
        $group = $this->currentGroup();

        return $group !== null
            && ($product->group_id === null || $product->group_id === $group->id)
            && $user->canInGroup($group, 'product:update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $this->update($user, $product) && ! $product->isReferenced();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function currentGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }
}
