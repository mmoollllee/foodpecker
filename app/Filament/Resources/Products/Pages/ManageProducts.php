<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\PackagingStrategy;
use App\Enums\ProductCategory;
use App\Enums\Visibility;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Manufacturer;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;

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
                ->modalDescription('In drei Schritten zum neuen Produkt — Stammdaten, Verpackungs-Logik, Preisstaffeln.')
                ->modalWidth('5xl')
                ->steps([
                    Step::make('Stammdaten')
                        ->description('Was bestellst du, von wem und in welcher Einheit?')
                        ->icon(Heroicon::OutlinedCube)
                        ->schema([
                            Select::make('manufacturer_id')
                                ->label('Hersteller')
                                ->options(fn () => Manufacturer::visibleTo(Filament::getTenant())->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->required()
                                ->helperText('Bei welchem Hersteller bestellt ihr dieses Produkt? Falls neu, erst unter „Hersteller" anlegen.'),
                            TextInput::make('name')
                                ->label('Produktname')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('z. B. „Bio Basmati Reis"')
                                ->helperText('So nennt der Hersteller dieses Produkt.'),
                            Select::make('unit')
                                ->label('Einheit')
                                ->options([
                                    'kg' => 'Kilogramm (kg)',
                                    'g' => 'Gramm (g)',
                                    'l' => 'Liter (l)',
                                    'ml' => 'Milliliter (ml)',
                                    'stk' => 'Stück',
                                    'glas' => 'Glas',
                                    'pkg' => 'Packung',
                                ])
                                ->default('kg')
                                ->required()
                                ->helperText('In welcher Einheit denkt ihr beim Bestellen? Meist Kilogramm, manchmal Stück oder Glas.'),
                            Select::make('category')
                                ->label('Kategorie')
                                ->options(ProductCategory::class)
                                ->searchable()
                                ->helperText('Gruppiert die Produktauswahl im Warenkorb.'),
                            Select::make('visibility')
                                ->label('Sichtbarkeit')
                                ->options(Visibility::class)
                                ->default(Visibility::Private->value)
                                ->required()
                                ->helperText('Öffentliche Produkte können von allen Foodpecker-Gruppen verwendet werden. Vorausgesetzt: der Hersteller ist auch öffentlich.'),
                        ])
                        ->columns(2),

                    Step::make('Verpackungs-Logik')
                        ->description('Wie kommt das Produkt zu euch — und wie könnt ihr es aufteilen?')
                        ->icon(Heroicon::OutlinedSquares2x2)
                        ->schema([
                            Radio::make('packaging_strategy')
                                ->label('Welche Verpackungs-Logik passt am besten?')
                                ->options(collect(PackagingStrategy::cases())
                                    ->mapWithKeys(fn (PackagingStrategy $s) => [$s->value => $s->getLabel()])
                                    ->all())
                                ->descriptions(collect(PackagingStrategy::cases())
                                    ->mapWithKeys(fn (PackagingStrategy $s) => [$s->value => $s->getDescription()])
                                    ->all())
                                ->default(PackagingStrategy::Tiered->value)
                                ->required()
                                ->columnSpanFull(),
                        ]),

                    Step::make('Preisstaffeln')
                        ->description('Welche Gebindegrößen und Preise bietet der Hersteller an?')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->schema([
                            TextInput::make('estimated_price_cents')
                                ->label('Geschätzter Preis pro Standard-Gebinde (in Cent)')
                                ->numeric()
                                ->suffix('Cent')
                                ->helperText('Grobe Hausnummer, z. B. 2499 für 24,99 €. Nach abgeschlossenen Bestellungen fließen die echten Preise zurück.'),
                            Repeater::make('priceTiers')
                                ->relationship()
                                ->label('Gebindegrößen / Preisstaffeln')
                                ->hiddenLabel()
                                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                                ->collapsible()
                                ->schema([
                                    TextInput::make('label')
                                        ->label('Label')
                                        ->placeholder('z. B. „25 kg Sack"')
                                        ->required()
                                        ->columnSpan(2),
                                    TextInput::make('package_amount')
                                        ->label('Gebinde-Menge')
                                        ->numeric()
                                        ->required()
                                        ->step(0.001)
                                        ->minValue(0.001)
                                        ->helperText('In der oben gewählten Einheit.'),
                                    TextInput::make('price_cents')
                                        ->label('Preis pro Gebinde')
                                        ->numeric()
                                        ->required()
                                        ->suffix('Cent'),
                                    TextInput::make('min_order_packages')
                                        ->label('Mindestbestellmenge')
                                        ->numeric()
                                        ->default(1)
                                        ->suffix('Gebinde'),
                                    Toggle::make('is_divisible')
                                        ->label('Innerhalb der Gruppe teilbar?')
                                        ->default(true)
                                        ->helperText('Kann ein Gebinde zwischen Teilnehmern aufgeteilt werden? Bei „Spaghetti 2 kg" meist nein.')
                                        ->live(),
                                    TextInput::make('divisible_step')
                                        ->label('Teilschritt')
                                        ->numeric()
                                        ->step(0.001)
                                        ->placeholder('z. B. 0.5')
                                        ->helperText('In welchen Schritten teilbar? Leer = volle Einheit (z. B. 1 Glas).')
                                        ->visible(fn (Get $get) => $get('is_divisible')),
                                ])
                                ->columns(3)
                                ->defaultItems(1)
                                ->addActionLabel('Weitere Gebindegröße hinzufügen')
                                ->helperText('Reis kannst du z. B. in 10 kg, 25 kg und 50 kg Säcken bestellen — eine Zeile pro Größe.')
                                ->columnSpanFull(),
                        ]),

                    Step::make('Beschreibung & Bild')
                        ->description('Optional, aber hilfreich für die Mitglieder')
                        ->icon(Heroicon::OutlinedPhoto)
                        ->schema([
                            Textarea::make('description')
                                ->label('Beschreibung')
                                ->placeholder('Worauf solltet ihr beim Bestellen achten? Geschmack, Anwendung, Besonderheiten…')
                                ->rows(4)
                                ->helperText('Wird in der Produkt-Karte angezeigt — gibt Mitgliedern Kontext bevor sie etwas in den Warenkorb legen.'),
                            FileUpload::make('image_path')
                                ->label('Produktbild')
                                ->image()
                                ->disk('public')
                                ->directory('products')
                                ->visibility('public')
                                ->imageEditor()
                                ->imageCropAspectRatio('1:1')
                                ->maxSize(5120)
                                ->helperText('Quadratisches Bild funktioniert am besten (z. B. ein Foto vom Hersteller).'),
                        ]),
                ])
                ->mutateDataUsing(fn (array $data) => ProductResource::mutateFormDataBeforeCreate($data)),
        ];
    }
}
