<?php

namespace App\Filament\Resources\Rounds\Schemas;

use App\Models\Group;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                ->schema(static::basicsFields()),
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
            Section::make('Finanzen')
                ->schema(static::financeFields())
                ->columns(2)
                ->collapsible(),
        ]);
    }

    /**
     * @return array<int, Step>
     */
    public static function wizardSteps(): array
    {
        return [
            Step::make('Worum geht\'s?')
                ->description('Titel und kurze Beschreibung der Runde — du bist der Lead')
                ->icon(Heroicon::OutlinedSparkles)
                ->schema(static::basicsFields()),
            Step::make('Sortiment')
                ->description('Welche Produkte sind diesmal bestellbar?')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->schema([static::productSelectionField()]),
            Step::make('Zeitplan')
                ->description('Wann passiert was?')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->schema(static::scheduleFields())
                ->columns(2),
            Step::make('Abholung')
                ->description('Wo und wann holen die Teilnehmer die Ware ab?')
                ->icon(Heroicon::OutlinedTruck)
                ->schema(static::pickupFields())
                ->columns(2),
            Step::make('Finanzen')
                ->description('Aufwandsentschädigung & Vereinsbeitrag')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema(static::financeFields())
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
                $product->id => ($product->manufacturer?->name ?? '—').' · '.$product->packagingSummary(),
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
            DatePicker::make('shopping_deadline')
                ->label('Ende Einkaufsphase')
                ->native(false)
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(14))
                ->helperText('Bis wann sollen alle ihre Warenkörbe befüllt haben? Typisch: ca. 2 Wochen.'),
            DatePicker::make('negotiation_deadline')
                ->label('Ende Verhandlungsphase')
                ->native(false)
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(21))
                ->afterOrEqual('shopping_deadline')
                ->helperText('Bis dahin holt der Lead aktuelle Preise vom Hersteller. Typisch: ca. 5 Werktage.'),
            DatePicker::make('finalization_deadline')
                ->label('Ende Bestätigungsphase')
                ->native(false)
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(28))
                ->afterOrEqual('negotiation_deadline')
                ->helperText('Bis dahin müssen alle Beteiligten dem finalen Vorschlag zugestimmt haben.'),
            DatePicker::make('payment_deadline')
                ->label('Ende Zahlungsphase')
                ->native(false)
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(35))
                ->afterOrEqual('finalization_deadline')
                ->helperText('Bis dahin überweisen alle ihren Anteil an den Lead — außerhalb der Plattform.'),
            DatePicker::make('expected_delivery')
                ->label('Voraussichtliche Lieferung')
                ->native(false)
                ->displayFormat('d.m.Y')
                ->default(fn () => now()->addDays(56))
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
                ->schema([
                    DateTimePicker::make('scheduled_at')
                        ->label('Termin')
                        ->required()
                        ->native(false)
                        ->seconds(false)
                        ->displayFormat('d.m.Y H:i'),
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
                ->defaultItems(1)
                ->addActionLabel('Weiteren Abholtermin hinzufügen')
                ->helperText('1–3 Termine, zu denen Teilnehmer abholen können. Mindestens einer wird für den Start der Einkaufsphase benötigt.'),
        ];
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
                ->helperText('Für das Koordinieren der Runde. Wandert mit, falls der Lead-Status während der Runde übergeben wird. Typisch: 0–5 %.'),
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
            ->with(['manufacturer', 'priceTiers'])
            ->orderBy('name')
            ->get());
    }
}
