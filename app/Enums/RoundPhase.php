<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RoundPhase: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Shopping = 'shopping';
    case Negotiating = 'negotiating';
    case Finalizing = 'finalizing';
    case Payment = 'payment';
    case Ordering = 'ordering';
    case Delivery = 'delivery';
    case Pickup = 'pickup';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Shopping => 'Einkauf',
            self::Negotiating => 'Verhandlung',
            self::Finalizing => 'Bestätigung',
            self::Payment => 'Zahlung',
            self::Ordering => 'Bestellung',
            self::Delivery => 'Lieferung',
            self::Pickup => 'Abholung',
            self::Completed => 'Abgeschlossen',
            self::Cancelled => 'Abgebrochen',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Shopping => 'info',
            self::Negotiating => 'warning',
            self::Finalizing => 'amber',
            self::Payment => 'danger',
            self::Ordering => 'purple',
            self::Delivery => 'indigo',
            self::Pickup => 'success',
            self::Completed => 'gray',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Draft => Heroicon::PencilSquare,
            self::Shopping => Heroicon::ShoppingCart,
            self::Negotiating => Heroicon::ChatBubbleLeftRight,
            self::Finalizing => Heroicon::HandThumbUp,
            self::Payment => Heroicon::Banknotes,
            self::Ordering => Heroicon::PaperAirplane,
            self::Delivery => Heroicon::Truck,
            self::Pickup => Heroicon::ArchiveBoxArrowDown,
            self::Completed => Heroicon::CheckCircle,
            self::Cancelled => Heroicon::XCircle,
        };
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function isLocked(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Ordering, self::Delivery, self::Pickup], true);
    }

    /**
     * @return array<int, RoundPhase>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Shopping, self::Cancelled],
            self::Shopping => [self::Negotiating, self::Cancelled],
            self::Negotiating => [self::Shopping, self::Finalizing, self::Cancelled],
            self::Finalizing => [self::Payment, self::Negotiating, self::Cancelled],
            self::Payment => [self::Ordering, self::Cancelled],
            self::Ordering => [self::Delivery, self::Cancelled],
            self::Delivery => [self::Pickup],
            self::Pickup => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Reihenfolge im Fortschrittsbalken.
     */
    public function order(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Shopping => 1,
            self::Negotiating => 2,
            self::Finalizing => 3,
            self::Payment => 4,
            self::Ordering => 5,
            self::Delivery => 6,
            self::Pickup => 7,
            self::Completed => 8,
            self::Cancelled => 99,
        };
    }
}
