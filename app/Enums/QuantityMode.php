<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum QuantityMode: string implements HasColor, HasLabel
{
    case Exact = 'exact';
    case Flexible = 'flexible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Exact => 'Exakte Menge',
            self::Flexible => 'Flexible Spanne',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Exact => 'info',
            self::Flexible => 'success',
        };
    }
}
