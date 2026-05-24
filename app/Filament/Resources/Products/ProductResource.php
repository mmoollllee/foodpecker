<?php

namespace App\Filament\Resources\Products;

use App\Enums\PackagingStrategy;
use App\Enums\Visibility;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Manufacturer;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Produkte';

    protected static ?string $modelLabel = 'Produkt';

    protected static ?string $pluralModelLabel = 'Produkte';

    protected static string|\UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 11;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Produkt')
                ->schema([
                    Select::make('manufacturer_id')
                        ->label('Hersteller')
                        ->options(fn () => Manufacturer::visibleTo(Filament::getTenant())->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('name')
                        ->label('Produktname')
                        ->required()
                        ->maxLength(255),
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
                        ->required(),
                    Select::make('packaging_strategy')
                        ->label('Verpackungs-Logik')
                        ->options(PackagingStrategy::class)
                        ->default(PackagingStrategy::Tiered->value)
                        ->required()
                        ->helperText('Bestimmt, wie das Produkt aufgeteilt werden kann.')
                        ->columnSpanFull(),
                    Select::make('visibility')
                        ->label('Sichtbarkeit')
                        ->options(Visibility::class)
                        ->default(Visibility::Private->value)
                        ->required(),
                    TextInput::make('estimated_price_cents')
                        ->label('Geschätzter Preis (Cent)')
                        ->numeric()
                        ->suffix('Cent')
                        ->helperText('z. B. 2499 = 24,99 € pro typisches Gebinde'),
                ])->columns(3),

            Section::make('Preisstaffeln / Gebindegrößen')
                ->description('Eine Zeile pro verfügbarer Gebindegröße. Mengenstaffeln (Reis 10/25/50 kg), feste Größen (Dinkelmehl 25/50 kg), Paletten-Schritte (Senf 12er) oder Großgebinde (Spirelli 5 kg).')
                ->schema([
                    Repeater::make('priceTiers')
                        ->relationship()
                        ->hiddenLabel()
                        ->orderColumn('sort_order')
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                        ->collapsible()
                        ->cloneable()
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
                                ->columnSpan(1),
                            TextInput::make('price_cents')
                                ->label('Preis')
                                ->numeric()
                                ->required()
                                ->suffix('Cent')
                                ->columnSpan(1),
                            TextInput::make('min_order_packages')
                                ->label('Min. Gebinde')
                                ->numeric()
                                ->default(1)
                                ->columnSpan(1),
                            Toggle::make('is_divisible')
                                ->label('Innerhalb der Gruppe teilbar?')
                                ->default(true)
                                ->live()
                                ->columnSpan(2)
                                ->inline(false),
                            TextInput::make('divisible_step')
                                ->label('Teilschritt')
                                ->numeric()
                                ->step(0.001)
                                ->placeholder('z. B. 0.5')
                                ->visible(fn ($get) => $get('is_divisible'))
                                ->columnSpan(2),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->addActionLabel('Weitere Größe hinzufügen'),
                ]),

            Section::make('Beschreibung')
                ->schema([
                    Textarea::make('description')
                        ->label('Beschreibung')
                        ->rows(4),
                ])
                ->collapsed()
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Produkt')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Product $r) => $r->manufacturer?->name),
                TextColumn::make('unit')
                    ->label('Einh.')
                    ->color('gray'),
                TextColumn::make('packaging_strategy')
                    ->label('Verpackung')
                    ->badge(),
                TextColumn::make('visibility')
                    ->label('Sichtbarkeit')
                    ->badge(),
                TextColumn::make('priceTiers.label')
                    ->label('Gebinde')
                    ->badge()
                    ->color('info')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('estimated_price_cents')
                    ->label('Richtwert')
                    ->state(fn (Product $r) => $r->formattedEstimatedPrice())
                    ->sortable()
                    ->color('warning'),
                TextColumn::make('group.name')
                    ->label('Gruppe')
                    ->placeholder('— öffentlich —')
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('visibility')
                    ->label('Sichtbarkeit')
                    ->options(Visibility::class),
                SelectFilter::make('packaging_strategy')
                    ->label('Verpackung')
                    ->options(PackagingStrategy::class),
                SelectFilter::make('manufacturer_id')
                    ->label('Hersteller')
                    ->relationship('manufacturer', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make()->label('Ansehen')->slideOver(),
                EditAction::make()->label('Bearbeiten')->modalWidth('6xl'),
                DeleteAction::make()->label('Löschen'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Noch keine Produkte')
            ->emptyStateDescription('Lege zuerst Hersteller an, dann die zugehörigen Produkte mit ihren Preisstaffeln.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleTo(Filament::getTenant())
            ->with('manufacturer', 'priceTiers');
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['created_by_user_id'] = auth()->id();
        if (($data['visibility'] ?? Visibility::Private->value) === Visibility::Public->value) {
            $data['group_id'] = null;
        } else {
            $data['group_id'] = $tenant?->id;
        }

        return $data;
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record?->name;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProducts::route('/'),
        ];
    }
}
