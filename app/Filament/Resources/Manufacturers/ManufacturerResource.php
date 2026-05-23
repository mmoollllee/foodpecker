<?php

namespace App\Filament\Resources\Manufacturers;

use App\Enums\Visibility;
use App\Filament\Resources\Manufacturers\Pages\ManageManufacturers;
use App\Models\Manufacturer;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ManufacturerResource extends Resource
{
    protected static ?string $model = Manufacturer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Hersteller';

    protected static ?string $modelLabel = 'Hersteller';

    protected static ?string $pluralModelLabel = 'Hersteller';

    protected static string|\UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 10;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stammdaten')
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                    Select::make('visibility')
                        ->label('Sichtbarkeit')
                        ->options(Visibility::class)
                        ->default(Visibility::Private->value)
                        ->required()
                        ->helperText('Öffentliche Hersteller können von allen Gruppen verwendet werden.'),
                    TextInput::make('website')
                        ->label('Website')
                        ->url()
                        ->prefix('https://')
                        ->maxLength(255),
                    TextInput::make('contact_email')
                        ->label('E-Mail')
                        ->email()
                        ->maxLength(255),
                    TextInput::make('contact_phone')
                        ->label('Telefon')
                        ->tel()
                        ->maxLength(255),
                ])->columns(3),
            Section::make('Adresse & Versand')
                ->schema([
                    Textarea::make('address')
                        ->label('Adresse')
                        ->rows(3),
                    Textarea::make('shipping_notes')
                        ->label('Versandhinweise')
                        ->rows(3)
                        ->helperText('z. B. Mindestbestellmengen, Lieferzeiten, übliche Versandkosten.'),
                ])->columns(2)->collapsible(),
            Section::make('Beschreibung')
                ->schema([
                    Textarea::make('description')
                        ->label('Beschreibung')
                        ->rows(4),
                ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Manufacturer $r) => str(strip_tags($r->description ?? ''))->limit(60)),
                TextColumn::make('visibility')
                    ->label('Sichtbarkeit')
                    ->badge(),
                TextColumn::make('group.name')
                    ->label('Gruppe')
                    ->placeholder('— öffentlich —')
                    ->color('gray'),
                TextColumn::make('products_count')
                    ->label('Produkte')
                    ->counts('products')
                    ->badge()
                    ->color('info'),
                TextColumn::make('contact_email')
                    ->label('E-Mail')
                    ->searchable()
                    ->copyable()
                    ->icon(Heroicon::Envelope)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipping_notes')
                    ->label('Versand')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Aktualisiert')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('visibility')
                    ->label('Sichtbarkeit')
                    ->options(Visibility::class),
            ])
            ->recordActions([
                ViewAction::make()->label('Ansehen')->slideOver(),
                EditAction::make()->label('Bearbeiten')->slideOver(),
                DeleteAction::make()->label('Löschen'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Noch keine Hersteller')
            ->emptyStateDescription('Lege einen Hersteller an, um Produkte zu pflegen.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Filament::getTenant());
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
            'index' => ManageManufacturers::route('/'),
        ];
    }
}
