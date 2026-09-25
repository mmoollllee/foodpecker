<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Mails a lead sends to a manufacturer during a round.
 */
enum ManufacturerMailType: string implements HasLabel
{
    case PriceInquiry = 'price_inquiry';
    case Order = 'order';

    public function getLabel(): string
    {
        return match ($this) {
            self::PriceInquiry => 'Preisanfrage',
            self::Order => 'Bestellung',
        };
    }
}
