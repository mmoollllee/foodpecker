<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProducts extends ManageRecords
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return 'Produkte';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Produkt anlegen')
                ->modalHeading('Neues Produkt anlegen')
                ->modalDescription('In vier Schritten zum neuen Produkt — Stammdaten, Verteilung, Gebinde & Preise, Beschreibung.')
                ->modalWidth('5xl')
                ->steps(ProductForm::wizardSteps())
                ->mutateDataUsing(fn (array $data): array => [
                    ...ProductForm::prepareForSave($data),
                    'created_by_user_id' => auth()->id(),
                ]),
        ];
    }
}
