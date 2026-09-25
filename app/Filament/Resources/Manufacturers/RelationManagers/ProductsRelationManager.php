<?php

namespace App\Filament\Resources\Manufacturers\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The products of a manufacturer, with the table, form and permissions of
 * the product resource.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $relatedResource = ProductResource::class;

    /**
     * The manufacturer's view page is where its products are maintained,
     * so the actions stay available there.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->visibleTo(Filament::getTenant()))
            ->headerActions([
                CreateAction::make()
                    ->label('Produkt anlegen')
                    ->modalHeading('Neues Produkt anlegen')
                    ->modalDescription('In vier Schritten zum neuen Produkt — Stammdaten, Verpackungs-Logik, Preisstaffeln, Beschreibung.')
                    ->modalWidth('5xl')
                    ->steps(ProductForm::wizardSteps($this->getOwnerRecord()))
                    ->mutateDataUsing(fn (array $data): array => [
                        ...ProductForm::prepareForSave([...$data, 'manufacturer_id' => $this->getOwnerRecord()->getKey()]),
                        'created_by_user_id' => auth()->id(),
                    ]),
            ])
            ->emptyStateDescription('Lege das erste Produkt dieses Herstellers mit seinen Preisstaffeln an.');
    }
}
