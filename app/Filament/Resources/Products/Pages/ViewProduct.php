<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\InteractsWithNotesAndDocuments;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewProduct extends ViewRecord
{
    use InteractsWithNotesAndDocuments;

    protected static string $resource = ProductResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Bearbeiten')
                ->modalWidth('6xl')
                ->mutateDataUsing(fn (array $data, Product $record): array => ProductForm::prepareForSave($data, $record->group_id)),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.catalog.product-card'),
                Section::make('Tatsächlich bezahlte Preise')
                    ->description('Aus abgeschlossenen Bestellungen — bei geteilten Produkten auch aus anderen Gruppen.')
                    ->schema([View::make('filament.catalog.price-history')])
                    ->collapsible(),
                View::make('filament.partials.notes-and-documents')
                    ->viewData(['notesDescription' => 'Erfahrungen mit dem Produkt — z. B. „Diesmal etwas hart, lieber länger kochen“.']),
                Section::make('Verlauf')
                    ->schema([View::make('filament.catalog.activities')]),
            ]);
    }

    protected function annotatedRecord(): Model
    {
        return $this->getRecord();
    }

    protected function refreshAnnotations(): void
    {
        $this->getRecord()->refresh();
    }
}
