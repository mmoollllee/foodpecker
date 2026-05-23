<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum VoteValue: string implements HasColor, HasIcon, HasLabel
{
    case Up = 'up';
    case Down = 'down';

    public function getLabel(): string
    {
        return match ($this) {
            self::Up => 'Daumen hoch',
            self::Down => 'Daumen runter',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Up => 'success',
            self::Down => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Up => Heroicon::HandThumbUp,
            self::Down => Heroicon::HandThumbDown,
        };
    }
}
