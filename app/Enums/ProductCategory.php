<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ProductCategory: string implements HasColor, HasIcon, HasLabel
{
    case Grains = 'grains';
    case Flours = 'flours';
    case Pasta = 'pasta';
    case Legumes = 'legumes';
    case Condiments = 'condiments';
    case Oils = 'oils';
    case Sweeteners = 'sweeteners';
    case Spices = 'spices';
    case Dairy = 'dairy';
    case Beverages = 'beverages';
    case Produce = 'produce';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Grains => 'Getreide',
            self::Flours => 'Mehle',
            self::Pasta => 'Teigwaren',
            self::Legumes => 'Hülsenfrüchte',
            self::Condiments => 'Feinkost',
            self::Oils => 'Öle & Essig',
            self::Sweeteners => 'Süßungsmittel',
            self::Spices => 'Gewürze',
            self::Dairy => 'Milchprodukte',
            self::Beverages => 'Getränke',
            self::Produce => 'Obst & Gemüse',
            self::Other => 'Sonstiges',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Grains => 'amber',
            self::Flours => 'yellow',
            self::Pasta => 'orange',
            self::Legumes => 'lime',
            self::Condiments => 'rose',
            self::Oils => 'emerald',
            self::Sweeteners => 'pink',
            self::Spices => 'red',
            self::Dairy => 'sky',
            self::Beverages => 'blue',
            self::Produce => 'green',
            self::Other => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return Heroicon::Tag;
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Grains => 1,
            self::Flours => 2,
            self::Pasta => 3,
            self::Legumes => 4,
            self::Produce => 5,
            self::Dairy => 6,
            self::Oils => 7,
            self::Condiments => 8,
            self::Spices => 9,
            self::Sweeteners => 10,
            self::Beverages => 11,
            self::Other => 99,
        };
    }
}
