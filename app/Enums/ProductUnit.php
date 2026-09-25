<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Base unit in which a product is ordered and distributed.
 */
enum ProductUnit: string implements HasLabel
{
    case Kilogram = 'kg';
    case Gram = 'g';
    case Liter = 'l';
    case Milliliter = 'ml';
    case Piece = 'stk';
    case Jar = 'glas';
    case Pack = 'pkg';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kilogram => 'Kilogramm (kg)',
            self::Gram => 'Gramm (g)',
            self::Liter => 'Liter (l)',
            self::Milliliter => 'Milliliter (ml)',
            self::Piece => 'Stück',
            self::Jar => 'Glas',
            self::Pack => 'Packung',
        };
    }

    /**
     * Short form used as input suffix and next to quantities.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Kilogram => 'kg',
            self::Gram => 'g',
            self::Liter => 'l',
            self::Milliliter => 'ml',
            self::Piece => 'Stück',
            self::Jar => 'Glas',
            self::Pack => 'Packung',
        };
    }
}
