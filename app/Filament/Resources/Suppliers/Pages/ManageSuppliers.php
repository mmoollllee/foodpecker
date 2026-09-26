<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    public function getTitle(): string
    {
        return 'Lieferanten';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Lieferant anlegen')
                ->modalHeading('Neuen Lieferanten anlegen')
                ->modalWidth('4xl')
                ->mutateDataUsing(fn (array $data) => SupplierResource::mutateFormDataBeforeCreate($data)),
        ];
    }
}
