<?php

namespace App\Filament\Resources\Rounds\Schemas;

use App\Models\Group;
use App\Models\Product;
use CodeWithKyrian\FilamentDateRange\Forms\Components\DateRangePicker;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Fields of a round, shared by the creation wizard and "Eckdaten bearbeiten".
 *
 * Bewusst _ohne_ phase-Feld — die Phase wird über die dedizierten Actions
 * "Bestellrunde starten" bzw. "Weiter zu …" verwaltet, nicht hier.
 */
class RoundForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Eckdaten')
                ->description('Den Lead wechselst du über „Weitere Aktionen → Lead-Rolle übergeben“ — mit Zustimmung der neuen Person.')
                ->schema([
                    ...static::basicsFields(),
                    static::financeFieldset(),
                ]),
            Section::make('Sortiment')
                ->description('Welche Produkte sind in dieser Runde bestellbar? Leer lassen = alle für die Gruppe sichtbaren Produkte.')
                ->schema([static::productSelectionField()])
                ->collapsed()
                ->collapsible(),
            Section::make('Zeitplan')
                ->schema(static::scheduleFields())
                ->columns(2)
                ->collapsible(),
            Section::make('Abholung')
                ->schema(static::pickupFields())
                ->columns(2)
                ->collapsible(),
        ]);
    }

    /**
     * Four short steps, so the wizard header shows all of them. The
     * finances belong to the basics: the lead fee is set when the round opens.
     *
     * @return array<int, Step>
     */
    public static function wizardSteps(): array
    {
        return [
            Step::make('Eckdaten')
                ->icon(Heroicon::OutlinedSparkles)
                ->schema([
                    ...static::basicsFields(),
                    static::financeFieldset(),
                ]),
            Step::make('Sortiment')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->schema([static::productSelectionField()]),
            Step::make('Zeitplan')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->schema(static::scheduleFields())
                ->columns(2),
            Step::make('Abholung')
                ->icon(Heroicon::OutlinedTruck)
                ->schema(static::pickupFields())
                ->columns(2),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function basicsFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Titel der Runde')
                ->placeholder('z. B. „Frühjahr-Bestellung 2026“')
                ->required()
                ->maxLength(255)
                ->helperText('Wie soll diese Bestellrunde heißen? Das sehen alle Teilnehmer.'),
            Textarea::make('description')
                ->label('Beschreibung (optional)')
                ->placeholder('Worauf wollen wir uns dieses Mal konzentrieren? Was ist diesmal anders?')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
    }

    public static function productSelectionField(): CheckboxList
    {
        return CheckboxList::make('available_products')
            ->label('Bestellbare Produkte')
            ->relationship('availableProducts', 'name')
            ->options(fn (): array => static::selectableProducts()->mapWithKeys(fn (Product $product): array => [$product->id => $product->name])->all())
            ->descriptions(fn (): array => static::selectableProducts()->mapWithKeys(fn (Product $product): array => [
                $product->id => ($product->supplier?->name ?? '—').' · '.$product->packagingSummary(),
            ])->all())
            ->columns(2)
            ->bulkToggleable()
            ->helperText('Leer = alle für die Gruppe sichtbaren Produkte sind bestellbar. Die Auswahl lässt sich später jederzeit anpassen.')
            ->columnSpanFull();
    }

    /**
     * @return array<int, mixed>
     */
    public static function scheduleFields(): array
    {
        return [
            DateRangePicker::make('shopping_deadline')
                ->label('Ende Einkaufsphase')
                ->singleDate()
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(14)->startOfDay())
                ->helperText('Bis wann sollen alle ihre Warenkörbe befüllt haben? Typisch: ca. 2 Wochen.'),
            DateRangePicker::make('negotiation_deadline')
                ->label('Ende Anpassungsphase')
                ->singleDate()
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(21)->startOfDay())
                ->afterOrEqual('shopping_deadline')
                ->helperText('Bis dahin holt der Lead Preise und Versandkosten bei den Lieferanten ein. Typisch: ca. 5 Werktage.'),
            DateRangePicker::make('finalization_deadline')
                ->label('Ende Bestätigungsphase')
                ->singleDate()
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(28)->startOfDay())
                ->afterOrEqual('negotiation_deadline')
                ->helperText('Bis dahin müssen alle Beteiligten dem finalen Vorschlag zugestimmt haben.'),
            DateRangePicker::make('payment_deadline')
                ->label('Ende Zahlungsphase')
                ->singleDate()
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(35)->startOfDay())
                ->afterOrEqual('finalization_deadline')
                ->helperText('Bis dahin überweisen alle ihren Anteil an den Lead — außerhalb der Plattform.'),
            DateRangePicker::make('expected_delivery')
                ->label('Voraussichtliche Lieferung')
                ->singleDate()
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(56)->startOfDay())
                ->helperText('Wann sollte die Ware beim Lead eintreffen? Grob geschätzt reicht.'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function pickupFields(): array
    {
        return [
            Textarea::make('pickup_location')
                ->label('Abholort')
                ->required()
                ->rows(2)
                ->placeholder("Straße, Hausnummer, PLZ Ort\nHinweis (z. B. Klingelschild, Tor-Code)")
                ->helperText('Wo holen die Teilnehmer ihre Ware ab? Adresse und ggf. Hinweise zum Hineinkommen.')
                ->columnSpanFull(),
            TextInput::make('max_participants')
                ->label('Maximale Teilnehmerzahl (optional)')
                ->integer()
                ->minValue(2)
                ->maxValue(100)
                ->placeholder('z. B. 8')
                ->helperText('Falls der Abholort begrenzt ist. Leer lassen = unbegrenzt.'),
            Repeater::make('pickupDates')
                ->relationship()
                ->label('Abholtermine')
                ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => [
                    ...$data,
                    'window' => ['start' => $data['scheduled_at'] ?? null, 'end' => $data['ends_at'] ?? null],
                ])
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => static::pickupWindowToColumns($data))
                ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => static::pickupWindowToColumns($data))
                ->schema([
                    DateRangePicker::make('window')
                        ->label('Zeitfenster')
                        ->required()
                        ->withTime()
                        ->displayFormat('d.m.Y H:i')
                        ->startPlaceholder('Von')
                        ->endPlaceholder('Bis'),
                    TextInput::make('location')
                        ->label('Spezifischer Ort (optional)')
                        ->placeholder('Falls abweichend vom Hauptort')
                        ->maxLength(255),
                    TextInput::make('notes')
                        ->label('Hinweis (optional)')
                        ->placeholder('z. B. „nur Samstags“')
                        ->maxLength(255),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->defaultItems(0)
                ->addActionLabel('Abholtermin hinzufügen')
                ->helperText('1–3 Termine, zu denen Teilnehmer abholen können. Leg sie fest, sobald der Liefertermin absehbar ist — spätestens bevor die Abholung beginnt.'),
        ];
    }

    /**
     * The picked time window goes into the start and end columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function pickupWindowToColumns(array $data): array
    {
        $window = $data['window'] ?? [];
        unset($data['window']);

        return [
            ...$data,
            'scheduled_at' => $window['start'] ?? null,
            'ends_at' => $window['end'] ?? null,
        ];
    }

    /**
     * What the round costs on top of the goods.
     */
    public static function financeFieldset(): Fieldset
    {
        return Fieldset::make('Finanzen')
            ->schema(static::financeFields())
            ->columns(2)
            ->columnSpanFull();
    }

    /**
     * @return array<int, mixed>
     */
    public static function financeFields(): array
    {
        return [
            TextInput::make('lead_fee_percent')
                ->label('Aufwandsentschädigung für den Lead')
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->maxValue(50)
                ->suffix('% der Bestellsumme')
                ->default(2.5)
                ->required()
                ->helperText('Bekommt der Lead fürs Koordinieren der Runde. Wandert mit, falls der Lead-Status während der Runde übergeben wird. Typisch: 0–5 %.'),
            TextInput::make('platform_fee_percent')
                ->label('Beitrag an den Foodpecker-Verein')
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->maxValue(10)
                ->suffix('% der Bestellsumme')
                ->default(fn (): float => (float) config('foodpecker.platform_fee_percent', 1.0))
                ->required()
                ->helperText('Finanziert Betrieb und Weiterentwicklung der Plattform.'),
        ];
    }

    private static function currentGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }

    /**
     * @return Collection<int, Product>
     */
    private static function selectableProducts(): Collection
    {
        return once(fn () => Product::visibleTo(static::currentGroup())
            ->with(['supplier', 'priceTiers'])
            ->orderBy('name')
            ->get());
    }
}
