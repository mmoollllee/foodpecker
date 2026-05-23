<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum PackagingStrategy: string implements HasColor, HasDescription, HasLabel
{
    case Fixed = 'fixed';
    case Tiered = 'tiered';
    case PaletteDivisible = 'palette_divisible';
    case MultiSizeIndivisible = 'multi_size_indivisible';
    case BulkWeighable = 'bulk_weighable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Feste Paketgröße',
            self::Tiered => 'Mengenstaffel',
            self::PaletteDivisible => 'Palette, teilbar',
            self::MultiSizeIndivisible => 'Mehrere Größen, nicht teilbar',
            self::BulkWeighable => 'Großgebinde, abwiegbar',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Fixed => 'Ein einziges festes Gebinde, intern auf Personen aufteilbar.',
            self::Tiered => 'Mehrere Gebindegrößen mit unterschiedlichen Kilopreisen — Gruppe wählt eine.',
            self::PaletteDivisible => 'Palette in festem Schritt (z. B. 12er-Pack Gläser), intern einzeln verteilbar.',
            self::MultiSizeIndivisible => 'Mehrere Packungsgrößen, jede Packung geht ganz an eine Person.',
            self::BulkWeighable => 'Großgebinde in vollen Schritten, intern frei abwiegbar.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Fixed => 'gray',
            self::Tiered => 'info',
            self::PaletteDivisible => 'warning',
            self::MultiSizeIndivisible => 'danger',
            self::BulkWeighable => 'success',
        };
    }
}
