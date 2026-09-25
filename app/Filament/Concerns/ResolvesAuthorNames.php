<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * Who wrote or did something, as shown in notes and activity streams.
 */
trait ResolvesAuthorNames
{
    /**
     * Names of people from other groups — or from a dissolved one — are not
     * shown.
     */
    public function authorName(?User $user, ?int $groupId): string
    {
        if ($groupId === null || $groupId !== Filament::getTenant()?->getKey()) {
            return 'Mitglied einer anderen Gruppe';
        }

        return $user?->fullName() ?? '—';
    }
}
