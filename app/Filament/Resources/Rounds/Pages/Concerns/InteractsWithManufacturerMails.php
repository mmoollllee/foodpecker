<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\ManufacturerMailType;
use App\Enums\RoundPhase;
use App\Models\Manufacturer;
use App\Services\Notifications\ManufacturerMailComposer;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Prepared mails to manufacturers: a price inquiry with the expected
 * quantities and the order of the final proposal. The lead sends them from
 * their own mail program, so answers reach them directly.
 */
trait InteractsWithManufacturerMails
{
    public function composeManufacturerMailAction(): Action
    {
        return Action::make('composeManufacturerMail')
            ->label('E-Mail an Hersteller')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->visible(fn (): bool => $this->canManage()
                && $this->getRound()->phase->isActive()
                && $this->getRound()->phase !== RoundPhase::Draft
                && app(ManufacturerMailComposer::class)->manufacturersFor($this->getRound())->isNotEmpty())
            ->modalHeading('E-Mail an einen Hersteller')
            ->modalDescription('Foodpecker füllt die wichtigsten Eckpunkte aus der Runde ein. Du kannst den Text anpassen und dann in deinem Mailprogramm öffnen oder kopieren.')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen')
            ->fillForm(function (): array {
                $manufacturer = app(ManufacturerMailComposer::class)->manufacturersFor($this->getRound())->first();
                $type = $this->getRound()->chosen_proposal_id !== null ? ManufacturerMailType::Order : ManufacturerMailType::PriceInquiry;

                return [
                    'manufacturer_id' => $manufacturer?->id,
                    'type' => $type->value,
                    ...$this->manufacturerMailFields($manufacturer?->id, $type->value),
                ];
            })
            ->schema([
                Select::make('manufacturer_id')
                    ->label('Hersteller')
                    ->options(fn (): array => app(ManufacturerMailComposer::class)->manufacturersFor($this->getRound())->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->refillManufacturerMail($get, $set)),
                ToggleButtons::make('type')
                    ->label('Anlass')
                    ->options(ManufacturerMailType::class)
                    ->inline()
                    ->required()
                    ->live()
                    ->disableOptionWhen(fn (string $value, Get $get): bool => ! $this->canComposeManufacturerMail($get('manufacturer_id'), $value))
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->refillManufacturerMail($get, $set)),
                Hidden::make('to'),
                TextInput::make('subject')
                    ->label('Betreff'),
                Textarea::make('body')
                    ->label('Text')
                    ->rows(16)
                    ->helperText(fn (Get $get): string => filled($get('to'))
                        ? 'Geht an '.$get('to').'.'
                        : 'Für diesen Hersteller ist keine E-Mail-Adresse hinterlegt — trag sie im Mailprogramm ein.')
                    ->belowContent([
                        Action::make('openMailProgram')
                            ->label('Im Mailprogramm öffnen')
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->actionJs(<<<'JS'
                                window.location.href = 'mailto:' + ($get('to') ?? '')
                                    + '?subject=' + encodeURIComponent($get('subject') ?? '')
                                    + '&body=' + encodeURIComponent($get('body') ?? '')
                                JS),
                        Action::make('copyMailText')
                            ->label('Text kopieren')
                            ->icon(Heroicon::OutlinedClipboardDocument)
                            ->color('gray')
                            ->actionJs(<<<'JS'
                                const text = ($get('subject') ?? '') + '\n\n' + ($get('body') ?? '');
                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(text);
                                } else {
                                    window.prompt('Text kopieren (Strg/Cmd + C):', text);
                                }
                                JS),
                    ]),
            ]);
    }

    protected function refillManufacturerMail(Get $get, Set $set): void
    {
        foreach ($this->manufacturerMailFields($get('manufacturer_id'), $get('type')) as $field => $value) {
            $set($field, $value);
        }
    }

    protected function canComposeManufacturerMail(mixed $manufacturerId, string $type): bool
    {
        $manufacturer = Manufacturer::find($manufacturerId);
        $mailType = ManufacturerMailType::tryFrom($type);

        return $manufacturer !== null
            && $mailType !== null
            && app(ManufacturerMailComposer::class)->canCompose($this->getRound(), $manufacturer, $mailType);
    }

    /**
     * @return array{to: ?string, subject: string, body: string}
     */
    protected function manufacturerMailFields(mixed $manufacturerId, mixed $type): array
    {
        $composer = app(ManufacturerMailComposer::class);
        $manufacturer = $composer->manufacturersFor($this->getRound())->firstWhere('id', (int) $manufacturerId);
        $mailType = $type instanceof ManufacturerMailType ? $type : ManufacturerMailType::tryFrom((string) $type);

        if ($manufacturer === null || $mailType === null || ! $composer->canCompose($this->getRound(), $manufacturer, $mailType)) {
            return ['to' => $manufacturer?->contact_email, 'subject' => '', 'body' => ''];
        }

        return [
            'to' => $manufacturer->contact_email,
            ...$composer->compose($this->getRound(), $manufacturer, $mailType, $this->currentUser()),
        ];
    }
}
