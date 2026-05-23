<?php

namespace App\Filament\Resources\Rounds\Schemas;

use App\Enums\RoundPhase;
use App\Models\Group;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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

                            return User::whereIn('id', $group->members()->pluck('users.id')->push($group->owner_id))
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn (User $u) => [$u->id => $u->fullName().' · '.$u->email])
                                ->all();
                        })
                        ->default(fn () => auth()->id())
                        ->required(),
                    Select::make('phase')
                        ->label('Phase')
                        ->options(RoundPhase::class)
                        ->default(RoundPhase::Draft->value)
                        ->disabled(fn (?string $operation) => $operation === 'create')
                        ->dehydratedWhenHidden()
                        ->required(),
                    Textarea::make('description')
                        ->label('Beschreibung / Notizen für die Teilnehmer')
                        ->rows(3)
                        ->columnSpanFull(),
                ])->columns(3),

            Section::make('Deadlines')
                ->schema([
                    DatePicker::make('shopping_deadline')
                        ->label('Ende Einkaufsphase')
                        ->native(false),
                    DatePicker::make('negotiation_deadline')
                        ->label('Ende Verhandlung')
                        ->native(false),
                    DatePicker::make('finalization_deadline')
                        ->label('Ende Bestätigung')
                        ->native(false),
                    DatePicker::make('payment_deadline')
                        ->label('Ende Zahlung')
                        ->native(false),
                    DatePicker::make('expected_delivery')
                        ->label('Voraussichtliche Lieferung')
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
