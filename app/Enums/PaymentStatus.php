<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Waived = 'waived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Offen',
            self::Paid => 'Bezahlt',
            self::Waived => 'Erlassen',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'danger',
            self::Paid => 'success',
            self::Waived => 'gray',
        };
    }
}
