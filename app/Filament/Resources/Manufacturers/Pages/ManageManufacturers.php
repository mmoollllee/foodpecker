<?php

namespace App\Filament\Resources\Manufacturers\Pages;

use App\Filament\Resources\Manufacturers\ManufacturerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageManufacturers extends ManageRecords
{
    protected static string $resource = ManufacturerResource::class;

    public function getTitle(): string
    {
        return 'Hersteller';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Hersteller anlegen')
                ->slideOver()
                ->mutateDataUsing(fn (array $data) => ManufacturerResource::mutateFormDataBeforeCreate($data)),
        ];
    }
}
