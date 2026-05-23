<?php

namespace App\Concerns;

use App\Models\Activity;
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
        return $this->morphMany(Activity::class, 'subject')->latest();
    }

    public function logActivity(string $action, array $properties = []): Activity
    {
        $groupId = $this->group_id ?? Filament::getTenant()?->getKey();

        return $this->activities()->create([
            'action' => $action,
            'properties' => $properties,
            'user_id' => auth()->id(),
            'group_id' => $groupId,
        ]);
    }
}
