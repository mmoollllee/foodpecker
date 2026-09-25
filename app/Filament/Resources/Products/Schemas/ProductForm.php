<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\PackagingStrategy;
use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Enums\Visibility;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\Group;
use App\Models\Manufacturer;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Fields of a product, shared by the creation wizard and the edit modal.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Produkt')
                ->schema(static::masterDataFields())
                ->columns(2),
            Section::make('Verpackungs-Logik')
                ->schema([static::packagingField()])
                ->collapsible(),
            Section::make('Preisstaffeln / Gebindegrößen')
                ->schema(static::pricingFields())
                ->collapsible(),
            Section::make('Beschreibung & Bild')
                ->schema(static::descriptionFields())
                ->collapsed()
                ->collapsible(),
        ]);
    }

    /**
     * @param  Manufacturer|null  $manufacturer  Fixes the manufacturer, e.g. when creating from its page.
     * @return array<int, Step>
     */
    public static function wizardSteps(?Manufacturer $manufacturer = null): array
    {
        return [
            Step::make('Stammdaten')
                ->description('Was bestellt ihr, von wem und in welcher Einheit?')
                ->icon(Heroicon::OutlinedCube)
                ->schema(static::masterDataFields($manufacturer))
                ->columns(2),
            Step::make('Verpackungs-Logik')
                ->description('Wie kommt das Produkt zu euch — und wie könnt ihr es aufteilen?')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->schema([static::packagingField()]),
            Step::make('Preisstaffeln')
                ->description('Welche Gebindegrößen und Preise bietet der Hersteller an?')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema(static::pricingFields()),
            Step::make('Beschreibung & Bild')
                ->description('Optional, aber hilfreich für die Mitglieder')
                ->icon(Heroicon::OutlinedPhoto)
                ->schema(static::descriptionFields()),
        ];
    }

    /**
     * @param  Manufacturer|null  $manufacturer  Fixes the manufacturer, e.g. when creating from its page.
     * @return array<int, mixed>
     */
    public static function masterDataFields(?Manufacturer $manufacturer = null): array
    {
        return [
            Select::make('manufacturer_id')
                ->label('Hersteller')
                ->options(fn (): array => Manufacturer::visibleTo(static::currentGroup())->orderBy('name')->pluck('name', 'id')->all())
                ->default($manufacturer?->getKey())
                ->disabled($manufacturer !== null)
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->helperText($manufacturer === null ? 'Bei welchem Hersteller bestellt ihr dieses Produkt? Falls neu, erst unter „Hersteller“ anlegen.' : null),
            TextInput::make('name')
                ->label('Produktname')
                ->required()
                ->maxLength(255)
                ->placeholder('z. B. „Bio Basmati Reis“'),
            Select::make('unit')
                ->label('Einheit')
                ->options(ProductUnit::class)
                ->default(ProductUnit::Kilogram->value)
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
                ->disableOptionWhen(fn (string $value, Get $get): bool => $value === Visibility::Public->value
                    && ! static::manufacturerIsPublic($get('manufacturer_id')))
                ->helperText('Öffentliche Produkte können alle Foodpecker-Gruppen nutzen — das geht nur bei öffentlichen Herstellern. Ändern kann sie nur eure Gruppe.'),
        ];
    }

    public static function packagingField(): Radio
    {
        return Radio::make('packaging_strategy')
            ->label('Welche Verpackungs-Logik passt am besten?')
            ->options(PackagingStrategy::class)
            ->descriptions(collect(PackagingStrategy::cases())
                ->mapWithKeys(fn (PackagingStrategy $strategy): array => [$strategy->value => $strategy->getDescription()])
                ->all())
            ->default(PackagingStrategy::Tiered->value)
            ->required()
            ->columnSpanFull();
    }

    /**
     * @return array<int, mixed>
     */
    public static function pricingFields(): array
    {
        return [
            MoneyInput::make('estimated_price_cents')
                ->label('Geschätzter Preis pro Standard-Gebinde (optional)')
                ->helperText('Grobe Hausnummer. Nach abgeschlossenen Bestellungen zeigt die Produktkarte zusätzlich die tatsächlich gezahlten Preise.'),
            Repeater::make('priceTiers')
                ->relationship()
                ->label('Gebindegrößen / Preisstaffeln')
                ->orderColumn('sort_order')
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->collapsible()
                ->cloneable()
                ->schema([
                    TextInput::make('label')
                        ->label('Bezeichnung')
                        ->placeholder('z. B. „25 kg Sack“')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                    TextInput::make('package_amount')
                        ->label('Menge pro Gebinde')
                        ->numeric()
                        ->required()
                        ->step(0.001)
                        ->minValue(0.001)
                        ->helperText('In der oben gewählten Einheit.'),
                    MoneyInput::make('price_cents')
                        ->label('Preis pro Gebinde')
                        ->required(),
                    TextInput::make('min_order_packages')
                        ->label('Mindestbestellmenge')
                        ->integer()
                        ->minValue(1)
                        ->default(1)
                        ->suffix('Gebinde'),
                    Toggle::make('is_divisible')
                        ->label('Innerhalb der Gruppe teilbar?')
                        ->default(true)
                        ->live()
                        ->inline(false)
                        ->helperText('Bei „Spaghetti 2 kg“ meist nein: jede Packung geht ganz an eine Person.'),
                    TextInput::make('divisible_step')
                        ->label('Teilschritt')
                        ->numeric()
                        ->step(0.001)
                        ->minValue(0.001)
                        ->placeholder('z. B. 0,5')
                        ->helperText('In welchen Schritten teilbar? Leer = ganzes Gebinde.')
                        ->visible(fn (Get $get): bool => (bool) $get('is_divisible')),
                ])
                ->columns(3)
                ->defaultItems(1)
                ->minItems(1)
                ->addActionLabel('Weitere Gebindegröße hinzufügen')
                ->helperText('Reis gibt es z. B. in 10, 25 und 50 kg Säcken — eine Zeile pro Größe.')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function descriptionFields(): array
    {
        return [
            Textarea::make('description')
                ->label('Beschreibung')
                ->placeholder('Worauf solltet ihr beim Bestellen achten? Geschmack, Anwendung, Besonderheiten…')
                ->rows(4)
                ->maxLength(5000)
                ->columnSpanFull(),
            FileUpload::make('image_path')
                ->label('Produktbild (optional)')
                ->image()
                ->disk('public')
                ->directory('products')
                ->visibility('public')
                ->imageEditor()
                ->imageCropAspectRatio('1:1')
                ->maxSize(5120)
                ->helperText('Quadratische Bilder funktionieren am besten.'),
        ];
    }

    /**
     * Assigns the owning group (the current one for new products) and
     * enforces "public only with a public manufacturer" on the server too.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareForSave(array $data, ?int $owningGroupId = null): array
    {
        $data['group_id'] = $owningGroupId ?? static::currentGroup()?->id;

        if (! static::manufacturerIsPublic($data['manufacturer_id'] ?? null)) {
            $data['visibility'] = Visibility::Private->value;
        }

        return $data;
    }

    private static function manufacturerIsPublic(mixed $manufacturerId): bool
    {
        return filled($manufacturerId)
            && Manufacturer::query()->whereKey((int) $manufacturerId)->where('visibility', Visibility::Public->value)->exists();
    }

    private static function currentGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }
}
