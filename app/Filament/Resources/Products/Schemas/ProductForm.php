<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Enums\Visibility;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Group;
use App\Models\Product;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

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
            Section::make('Verteilung')
                ->schema(static::distributionFields())
                ->columns(2)
                ->collapsible(),
            Section::make('Gebinde & Preise')
                ->schema(static::packageFields())
                ->collapsible(),
            Section::make('Beschreibung & Bild')
                ->schema(static::descriptionFields())
                ->collapsed()
                ->collapsible(),
        ]);
    }

    /**
     * @param  Supplier|null  $supplier  Fixes the supplier, e.g. when creating from its page.
     * @return array<int, Step>
     */
    public static function wizardSteps(?Supplier $supplier = null): array
    {
        return [
            Step::make('Stammdaten')
                ->description('Was bestellt ihr, bei wem und in welcher Einheit?')
                ->icon(Heroicon::OutlinedCube)
                ->schema(static::masterDataFields($supplier))
                ->columns(2),
            Step::make('Verteilung')
                ->description('Wie wird eine Packung unter euch aufgeteilt?')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->schema(static::distributionFields())
                ->columns(2),
            Step::make('Gebinde & Preise')
                ->description('Welche Größen und Preise bietet der Lieferant an?')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema(static::packageFields()),
            Step::make('Beschreibung & Bild')
                ->description('Optional, aber hilfreich für die Mitglieder')
                ->icon(Heroicon::OutlinedPhoto)
                ->schema(static::descriptionFields()),
        ];
    }

    /**
     * @param  Supplier|null  $supplier  Fixes the supplier, e.g. when creating from its page.
     * @return array<int, mixed>
     */
    public static function masterDataFields(?Supplier $supplier = null): array
    {
        return [
            Select::make('supplier_id')
                ->label('Lieferant')
                ->options(fn (): array => Supplier::visibleTo(static::currentGroup())->orderBy('name')->pluck('name', 'id')->all())
                ->default($supplier?->getKey())
                ->disabled($supplier !== null)
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->createOptionForm($supplier === null && auth()->user()?->can('create', Supplier::class)
                    ? SupplierResource::formComponents()
                    : null)
                ->createOptionModalHeading('Neuen Lieferanten anlegen')
                ->createOptionUsing(fn (array $data): int => Supplier::create(SupplierResource::mutateFormDataBeforeCreate($data))->getKey())
                ->helperText($supplier === null ? 'Bei welchem Lieferanten bestellt ihr dieses Produkt? Ein neuer ist über das Plus schnell angelegt.' : null),
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
                ->live()
                ->helperText('In welcher Einheit denkt ihr beim Bestellen? Meist Kilogramm, manchmal Stück oder Glas.'),
            Select::make('category')
                ->label('Kategorie')
                ->options(ProductCategory::class)
                ->searchable()
                ->helperText('Gruppiert die Produkte beim Einkaufen.'),
            Select::make('visibility')
                ->label('Sichtbarkeit')
                ->options(Visibility::class)
                ->default(Visibility::Private->value)
                ->required()
                ->disableOptionWhen(fn (string $value, Get $get): bool => $value === Visibility::Public->value
                    && ! static::supplierIsPublic($get('supplier_id')))
                ->helperText('Öffentliche Produkte können alle Foodpecker-Gruppen nutzen — das geht nur bei öffentlichen Lieferanten. Ändern kann sie nur eure Gruppe.'),
        ];
    }

    /**
     * Whether packages are shared out in portions or go whole to one person.
     *
     * @return array<int, mixed>
     */
    public static function distributionFields(): array
    {
        return [
            Radio::make('is_portioned')
                ->label('Wie wird eine Packung verteilt?')
                ->options([
                    '1' => 'In Portionen — abwiegen oder abzählen',
                    '0' => 'Nur ganze Packungen',
                ])
                ->descriptions([
                    '1' => 'Ein Sack, eine Kiste oder ein Karton wird unter mehreren aufgeteilt — z. B. Mehl, Reis oder Senf aus dem 12er-Karton.',
                    '0' => 'Jede Packung geht ungeöffnet an eine Person — z. B. Spaghetti-Beutel.',
                ])
                ->formatStateUsing(fn (mixed $state, ?Product $record): string => $record === null || $record->isPortioned() ? '1' : '0')
                ->required()
                ->live(),
            TextInput::make('portion_size')
                ->label('Kleinste Portion')
                ->numeric()
                ->minValue(0.001)
                ->step(0.001)
                ->default(0.5)
                ->suffix(fn (Get $get): ?string => static::unitLabel($get('unit')))
                ->required(fn (Get $get): bool => static::isPortioned($get))
                ->visible(fn (Get $get): bool => static::isPortioned($get))
                ->helperText('Jede Person bekommt ein Vielfaches davon — z. B. 0,5 kg aus dem 25-kg-Sack oder 1 Glas aus dem 12er-Karton.'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function packageFields(): array
    {
        return [
            Repeater::make('priceTiers')
                ->relationship()
                ->label('Gebindegrößen')
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
                        ->label('Inhalt')
                        ->numeric()
                        ->required()
                        ->step(0.001)
                        ->minValue(0.001)
                        ->suffix(fn (Get $get): ?string => static::unitLabel($get('../../unit'))),
                    MoneyInput::make('price_cents')
                        ->label('Preis pro Gebinde')
                        ->required(),
                    TextInput::make('article_number')
                        ->label('Artikelnummer (optional)')
                        ->placeholder('beim Lieferanten')
                        ->maxLength(64),
                    TextInput::make('min_order_packages')
                        ->label('Mindestbestellmenge')
                        ->integer()
                        ->minValue(1)
                        ->default(1)
                        ->suffix('Gebinde'),
                ])
                ->columns(3)
                ->defaultItems(1)
                ->minItems(1)
                ->addActionLabel('Weitere Gebindegröße hinzufügen')
                ->helperText('Reis gibt es z. B. in 10, 25 und 50 kg Säcken — eine Zeile pro Größe. Foodpecker kombiniert die Größen später so, dass es für alle am günstigsten wird.')
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
     * Assigns the owning group (the current one for new products), turns
     * the distribution choice into the portion size and enforces "public
     * only with a public supplier" on the server too.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareForSave(array $data, ?int $owningGroupId = null): array
    {
        $data['group_id'] = $owningGroupId ?? static::currentGroup()?->id;

        if ((string) Arr::pull($data, 'is_portioned', '1') !== '1') {
            $data['portion_size'] = null;
        }

        if (! static::supplierIsPublic($data['supplier_id'] ?? null)) {
            $data['visibility'] = Visibility::Private->value;
        }

        return $data;
    }

    /**
     * The radio's state comes back cast to an integer, the raw form data as
     * the option's string key.
     */
    private static function isPortioned(Get $get): bool
    {
        return (string) $get('is_portioned') === '1';
    }

    private static function unitLabel(mixed $unit): ?string
    {
        $unit = $unit instanceof ProductUnit ? $unit : ProductUnit::tryFrom((string) $unit);

        return $unit?->shortLabel();
    }

    private static function supplierIsPublic(mixed $supplierId): bool
    {
        return filled($supplierId)
            && Supplier::query()->whereKey((int) $supplierId)->where('visibility', Visibility::Public->value)->exists();
    }

    private static function currentGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }
}
