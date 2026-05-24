<?php

namespace App\Filament\Widgets;

use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Round;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GroupStatsOverview extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Group) {
            return [];
        }

        $userId = auth()->id();
        $activeRounds = Round::where('group_id', $tenant->id)
            ->whereNotIn('phase', [RoundPhase::Completed->value, RoundPhase::Cancelled->value])
            ->where(function ($q) use ($userId): void {
                $q->where('phase', '!=', RoundPhase::Draft->value)
                    ->orWhere('lead_user_id', $userId);
            })
            ->count();

        $completedRounds = Round::where('group_id', $tenant->id)
            ->where('phase', RoundPhase::Completed->value)
            ->count();

        $members = $tenant->members()->count() + 1; // + Owner

        $visibleManufacturers = Manufacturer::visibleTo($tenant)->count();
        $visibleProducts = Product::visibleTo($tenant)->count();

        return [
            Stat::make('Aktive Runden', $activeRounds)
                ->description($activeRounds === 0 ? 'Starte gleich eine neue!' : 'in Bearbeitung')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color($activeRounds > 0 ? 'warning' : 'gray'),
            Stat::make('Abgeschlossene Runden', $completedRounds)
                ->description('in der Historie')
                ->descriptionIcon('heroicon-m-archive-box-arrow-down')
                ->color('success'),
            Stat::make('Mitglieder', $members)
                ->description('in dieser Gruppe')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
            Stat::make('Hersteller & Produkte', $visibleManufacturers.' / '.$visibleProducts)
                ->description('verfügbar (privat + öffentlich)')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('gray'),
        ];
    }
}
