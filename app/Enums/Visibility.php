<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Visibility: string implements HasColor, HasLabel
{
    case Private = 'private';
    case Public = 'public';

    public function getLabel(): string
    {
        return match ($this) {
            self::Private => 'Privat',
            self::Public => 'Öffentlich',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Private => 'gray',
            self::Public => 'success',
        };
    }
}
