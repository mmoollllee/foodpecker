<?php

namespace App\Filament\Resources\Rounds\Tables;

use App\Enums\RoundPhase;
use App\Models\Round;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->state(fn (Round $r) => $r->participants()->count())
                    ->badge()
                    ->color('info'),
                TextColumn::make('cart_items_count')
                    ->label('Artikel')
                    ->state(fn (Round $r) => $r->cartItems()->count())
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
                DeleteAction::make()->label('Löschen'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Noch keine Bestellrunden')
            ->emptyStateDescription('Starte eine neue Runde — die Einkaufsphase ist dann sofort offen.');
    }
}
