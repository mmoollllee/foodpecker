<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Mails a lead sends to a supplier during a round.
 */
enum SupplierMailType: string implements HasLabel
{
    case PriceInquiry = 'price_inquiry';
    case FollowUp = 'follow_up';
    case Order = 'order';

    public function getLabel(): string
    {
        return match ($this) {
            self::PriceInquiry => 'Preisanfrage',
            self::FollowUp => 'Nachfassen',
            self::Order => 'Bestellung',
        };
    }
}
