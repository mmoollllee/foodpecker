<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\RoundPhase;
use App\Enums\SupplierMailType;
use App\Models\Supplier;
use App\Services\Notifications\SupplierMailComposer;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Prepared mails to suppliers: a price inquiry with the expected
 * quantities and the order of the final proposal. The lead sends them from
 * their own mail program, so answers reach them directly.
 */
trait InteractsWithSupplierMails
{
    /**
     * Suppliers a mail can be written to, by mail type — every supplier card
     * asks for its own button.
     *
     * @var array<string, Collection<int, Supplier>>
     */
    protected array $composableSuppliersCache = [];

    /**
     * Pass `type` and `supplier` arguments to open the mail for that
     * occasion and supplier, e.g. the price inquiry while negotiating.
     */
    public function composeSupplierMailAction(): Action
    {
        return Action::make('composeSupplierMail')
            ->label(fn (array $arguments): string => $arguments['label'] ?? match (SupplierMailType::tryFrom((string) ($arguments['type'] ?? ''))) {
                SupplierMailType::PriceInquiry => 'Preisanfrage per Mail',
                SupplierMailType::FollowUp => 'Nachfassen per Mail',
                SupplierMailType::Order => 'Bestellung per Mail',
                default => 'E-Mail an den Lieferanten',
            })
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->color(fn (array $arguments): string => isset($arguments['label']) ? 'gray' : 'primary')
            ->size(fn (array $arguments): string => isset($arguments['label']) ? 'xs' : 'md')
            ->visible(fn (array $arguments): bool => $this->canManage()
                && $this->getRound()->phase->isActive()
                && $this->getRound()->phase !== RoundPhase::Draft
                && $this->composableSuppliers(SupplierMailType::tryFrom((string) ($arguments['type'] ?? '')))
                    ->when(filled($arguments['supplier'] ?? null), fn ($suppliers) => $suppliers->where('id', (int) $arguments['supplier']))
                    ->isNotEmpty())
            ->modalHeading('E-Mail an einen Lieferanten')
            ->modalDescription('Foodpecker füllt die wichtigsten Eckpunkte aus der Runde ein. Du kannst den Text anpassen und dann in deinem Mailprogramm öffnen oder kopieren.')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen')
            ->fillForm(function (array $arguments): array {
                $type = SupplierMailType::tryFrom((string) ($arguments['type'] ?? ''))
                    ?? ($this->getRound()->chosen_proposal_id !== null ? SupplierMailType::Order : SupplierMailType::PriceInquiry);
                $composable = $this->composableSuppliers($type);
                $supplier = $composable->firstWhere('id', (int) ($arguments['supplier'] ?? 0))
                    ?? $composable->first()
                    ?? app(SupplierMailComposer::class)->suppliersFor($this->getRound())->first();

                return [
                    'supplier_id' => $supplier?->id,
                    'type' => $type->value,
                    ...$this->supplierMailFields($supplier?->id, $type->value),
                ];
            })
            ->schema([
                Select::make('supplier_id')
                    ->label('Lieferant')
                    ->options(fn (): array => app(SupplierMailComposer::class)->suppliersFor($this->getRound())->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->refillSupplierMail($get, $set)),
                ToggleButtons::make('type')
                    ->label('Anlass')
                    ->options(SupplierMailType::class)
                    ->inline()
                    ->required()
                    ->live()
                    ->disableOptionWhen(fn (string $value, Get $get): bool => ! $this->canComposeSupplierMail($get('supplier_id'), $value))
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->refillSupplierMail($get, $set)),
                Hidden::make('to'),
                TextInput::make('subject')
                    ->label('Betreff'),
                Textarea::make('body')
                    ->label('Text')
                    ->rows(16)
                    ->helperText(fn (Get $get): string => filled($get('to'))
                        ? 'Geht an '.$get('to').'.'
                        : 'Für diesen Lieferanten ist keine E-Mail-Adresse hinterlegt — trag sie im Mailprogramm ein.')
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

    /**
     * Suppliers of the round a mail of the given type can be written
     * to — all of them without a type.
     *
     * @return Collection<int, Supplier>
     */
    protected function composableSuppliers(?SupplierMailType $type): Collection
    {
        return $this->composableSuppliersCache[$type?->value ?? ''] ??= $this->findComposableSuppliers($type);
    }

    /**
     * @return Collection<int, Supplier>
     */
    private function findComposableSuppliers(?SupplierMailType $type): Collection
    {
        $composer = app(SupplierMailComposer::class);
        $suppliers = $composer->suppliersFor($this->getRound());

        if ($type === null) {
            return $suppliers;
        }

        return $suppliers
            ->filter(fn (Supplier $supplier): bool => $composer->canCompose($this->getRound(), $supplier, $type))
            ->values();
    }

    protected function refillSupplierMail(Get $get, Set $set): void
    {
        foreach ($this->supplierMailFields($get('supplier_id'), $get('type')) as $field => $value) {
            $set($field, $value);
        }
    }

    protected function canComposeSupplierMail(mixed $supplierId, string $type): bool
    {
        $supplier = Supplier::find($supplierId);
        $mailType = SupplierMailType::tryFrom($type);

        return $supplier !== null
            && $mailType !== null
            && app(SupplierMailComposer::class)->canCompose($this->getRound(), $supplier, $mailType);
    }

    /**
     * @return array{to: ?string, subject: string, body: string}
     */
    protected function supplierMailFields(mixed $supplierId, mixed $type): array
    {
        $composer = app(SupplierMailComposer::class);
        $supplier = $composer->suppliersFor($this->getRound())->firstWhere('id', (int) $supplierId);
        $mailType = $type instanceof SupplierMailType ? $type : SupplierMailType::tryFrom((string) $type);

        if ($supplier === null || $mailType === null || ! $composer->canCompose($this->getRound(), $supplier, $mailType)) {
            return ['to' => $supplier?->contact_email, 'subject' => '', 'body' => ''];
        }

        return [
            'to' => $supplier->contact_email,
            ...$composer->compose($this->getRound(), $supplier, $mailType, $this->currentUser()),
        ];
    }
}
