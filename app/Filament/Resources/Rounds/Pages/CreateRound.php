<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Group;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;

class CreateRound extends CreateRecord
{
    use HasWizard;

    protected static string $resource = RoundResource::class;

    public function getTitle(): string
    {
        return 'Neue Bestellrunde starten';
    }

    public function getSubheading(): ?string
    {
        return 'In vier Schritten zur neuen Runde — alle Angaben sind später noch änderbar.';
    }

    public function hasSkippableSteps(): bool
    {
        return true;
    }

    protected function getSteps(): array
    {
        return [
            Step::make('Worum geht\'s?')
                ->description('Titel, Lead und kurze Beschreibung der Runde')
                ->icon(Heroicon::OutlinedSparkles)
                ->schema([
                    TextInput::make('title')
                        ->label('Titel der Runde')
                        ->placeholder('z. B. „Frühjahr-Bestellung 2026"')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Wie soll diese Bestellrunde heißen? Das sehen alle Teilnehmer.'),
                    Select::make('lead_user_id')
                        ->label('Lead — wer koordiniert die Runde?')
                        ->options(fn () => $this->memberOptions())
                        ->default(fn () => auth()->id())
                        ->required()
                        ->searchable()
                        ->helperText('Der Lead verhandelt mit dem Hersteller, gibt die Bestellung auf und sammelt die Zahlungen ein. Standardmäßig bist das du.'),
                    Textarea::make('description')
                        ->label('Beschreibung (optional)')
                        ->rows(3)
                        ->placeholder('Worauf wollen wir uns dieses Mal konzentrieren? Was ist diesmal anders?')
                        ->maxLength(1000),
                ]),

            Step::make('Zeitplan')
                ->description('Wann passiert was?')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->schema([
                    DatePicker::make('shopping_deadline')
                        ->label('Ende Einkaufsphase')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->helperText('Bis wann sollen alle ihre Warenkörbe befüllt haben? Typisch: ca. 2 Wochen.')
                        ->default(now()->addDays(14)),
                    DatePicker::make('negotiation_deadline')
                        ->label('Ende Verhandlungsphase')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->helperText('Bis dahin holt der Lead aktuelle Preise vom Hersteller. Typisch: ca. 5 Werktage.')
                        ->default(now()->addDays(21))
                        ->afterOrEqual('shopping_deadline'),
                    DatePicker::make('finalization_deadline')
                        ->label('Ende Bestätigungsphase')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->helperText('Bis dahin müssen alle Teilnehmer dem finalen Vorschlag zugestimmt haben.')
                        ->default(now()->addDays(28))
                        ->afterOrEqual('negotiation_deadline'),
                    DatePicker::make('payment_deadline')
                        ->label('Ende Zahlungsphase')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->helperText('Bis dahin überweisen alle ihren Anteil an den Lead. Außerhalb der Plattform.')
                        ->default(now()->addDays(35))
                        ->afterOrEqual('finalization_deadline'),
                    DatePicker::make('expected_delivery')
                        ->label('Voraussichtliche Lieferung')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->helperText('Wann sollte die Ware beim Lead eintreffen? Kann auch grob geschätzt werden.')
                        ->default(now()->addDays(56)),
                ])
                ->columns(2),

            Step::make('Abholung')
                ->description('Wo und wann holen die Teilnehmer die Ware ab?')
                ->icon(Heroicon::OutlinedTruck)
                ->schema([
                    Textarea::make('pickup_location')
                        ->label('Abholort')
                        ->required()
                        ->rows(2)
                        ->placeholder("Straße, Hausnummer, PLZ Ort\nHinweis (z. B. Klingelschild, Tor-Code)")
                        ->helperText('Wo holen die Teilnehmer ihre Ware ab? Adresse + ggf. Hinweise zum Hineinkommen.')
                        ->columnSpanFull(),
                    TextInput::make('max_participants')
                        ->label('Maximale Teilnehmerzahl (optional)')
                        ->numeric()
                        ->minValue(2)
                        ->maxValue(50)
                        ->placeholder('z. B. 8')
                        ->helperText('Falls der Abholort begrenzt ist. Leer lassen = unbegrenzt.'),
                    Repeater::make('pickupDates')
                        ->relationship()
                        ->label('Abholtermine')
                        ->schema([
                            DatePicker::make('scheduled_at')
                                ->label('Termin')
                                ->required()
                                ->native(false)
                                ->displayFormat('d.m.Y'),
                            TextInput::make('location')
                                ->label('Spezifischer Ort (optional)')
                                ->placeholder('Falls abweichend vom Hauptort'),
                            TextInput::make('notes')
                                ->label('Hinweis (optional)')
                                ->placeholder('z. B. „nur Samstags"'),
                        ])
                        ->columns(3)
                        ->columnSpanFull()
                        ->defaultItems(1)
                        ->addActionLabel('Weiteren Abholtermin hinzufügen')
                        ->helperText('1–3 Termine, zu denen Teilnehmer abholen können. Mindestens einer wird für den Start der Einkaufsphase benötigt.'),
                ])
                ->columns(2),

            Step::make('Finanzen')
                ->description('Aufwandsentschädigung & Vereinsbeitrag')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema([
                    TextInput::make('lead_fee_percent')
                        ->label('Aufwandsentschädigung für den Lead')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(50)
                        ->suffix('% der Bestellsumme')
                        ->default(2.5)
                        ->required()
                        ->helperText('Der Lead bekommt für das Koordinieren der Runde eine prozentuale Aufwandsentschädigung. Wandert mit, falls der Lead-Status während der Runde übergeben wird. Typisch: 0–5 %.'),
                    TextInput::make('platform_fee_percent')
                        ->label('Beitrag an Foodpecker e. V.')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(10)
                        ->suffix('% der Bestellsumme')
                        ->default(1.0)
                        ->required()
                        ->helperText('1 % geht an den Foodpecker-Verein, der die Plattform betreibt. Teilnehmer können diesen Betrag freiwillig erhöhen, indem sie ihre persönliche Bestellsumme aufrunden.'),
                ])
                ->columns(2),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['phase'] = RoundPhase::Draft->value;
        $data['phase_changed_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Round $round */
        $round = $this->record;

        // Lead automatisch als Teilnehmer registrieren
        RoundParticipant::firstOrCreate([
            'round_id' => $round->id,
            'user_id' => $round->lead_user_id,
        ]);

        $round->logActivity('created', ['title' => $round->title]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * @return array<int, string>
     */
    private function memberOptions(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Group) {
            return [];
        }

        return User::whereIn('id', $tenant->members()->pluck('users.id')->push($tenant->owner_id)->unique())
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->fullName().' · '.$u->email])
            ->all();
    }
}
