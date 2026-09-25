<?php

namespace App\Concerns;

use App\Models\Activity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait HasActivities
{
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest()->latest('id');
    }

    /**
     * Logs what happened — done by the logged-in person unless an actor is
     * given, e.g. for someone joining through an invitation link.
     */
    public function logActivity(string $action, array $properties = [], ?User $actor = null): Activity
    {
        return $this->activities()->create([
            'action' => $action,
            'properties' => $properties,
            'user_id' => $actor?->getKey() ?? auth()->id(),
            'group_id' => $this->activityGroupId(),
        ]);
    }

    /**
     * The activity belongs to the group the person acts for — on a shared
     * product that is not necessarily the group owning it.
     */
    protected function activityGroupId(): ?int
    {
        return Filament::getTenant()?->getKey() ?? $this->group_id;
    }
}
