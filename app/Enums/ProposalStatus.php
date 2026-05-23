<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProposalStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';
    case Chosen = 'chosen';
    case Withdrawn = 'withdrawn';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Published => 'Zur Abstimmung',
            self::Chosen => 'Ausgewählt',
            self::Withdrawn => 'Zurückgezogen',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'info',
            self::Chosen => 'success',
            self::Withdrawn => 'danger',
        };
    }
}
