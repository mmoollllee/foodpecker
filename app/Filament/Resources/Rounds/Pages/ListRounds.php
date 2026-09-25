<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
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

    /**
     * A group runs one round at a time, so there is little to filter: the
     * running round with the own drafts, and everything that is over.
     */
    public function getTabs(): array
    {
        return [
            'aktuell' => Tab::make('Aktuell')
                ->modifyQueryUsing(fn (Builder $query) => $query->active()),
            'historie' => Tab::make('Historie')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('phase', [
                    RoundPhase::Completed->value,
                    RoundPhase::Cancelled->value,
                ])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading(fn (): string => $this->activeTab === 'historie'
                ? 'Noch keine abgeschlossene Runde'
                : 'Gerade läuft keine Bestellrunde')
            ->emptyStateDescription(fn (): string => $this->activeTab === 'historie'
                ? 'Abgeschlossene und abgebrochene Runden landen hier.'
                : 'Starte die nächste Runde — sie bleibt ein privater Entwurf, bis du sie startest.');
    }
}
