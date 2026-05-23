<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
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
                ->slideOver()
                ->mutateDataUsing(fn (array $data) => ProductResource::mutateFormDataBeforeCreate($data)),
        ];
    }
}
