<?php

namespace App\Filament\Resources\Rounds\Tables;

use App\Enums\RoundPhase;
use App\Models\Round;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RoundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('lead')
                ->withCount(['activeParticipants as participants_count', 'cartItems']))
            ->columns([
                TextColumn::make('title')
                    ->label('Bestellrunde')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Round $r) => 'Lead: '.($r->lead?->fullName() ?? '—')),
                TextColumn::make('phase')
                    ->label('Phase')
                    ->badge(),
                TextColumn::make('participants_count')
                    ->label('Teilnehmer')
                    ->badge()
                    ->color('info'),
                TextColumn::make('cart_items_count')
                    ->label('Artikel')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('shopping_deadline')
                    ->label('Einkauf bis')
                    ->date('d.m.Y')
                    ->color('gray'),
                TextColumn::make('payment_deadline')
                    ->label('Zahlung bis')
                    ->date('d.m.Y')
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('expected_delivery')
                    ->label('Lieferung')
                    ->date('d.m.Y')
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Aktiv.')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('phase')
                    ->label('Phase')
                    ->options(RoundPhase::class)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make()->label('Öffnen'),
                EditAction::make()->label('Bearbeiten')->slideOver(),
                DeleteAction::make()
                    ->label('Löschen')
                    ->modalDescription('Nur Entwürfe und abgebrochene Runden können gelöscht werden.'),
            ]);
    }
}
