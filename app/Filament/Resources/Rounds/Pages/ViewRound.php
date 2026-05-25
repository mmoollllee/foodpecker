<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\QuantityMode;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Filament\Resources\Rounds\Schemas\RoundForm;
use App\Models\CartItem;
use App\Models\OrderProposal;
use App\Models\Payment;
use App\Models\Pickup;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProposalAllocation;
use App\Models\ProposalItem;
use App\Models\ProposalVote;
use App\Models\Round;
use App\Models\RoundParticipant;
use App\Services\Distribution\Distributor;
use App\Services\Money\OrderCalculator;
use App\Services\Notifications\DraftBuilder;
use App\Services\Rounds\PhaseTransitioner;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ViewRound extends Page
{
    protected static string $resource = RoundResource::class;

    protected string $view = 'filament.rounds.view-round';

    public ?int $record = null;

    public Round $round;

    public function mount(int|string $record): void
    {
        $this->record = (int) $record;
        $this->round = Round::query()
            ->with([
                'lead',
                'group',
                'pickupDates',
                'participants.user',
                'cartItems.user',
                'cartItems.product.manufacturer',
                'cartItems.product.priceTiers',
                'cartItems.preferredTier',
                'proposals.items.product',
                'proposals.items.priceTier',
                'proposals.items.allocations.user',
                'proposals.items.votes.user',
                'proposals.proposedBy',
                'payments.user',
                'pickups.user',
                'pickups.pickupDate',
                'notificationDrafts.preparedBy',
            ])
            ->findOrFail($record);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->round->title;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->round->title;
    }

    public function getSubheading(): ?string
    {
        return sprintf(
            'Lead: %s · Phase: %s · %d Teilnehmer',
            $this->round->lead?->fullName() ?? '—',
            $this->round->phase->getLabel(),
            $this->round->participants->where('removed', false)->count(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->startRoundAction(),
            $this->advancePhaseAction(),
            $this->addCartItemAction(),
            $this->createProposalAction(),
            $this->editRoundAction(),
            ActionGroup::make([
                $this->managePickupDatesAction(),
                $this->addParticipantAction(),
                $this->generateNotificationAction(),
            ])->label('Weitere Aktionen')->icon('heroicon-o-bars-3'),
        ];
    }

    public function startRoundAction(): Action
    {
        return Action::make('startRound')
            ->label('Bestellrunde starten')
            ->icon('heroicon-o-play')
            ->color('success')
            ->visible(fn (): bool => $this->round->phase === RoundPhase::Draft)
            ->requiresConfirmation()
            ->modalHeading('Bestellrunde starten?')
            ->modalDescription('Die Einkaufsphase wird sofort eröffnet, alle Mitglieder können die Runde dann sehen und Warenkörbe füllen. Voraussetzung: mindestens ein Abholtermin und ein Abholort sind hinterlegt.')
            ->modalSubmitActionLabel('Ja, jetzt starten')
            ->action(function (): void {
                try {
                    app(PhaseTransitioner::class)->transition(
                        $this->round,
                        RoundPhase::Shopping,
                        auth()->user(),
                        'Runde aus Entwurf gestartet.',
                    );
                    Notification::make()
                        ->title('Bestellrunde gestartet 🎉')
                        ->body('Die Einkaufsphase ist offen — denk dran, die Gruppe per Benachrichtigung zu informieren.')
                        ->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Start nicht möglich')
                        ->body(collect($e->errors())->flatten()->first() ?? 'Bitte erst Abholort und mindestens einen Abholtermin hinterlegen.')
                        ->danger()->send();
                }
                $this->refreshRound();
            });
    }

    /* ---------- Actions ---------- */

    public function advancePhaseAction(): Action
    {
        $next = $this->round->phase->allowedTransitions();
        $options = collect($next)
            ->mapWithKeys(fn (RoundPhase $p) => [$p->value => $p->getLabel()])
            ->all();

        return Action::make('advancePhase')
            ->label('Phase wechseln')
            ->icon('heroicon-o-forward')
            ->color('amber')
            ->visible(fn (): bool => $this->round->phase !== RoundPhase::Draft && ! empty($options))
            ->schema([
                Select::make('phase')
                    ->label('Neue Phase')
                    ->options($options)
                    ->required(),
                Textarea::make('reason')
                    ->label('Kommentar (optional)')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                app(PhaseTransitioner::class)->transition(
                    $this->round,
                    RoundPhase::from($data['phase']),
                    auth()->user(),
                    $data['reason'] ?? null,
                );
                Notification::make()->title('Phase aktualisiert.')->success()->send();
                $this->refreshRound();
            });
    }

    public function editRoundAction(): Action
    {
        return Action::make('editRound')
            ->label('Eckdaten bearbeiten')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->outlined()
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Speichern')
            ->record(fn () => $this->round)
            ->fillForm(fn (Round $record) => array_merge(
                $record->attributesToArray(),
                [
                    'pickupDates' => $record->pickupDates->map(fn ($d) => [
                        'scheduled_at' => $d->scheduled_at,
                        'location' => $d->location,
                        'notes' => $d->notes,
                    ])->all(),
                    'available_products' => $record->availableProducts->pluck('id')->all(),
                ],
            ))
            ->schema(RoundForm::configure(Schema::make())->getComponents())
            ->action(function (array $data, Round $record): void {
                $record->update(collect($data)->except(['available_products', 'pickupDates'])->all());
                Notification::make()->title('Bestellrunde aktualisiert.')->success()->send();
                $this->refreshRound();
            });
    }

    public function managePickupDatesAction(): Action
    {
        return Action::make('managePickupDates')
            ->label('Abholtermine')
            ->icon('heroicon-o-calendar-days')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen')
            ->modalContent(fn () => view('filament.rounds.partials.pickup-dates-list', [
                'round' => $this->round,
            ]));
    }

    public function addCartItemAction(): Action
    {
        return Action::make('addCartItem')
            ->label('Artikel hinzufügen')
            ->icon('heroicon-o-shopping-cart')
            ->color('primary')
            ->slideOver()
            ->visible(fn () => $this->round->phase === RoundPhase::Shopping)
            ->schema([
                Select::make('user_id')
                    ->label('Teilnehmer')
                    ->options(fn () => $this->participantOptions())
                    ->default(auth()->id())
                    ->required()
                    ->searchable(),
                Select::make('product_id')
                    ->label('Produkt')
                    ->options(fn () => $this->round->availableProductsForCart()
                        ->orderBy('name')
                        ->pluck('name', 'products.id'))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText($this->round->availableProducts()->exists()
                        ? 'Produkte aus dem für diese Runde kuratierten Sortiment.'
                        : 'Alle für die Gruppe sichtbaren Produkte.'),
                ToggleButtons::make('quantity_mode')
                    ->label('Mengenangabe')
                    ->options(QuantityMode::class)
                    ->default(QuantityMode::Exact->value)
                    ->required()
                    ->inline()
                    ->live(),
                TextInput::make('exact_quantity')
                    ->label('Exakte Menge')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Exact->value),
                TextInput::make('min_quantity')
                    ->label('Mindestmenge')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                TextInput::make('max_quantity')
                    ->label('Maximale Menge')
                    ->numeric()
                    ->step(0.01)
                    ->suffix(fn (Get $get) => $this->unitLabelForProduct((int) $get('product_id')))
                    ->visible(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value)
                    ->required(fn (Get $get) => $get('quantity_mode') === QuantityMode::Flexible->value),
                Textarea::make('notes')->label('Notiz')->rows(2),
            ])
            ->action(function (array $data): void {
                CartItem::updateOrCreate(
                    [
                        'round_id' => $this->round->id,
                        'user_id' => $data['user_id'],
                        'product_id' => $data['product_id'],
                    ],
                    $data,
                );
                RoundParticipant::firstOrCreate([
                    'round_id' => $this->round->id,
                    'user_id' => $data['user_id'],
                ]);
                Notification::make()->title('Artikel hinzugefügt.')->success()->send();
                $this->refreshRound();
            });
    }

    public function createProposalAction(): Action
    {
        return Action::make('createProposal')
            ->label('Vorschlag erstellen')
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->visible(fn () => in_array($this->round->phase, [
                RoundPhase::Negotiating, RoundPhase::Finalizing,
            ], true))
            ->schema([
                TextInput::make('title')
                    ->label('Titel des Vorschlags')
                    ->default('Vorschlag '.now()->format('d.m.'))
                    ->required(),
                Textarea::make('description')
                    ->label('Begründung / Hinweise')
                    ->rows(3),
                TextInput::make('shipping_cents')
                    ->label('Versandkosten (Cent)')
                    ->numeric()
                    ->default(0)
                    ->suffix('Cent'),
            ])
            ->action(function (array $data): void {
                $proposal = $this->round->proposals()->create([
                    'proposed_by_user_id' => auth()->id(),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'shipping_cents' => $data['shipping_cents'] ?? 0,
                    'status' => ProposalStatus::Draft->value,
                ]);

                // automatisch alle Produkte mit ≥1 Cart-Item befüllen
                $this->prefillProposal($proposal);

                $proposal->logActivity('created');
                Notification::make()->title('Vorschlag erstellt — du kannst ihn jetzt feinjustieren.')->success()->send();
                $this->refreshRound();
            });
    }

    public function addParticipantAction(): Action
    {
        return Action::make('addParticipant')
            ->label('Teilnehmer hinzufügen')
            ->icon('heroicon-o-user-plus')
            ->schema([
                Select::make('user_id')
                    ->label('Mitglied')
                    ->options(fn () => $this->memberOptions())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                RoundParticipant::firstOrCreate([
                    'round_id' => $this->round->id,
                    'user_id' => $data['user_id'],
                ]);
                Notification::make()->title('Teilnehmer ergänzt.')->success()->send();
                $this->refreshRound();
            });
    }

    public function generateNotificationAction(): Action
    {
        $kinds = [
            'shopping_open' => '🛒 Einkaufsphase eröffnet',
            'negotiation_started' => '📞 Verhandlung läuft',
            'proposal_ready' => '🤝 Vorschlag zur Abstimmung',
            'payment_due' => '💶 Zahlung fällig',
            'order_placed' => '📦 Bestellung ist raus',
            'pickup_dates' => '📅 Abholtermine bekannt',
            'custom' => '✍️ Freier Text',
        ];

        return Action::make('generateNotification')
            ->label('Benachrichtigung vorbereiten')
            ->icon('heroicon-o-envelope')
            ->schema([
                Select::make('kind')
                    ->label('Anlass')
                    ->options($kinds)
                    ->default('custom')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $draft = app(DraftBuilder::class)->buildDraft($this->round, $data['kind'], auth()->user());
                Notification::make()
                    ->title('Entwurf erstellt — siehe Tab "Aktivitäten & Benachrichtigungen".')
                    ->body('Du kannst den Text dort noch anpassen, bevor er versendet wird.')
                    ->success()
                    ->send();
                $this->refreshRound();
            });
    }

    public ?int $contextDraftId = null;

    public function editDraftAction(): Action
    {
        return Action::make('editDraft')
            ->label('Bearbeiten')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->size('xs')
            ->modalHeading('Benachrichtigungs-Entwurf bearbeiten')
            ->modalDescription('Pass Betreff und Text an. Sobald du speicherst, ist der Entwurf bereit zum Versand.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Speichern')
            ->mountUsing(function (array $arguments): void {
                $this->contextDraftId = (int) ($arguments['draft_id'] ?? 0);
            })
            ->fillForm(function (): array {
                $draft = $this->round->notificationDrafts()->find($this->contextDraftId);

                return $draft ? $draft->only(['subject', 'body']) : [];
            })
            ->schema([
                TextInput::make('subject')
                    ->label('Betreff')
                    ->required()
                    ->maxLength(255),
                Textarea::make('body')
                    ->label('Nachrichten-Text (Markdown)')
                    ->required()
                    ->rows(18)
                    ->helperText('Markdown unterstützt — Überschriften (#), Listen (-), Fett (**…**). Wird beim Versenden als E-Mail-Text genutzt.'),
            ])
            ->action(function (array $data): void {
                $draft = $this->round->notificationDrafts()->find($this->contextDraftId);
                if ($draft) {
                    $draft->update($data);
                    Notification::make()->title('Entwurf gespeichert.')->success()->send();
                }
                $this->refreshRound();
            });
    }

    /* ---------- Helpers ---------- */

    public function castVote(int $proposalItemId, string $value): void
    {
        ProposalVote::updateOrCreate(
            ['proposal_item_id' => $proposalItemId, 'user_id' => auth()->id()],
            ['value' => $value],
        );
        Notification::make()
            ->title($value === 'up' ? 'Daumen hoch gesetzt 👍' : 'Daumen runter gesetzt 👎')
            ->success()
            ->send();
        $this->refreshRound();
    }

    public function publishProposal(int $proposalId): void
    {
        OrderProposal::where('id', $proposalId)->update([
            'status' => ProposalStatus::Published->value,
            'published_at' => now(),
        ]);
        Notification::make()->title('Vorschlag veröffentlicht — die Gruppe kann jetzt abstimmen.')->success()->send();
        $this->refreshRound();
    }

    public function chooseProposal(int $proposalId): void
    {
        $this->round->update([
            'chosen_proposal_id' => $proposalId,
        ]);
        OrderProposal::where('id', $proposalId)->update([
            'status' => ProposalStatus::Chosen->value,
        ]);
        OrderProposal::where('round_id', $this->round->id)
            ->where('id', '!=', $proposalId)
            ->where('status', ProposalStatus::Chosen->value)
            ->update(['status' => ProposalStatus::Published->value]);

        $this->createPaymentsForChosenProposal($proposalId);

        Notification::make()->title('Vorschlag als finale Bestellung markiert.')->success()->send();
        $this->refreshRound();
    }

    public function togglePayment(int $paymentId): void
    {
        $payment = Payment::find($paymentId);
        if (! $payment) {
            return;
        }
        $payment->update([
            'status' => $payment->status === PaymentStatus::Paid
                ? PaymentStatus::Pending->value
                : PaymentStatus::Paid->value,
            'paid_at' => $payment->status === PaymentStatus::Paid ? null : now(),
        ]);
        $this->refreshRound();
    }

    public function togglePickup(int $pickupId): void
    {
        $pickup = Pickup::find($pickupId);
        if (! $pickup) {
            return;
        }
        $pickup->update([
            'picked_up_at' => $pickup->picked_up_at ? null : now(),
        ]);
        $this->refreshRound();
    }

    public function sendDraft(int $draftId): void
    {
        $draft = $this->round->notificationDrafts()->findOrFail($draftId);
        app(DraftBuilder::class)->markSent($draft);
        Notification::make()->title('E-Mail wäre jetzt verschickt (Mailer = log).')->success()->send();
        $this->refreshRound();
    }

    public function getCalculator(): OrderCalculator
    {
        return app(OrderCalculator::class);
    }

    /* ---------- Internals ---------- */

    private function refreshRound(): void
    {
        $this->mount($this->record);
    }

    private function unitLabelForProduct(int $productId): ?string
    {
        if ($productId === 0) {
            return null;
        }
        $unit = Product::query()->where('id', $productId)->value('unit');

        return match ($unit) {
            'kg' => 'kg',
            'g' => 'g',
            'l' => 'l',
            'ml' => 'ml',
            'stk' => 'Stück',
            'glas' => 'Glas',
            'pkg' => 'Packung',
            default => $unit,
        };
    }

    /**
     * @return array<int, string>
     */
    private function participantOptions(): array
    {
        return $this->round->group->members()
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => $u->fullName().' · '.$u->email])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function memberOptions(): array
    {
        $existing = $this->round->participants->pluck('user_id')->all();

        return $this->round->group->members()
            ->whereNotIn('users.id', $existing)
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => $u->fullName().' · '.$u->email])
            ->all();
    }

    private function prefillProposal(OrderProposal $proposal): void
    {
        $items = $this->round->cartItems
            ->groupBy('product_id')
            ->each(function (Collection $group, int $productId) use ($proposal): void {
                /** @var Product|null $product */
                $product = $group->first()->product;
                if (! $product) {
                    return;
                }
                $tier = $product->priceTiers->first()
                    ?? $product->priceTiers()->first();
                if (! $tier instanceof PriceTier) {
                    return;
                }

                $result = app(Distributor::class)->compute($group, $tier);
                if ($result->packagesOrdered <= 0) {
                    return;
                }

                $item = ProposalItem::create([
                    'proposal_id' => $proposal->id,
                    'product_id' => $productId,
                    'price_tier_id' => $tier->id,
                    'packages_ordered' => $result->packagesOrdered,
                    'total_price_cents' => $result->totalPriceCents,
                    'notes' => $result->feasible ? null : implode("\n", $result->notes),
                ]);

                foreach ($result->allocations as $alloc) {
                    ProposalAllocation::create([
                        'proposal_item_id' => $item->id,
                        'user_id' => $alloc->userId,
                        'quantity' => round($alloc->allocatedQuantity, 3),
                        'share_cents' => $alloc->shareCents,
                    ]);
                }
            });
    }

    private function createPaymentsForChosenProposal(int $proposalId): void
    {
        $proposal = OrderProposal::with('items.allocations', 'round.participants')->findOrFail($proposalId);
        $totals = app(OrderCalculator::class)->calculate($proposal);

        foreach ($totals->perParticipant as $p) {
            Payment::updateOrCreate(
                ['round_id' => $this->round->id, 'user_id' => $p->userId],
                [
                    'amount_cents' => $p->subtotalCents(),
                    'round_up_donation_cents' => $p->roundUpDonationCents,
                    'status' => PaymentStatus::Pending->value,
                ],
            );

            Pickup::firstOrCreate(
                ['round_id' => $this->round->id, 'user_id' => $p->userId],
            );
        }
    }
}
