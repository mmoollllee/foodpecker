<?php

namespace App\Filament\Widgets;

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Group;
use App\Models\Round;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ActiveRounds extends BaseWidget
{
    protected static ?string $heading = 'Aktive Bestellrunden';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function getDescription(): ?string
    {
        return 'Wo gerade etwas passiert — Klick auf eine Zeile öffnet das Runden-Dashboard.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $tenant = Filament::getTenant();
                $userId = auth()->id();
                $query = Round::query()
                    ->whereNotIn('phase', [RoundPhase::Completed->value, RoundPhase::Cancelled->value])
                    ->where(function (Builder $q) use ($userId): void {
                        // Drafts nur für den eigenen Lead sichtbar
                        $q->where('phase', '!=', RoundPhase::Draft->value)
                            ->orWhere('lead_user_id', $userId);
                    })
                    ->latest('updated_at');
                if ($tenant instanceof Group) {
                    $query->where('group_id', $tenant->id);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('title')
                    ->label('Bestellrunde')
                    ->weight('semibold')
                    ->description(fn (Round $r) => 'Lead: '.($r->lead?->fullName() ?? '—')),
                TextColumn::make('phase')
                    ->label('Phase')
                    ->badge(),
                TextColumn::make('shopping_deadline')
                    ->label('Einkauf bis')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('payment_deadline')
                    ->label('Zahlung bis')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('expected_delivery')
                    ->label('Lieferung')
                    ->date('d.m.Y')
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Round $r) => RoundResource::getUrl('view', ['record' => $r]))
            ->paginated(false)
            ->emptyStateHeading('Keine aktive Runde')
            ->emptyStateDescription('Sobald jemand eine neue Bestellrunde startet, erscheint sie hier.');
    }
}
