<?php

namespace App\Filament\Forms\StateCasts;

use App\Services\Money\Money;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * Casts between integer cents (model / dehydrated state) and a German
 * decimal string (Livewire state shown in the input, e.g. "12,50").
 */
class MoneyStateCast implements StateCast
{
    public function get(mixed $state): ?int
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (is_int($state)) {
            return $state;
        }

        return Money::parse((string) $state);
    }

    public function set(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (is_int($state)) {
            return Money::toInputString($state);
        }

        return (string) $state;
    }
}
