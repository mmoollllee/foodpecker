<?php

namespace App\Filament\Resources\Manufacturers\Pages;

use App\Filament\Concerns\InteractsWithNotesAndDocuments;
use App\Filament\Resources\Manufacturers\ManufacturerResource;
use App\Models\Manufacturer;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewManufacturer extends ViewRecord
{
    use InteractsWithNotesAndDocuments;

    protected static string $resource = ManufacturerResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Bearbeiten')
                ->modalWidth('4xl')
                ->mutateDataUsing(fn (array $data, Manufacturer $record): array => [
                    ...$data,
                    'group_id' => $record->group_id ?? Filament::getTenant()?->getKey(),
                ]),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Hersteller')
                    ->schema([
                        TextEntry::make('visibility')->label('Sichtbarkeit')->badge(),
                        TextEntry::make('group.name')->label('Gehört zu')->placeholder('—'),
                        TextEntry::make('website')->label('Website')->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—'),
                        TextEntry::make('contact_email')->label('E-Mail')->copyable()->placeholder('—'),
                        TextEntry::make('contact_phone')->label('Telefon')->placeholder('—'),
                        TextEntry::make('address')->label('Adresse')->placeholder('—'),
                        TextEntry::make('shipping_notes')->label('Versandhinweise')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('description')->label('Beschreibung')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Lists the products right below the manufacturer, before notes and history.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getInfolistContentComponent(),
                $this->getRelationManagersContentComponent(),
                View::make('filament.partials.notes-and-documents')
                    ->viewData(['notesDescription' => 'Erfahrungen mit dem Hersteller — z. B. „Nächstes Mal früher anfragen, war im November knapp“.']),
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
