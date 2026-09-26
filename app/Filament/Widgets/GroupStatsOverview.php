<?php

namespace App\Filament\Widgets;

use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\Product;
use App\Models\Round;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GroupStatsOverview extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Group) {
            return [];
        }

        $completedRounds = Round::where('group_id', $tenant->id)
            ->where('phase', RoundPhase::Completed->value)
            ->count();

        $members = $tenant->members()->count();

        $visibleSuppliers = Supplier::visibleTo($tenant)->count();
        $visibleProducts = Product::visibleTo($tenant)->count();

        return [
            Stat::make('Abgeschlossene Runden', $completedRounds)
                ->description('in der Historie')
                ->descriptionIcon('heroicon-m-archive-box-arrow-down')
                ->color('success'),
            Stat::make('Mitglieder', $members)
                ->description('in dieser Gruppe')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
            Stat::make('Lieferanten & Produkte', $visibleSuppliers.' / '.$visibleProducts)
                ->description('verfügbar (privat + öffentlich)')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('gray'),
        ];
    }
}
