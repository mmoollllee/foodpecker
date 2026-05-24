<?php

namespace App\Filament\Resources\Rounds\Schemas;

use App\Models\Group;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form-Schema fürs Bearbeiten der "Eckdaten" einer bereits bestehenden Runde.
 *
 * Bewusst _ohne_ phase-Feld — die Phase wird über die dedizierten Actions
 * "Bestellrunde starten" bzw. "Phase wechseln" verwaltet, nicht hier.
 */
class RoundForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Eckdaten')
                ->schema([
                    TextInput::make('title')
                        ->label('Titel der Runde')
                        ->placeholder('z. B. „Frühjahr-Bestellung 2026"')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                    Select::make('lead_user_id')
                        ->label('Lead')
                        ->options(function () {
                            $group = Filament::getTenant();
                            if (! $group instanceof Group) {
                                return [];
                            }

                            return User::whereIn('id', $group->members()->pluck('users.id')->push($group->owner_id)->unique())
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn (User $u) => [$u->id => $u->fullName().' · '.$u->email])
                                ->all();
                        })
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->required(),
                    Textarea::make('description')
                        ->label('Beschreibung / Notizen für die Teilnehmer')
                        ->rows(3)
                        ->columnSpanFull(),
                ])->columns(3),

            Section::make('Sortiment')
                ->description('Welche Produkte sind in dieser Runde bestellbar? Leer lassen = alle für die Gruppe sichtbaren Produkte.')
                ->schema([
                    CheckboxList::make('available_products')
                        ->relationship('availableProducts', 'name')
                        ->options(fn () => Product::visibleTo(Filament::getTenant())
                            ->with('manufacturer')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => $p->name])
                            ->all())
                        ->descriptions(fn () => Product::visibleTo(Filament::getTenant())
                            ->with('manufacturer')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => ($p->manufacturer?->name ?? '—').' · '.$p->packagingSummary()])
                            ->all())
                        ->columns(2)
                        ->bulkToggleable()
                        ->hiddenLabel()
                        ->columnSpanFull(),
                ])->collapsed()->collapsible(),

            Section::make('Deadlines')
                ->schema([
                    DatePicker::make('shopping_deadline')
                        ->label('Ende Einkaufsphase')
                        ->displayFormat('d.m.Y')
                        ->native(false),
                    DatePicker::make('negotiation_deadline')
                        ->label('Ende Verhandlung')
                        ->displayFormat('d.m.Y')
                        ->native(false),
                    DatePicker::make('finalization_deadline')
                        ->label('Ende Bestätigung')
                        ->displayFormat('d.m.Y')
                        ->native(false),
                    DatePicker::make('payment_deadline')
                        ->label('Ende Zahlung')
                        ->displayFormat('d.m.Y')
                        ->native(false),
                    DatePicker::make('expected_delivery')
                        ->label('Voraussichtliche Lieferung')
                        ->displayFormat('d.m.Y')
                        ->native(false),
                ])->columns(3)->collapsible(),

            Section::make('Abholung')
                ->schema([
                    Textarea::make('pickup_location')
                        ->label('Abholort')
                        ->placeholder('Adresse + Hinweise (z. B. Klingelschild, Tor-Code)')
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('max_participants')
                        ->label('Maximale Teilnehmerzahl')
                        ->numeric()
                        ->helperText('Optional, z. B. wenn der Abholort begrenzt ist.'),
                    Repeater::make('pickupDates')
                        ->relationship()
                        ->label('Abholtermine')
                        ->schema([
                            DatePicker::make('scheduled_at')
                                ->label('Termin')
                                ->required()
                                ->displayFormat('d.m.Y')
                                ->native(false),
                            TextInput::make('location')
                                ->label('Spezifischer Ort (optional)'),
                            TextInput::make('notes')
                                ->label('Hinweis'),
                        ])
                        ->columns(3)
                        ->columnSpanFull()
                        ->defaultItems(1)
                        ->addActionLabel('Weiteren Abholtermin hinzufügen'),
                ])->columns(2)->collapsible(),

            Section::make('Finanzen')
                ->schema([
                    TextInput::make('lead_fee_percent')
                        ->label('Aufwandsentschädigung Lead')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(50)
                        ->suffix('%')
                        ->default(2.5),
                    TextInput::make('platform_fee_percent')
                        ->label('Vereinsbeitrag')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(10)
                        ->suffix('%')
                        ->default(1.0)
                        ->helperText('1 % geht an den Foodpecker-Verein.'),
                ])->columns(2)->collapsible(),
        ]);
    }
}
