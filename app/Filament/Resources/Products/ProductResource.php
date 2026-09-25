<?php

namespace App\Filament\Resources\Products;

use App\Enums\PackagingStrategy;
use App\Enums\ProductCategory;
use App\Enums\Visibility;
use App\Filament\Resources\Manufacturers\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Produkte';

    protected static ?string $modelLabel = 'Produkt';

    protected static ?string $pluralModelLabel = 'Produkte';

    protected static string|\UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 11;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Produkt')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Product $record, HasTable $livewire): ?string => $livewire instanceof ProductsRelationManager ? null : $record->manufacturer?->name),
                TextColumn::make('category')
                    ->label('Kategorie')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit')
                    ->label('Einh.')
                    ->formatStateUsing(fn (Product $record): string => $record->unitLabel())
                    ->color('gray'),
                TextColumn::make('packaging_strategy')
                    ->label('Verpackung')
                    ->badge(),
                TextColumn::make('visibility')
                    ->label('Sichtbarkeit')
                    ->badge(),
                TextColumn::make('priceTiers.label')
                    ->label('Gebinde')
                    ->badge()
                    ->color('info')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('estimated_price_cents')
                    ->label('Richtwert')
                    ->state(fn (Product $record): ?string => $record->formattedEstimatedPrice())
                    ->sortable()
                    ->color('warning'),
                TextColumn::make('group.name')
                    ->label('Gehört zu')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Product $record): string => static::getUrl('view', ['record' => $record]))
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategorie')
                    ->options(ProductCategory::class)
                    ->multiple(),
                SelectFilter::make('visibility')
                    ->label('Sichtbarkeit')
                    ->options(Visibility::class),
                SelectFilter::make('packaging_strategy')
                    ->label('Verpackung')
                    ->options(PackagingStrategy::class),
                SelectFilter::make('manufacturer_id')
                    ->label('Hersteller')
                    ->relationship('manufacturer', 'name')
                    ->searchable()
                    ->preload()
                    ->hiddenOn(ProductsRelationManager::class),
                TrashedFilter::make()
                    ->label('Archiv')
                    ->placeholder('Ohne archivierte')
                    ->trueLabel('Mit archivierten')
                    ->falseLabel('Nur archivierte'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ansehen')
                    ->url(fn (Product $record): string => static::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label('Bearbeiten')
                    ->modalWidth('6xl')
                    ->mutateDataUsing(fn (array $data, Product $record): array => ProductForm::prepareForSave($data, $record->group_id)),
                DeleteAction::make()
                    ->label('Archivieren')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->modalHeading('Produkt archivieren?')
                    ->modalDescription('Archivierte Produkte tauchen in neuen Runden nicht mehr auf. Bestehende Warenkörbe, Vorschläge und die Preis-Historie bleiben erhalten.')
                    ->modalSubmitActionLabel('Archivieren')
                    ->successNotificationTitle('Produkt archiviert'),
                RestoreAction::make()
                    ->label('Wiederherstellen'),
                ForceDeleteAction::make()
                    ->label('Endgültig löschen')
                    ->modalDescription('Nur möglich, solange das Produkt nie bestellt wurde.'),
            ])
            ->emptyStateHeading('Noch keine Produkte')
            ->emptyStateDescription('Lege zuerst Hersteller an, dann die zugehörigen Produkte mit ihren Preisstaffeln.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleTo(Filament::getTenant())
            ->with('manufacturer', 'priceTiers');
    }

    /**
     * Archived products stay viewable, e.g. from an old order.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record?->name;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
        ];
    }
}
