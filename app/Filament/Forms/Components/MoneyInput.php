<?php

namespace App\Filament\Forms\Components;

use App\Filament\Forms\StateCasts\MoneyStateCast;
use App\Services\Money\Money;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * Euro amount input that stores integer cents but lets people type
 * "12,50" instead of "1250".
 */
class MoneyInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->suffix('€')
            ->inputMode('decimal')
            ->placeholder('0,00')
            ->stateCast(new MoneyStateCast)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && Money::parse(is_scalar($value) ? $value : null) === null) {
                    $fail('Bitte einen Betrag wie 12,50 eingeben.');
                }
            });
    }
}
