<?php

namespace App\Filament\Resources\Rounds;

use App\Filament\Resources\Rounds\Pages\CreateRound;
use App\Filament\Resources\Rounds\Pages\ListRounds;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Filament\Resources\Rounds\Schemas\RoundForm;
use App\Filament\Resources\Rounds\Tables\RoundsTable;
use App\Models\Round;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoundResource extends Resource
{
    protected static ?string $model = Round::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Bestellrunden';

    protected static ?string $modelLabel = 'Bestellrunde';

    protected static ?string $pluralModelLabel = 'Bestellrunden';

    protected static string|\UnitEnum|null $navigationGroup = 'Bestellungen';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return RoundForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoundsTable::configure($table);
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record?->title;
    }

    /**
     * Drafts sind privat — nur der Lead sieht seine eigenen Entwürfe.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleTo($user);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRounds::route('/'),
            'create' => CreateRound::route('/create'),
            'view' => ViewRound::route('/{record}'),
        ];
    }
}
