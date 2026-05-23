<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRounds extends ListRecords
{
    protected static string $resource = RoundResource::class;

    public function getTitle(): string
    {
        return 'Bestellrunden';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Neue Runde starten')
                ->icon('heroicon-o-plus'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'aktiv' => Tab::make('Aktiv')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('phase', [
                    RoundPhase::Completed->value,
                    RoundPhase::Cancelled->value,
                ])),
            'shopping' => Tab::make('Im Einkauf')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('phase', RoundPhase::Shopping->value)),
            'finalizing' => Tab::make('Zur Abstimmung')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('phase', RoundPhase::Finalizing->value)),
            'completed' => Tab::make('Historie')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('phase', RoundPhase::Completed->value)),
            'all' => Tab::make('Alle'),
        ];
    }
}
