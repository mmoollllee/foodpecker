<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\NotificationKind;
use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Filament\Resources\Rounds\Schemas\RoundForm;
use App\Models\CartItem;
use App\Models\NotificationDraft;
use App\Models\OrderProposal;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\RoundSupplier;
use App\Models\Supplier;
use App\Services\Notifications\DraftBuilder;
use App\Services\Notifications\DraftSender;
use App\Services\Proposals\ProposalWorkflow;
use App\Services\Rounds\PhaseTransitioner;
use App\Services\Rounds\SupplierFeedback;
use App\Services\Rounds\SupplierOrders;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Phase changes of a round: start, next step, step back, cancel. The
 * notification to the group is written in the same dialog.
 */
trait InteractsWithPhases
{
    public function startRoundAction(): Action
    {
        return Action::make('startRound')
            ->label('Bestellrunde starten')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (): bool => $this->getRound()->phase === RoundPhase::Draft && $this->canManage())
            ->modalHeading('Bestellrunde starten?')
            ->modalDescription(fn (): Htmlable => $this->phaseChecklist(RoundPhase::Shopping, 'Die Einkaufsphase wird sofort eröffnet, alle Mitglieder sehen die Runde und können Warenkörbe füllen.'))
            ->modalSubmitActionLabel('Jetzt starten')
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled($this->missingRequirements(RoundPhase::Shopping) !== []))
            ->modalWidth('3xl')
            ->fillForm(fn (): array => $this->notificationDefaults(RoundPhase::Shopping))
            ->schema($this->notificationFields())
            ->action(fn (array $data) => $this->switchPhase(RoundPhase::Shopping, null, $data));
    }

    /**
     * Moves the round on. From the adjustment phase this puts the order
     * proposal up for a vote in the same step.
     */
    public function nextPhaseAction(): Action
    {
        return Action::make('nextPhase')
            ->label(fn (): string => $this->nextPhaseLabel())
            ->icon(fn () => $this->getRound()->phase === RoundPhase::Negotiating ? Heroicon::OutlinedMegaphone : ($this->nextPhase()?->getIcon() ?? Heroicon::OutlinedForward))
            ->color('primary')
            ->visible(fn (): bool => $this->getRound()->phase !== RoundPhase::Draft
                && $this->nextPhase() !== null
                && $this->canManage())
            ->modalHeading(fn (): string => $this->nextPhaseLabel())
            ->modalDescription(fn (): Htmlable => $this->phaseChecklist($this->nextPhase(), $this->nextPhaseIntro(), $this->phaseHints($this->nextPhase())))
            ->modalSubmitActionLabel('Phase wechseln')
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled(
                $this->nextPhase() === null || $this->missingRequirements($this->nextPhase()) !== [],
            ))
            ->modalWidth('3xl')
            ->fillForm(fn (): array => ($next = $this->nextPhase()) ? $this->notificationDefaults($next) : [])
            ->schema([
                Textarea::make('reason')
                    ->label('Kommentar für den Verlauf (optional)')
                    ->rows(2)
                    ->maxLength(500),
                ...$this->notificationFields(),
            ])
            ->action(function (array $data): void {
                if ($next = $this->nextPhase()) {
                    $this->switchPhase($next, $data['reason'] ?? null, $data);
                }
            });
    }

    public function previousPhaseAction(): Action
    {
        return Action::make('previousPhase')
            ->label(fn (): string => 'Zurück zu: '.($this->previousPhase()?->getLabel() ?? '—'))
            ->icon(Heroicon::OutlinedBackward)
            ->visible(fn (): bool => $this->previousPhase() !== null && $this->canManage())
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Zurück zu: '.$this->previousPhase()?->getLabel().'?')
            ->modalDescription(fn (): string => $this->getRound()->phase === RoundPhase::Finalizing && $this->getRound()->chosen_proposal_id
                ? 'Die Wahl der finalen Bestellung wird dabei aufgehoben, offene Zahlungen werden gelöscht.'
                : 'Für Korrekturen kannst du einen Schritt zurückgehen.')
            ->schema([
                Textarea::make('reason')
                    ->label('Warum? (optional)')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                if ($previous = $this->previousPhase()) {
                    $this->switchPhase($previous, $data['reason'] ?? null);
                }
            });
    }

    public function cancelRoundAction(): Action
    {
        return Action::make('cancelRound')
            ->label('Runde abbrechen')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (): bool => $this->getRound()->phase->canTransitionTo(RoundPhase::Cancelled) && $this->canManage())
            ->modalHeading('Runde abbrechen?')
            ->modalDescription('Die Runde bleibt in der Historie sichtbar, es kann aber nichts mehr bestellt werden.')
            ->modalSubmitActionLabel('Runde abbrechen')
            ->modalWidth('3xl')
            ->fillForm(fn (): array => $this->notificationDefaults(RoundPhase::Cancelled))
            ->schema([
                Textarea::make('reason')
                    ->label('Grund')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->rows(3),
                ...$this->notificationFields(),
            ])
            ->action(fn (array $data) => $this->switchPhase(RoundPhase::Cancelled, $data['reason'], $data));
    }

    public function editRoundAction(): Action
    {
        return Action::make('editRound')
            ->label('Eckdaten bearbeiten')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->outlined()
            ->authorize('update')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Speichern')
            ->fillForm(fn (Round $record): array => [
                ...$record->attributesToArray(),
                'available_products' => $record->availableProducts->pluck('id')->all(),
            ])
            ->schema(fn (Schema $schema): Schema => RoundForm::configure($schema))
            ->action(function (array $data, Round $record): void {
                $record->update(collect($data)->except(['available_products', 'pickupDates'])->all());
                $record->logActivity('updated');

                Notification::make()->title('Bestellrunde aktualisiert.')->success()->send();
                $this->refreshRound();
            });
    }

    public function deleteRoundAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Runde löschen')
            ->modalDescription('Nur Entwürfe und abgebrochene Runden können gelöscht werden. Das lässt sich nicht rückgängig machen.');
    }

    /**
     * The draft that goes up for a vote when the adjustment phase ends: the
     * lead's newest one. Counter-drafts of others go up only by their own
     * proposers' hand.
     */
    public function draftForVote(): ?OrderProposal
    {
        $round = $this->getRound();

        return $round->proposals
            ->where('status', ProposalStatus::Draft)
            ->where('proposed_by_user_id', $round->lead_user_id)
            ->sortByDesc('id')
            ->first();
    }

    protected function nextPhase(): ?RoundPhase
    {
        return app(PhaseTransitioner::class)->nextPhase($this->getRound());
    }

    protected function previousPhase(): ?RoundPhase
    {
        return app(PhaseTransitioner::class)->previousPhase($this->getRound());
    }

    protected function nextPhaseLabel(): string
    {
        return $this->getRound()->phase === RoundPhase::Negotiating
            ? 'Zur Abstimmung stellen'
            : 'Weiter zu: '.($this->nextPhase()?->getLabel() ?? '—');
    }

    protected function nextPhaseIntro(): ?string
    {
        return $this->getRound()->phase === RoundPhase::Negotiating
            ? 'Der Bestellvorschlag geht zur Abstimmung und lässt sich danach nicht mehr ändern. Alle, die etwas bestellt haben, stimmen pro Position ab.'
            : null;
    }

    /**
     * What is still missing. Before the vote the order proposal itself has
     * to be ready, so its problems count too.
     *
     * @return array<int, string>
     */
    protected function missingRequirements(?RoundPhase $phase): array
    {
        if ($phase === null) {
            return [];
        }

        $round = $this->getRound();
        $draft = $round->phase === RoundPhase::Negotiating && $phase === RoundPhase::Finalizing ? $this->draftForVote() : null;

        if ($draft === null) {
            return app(PhaseTransitioner::class)->missingRequirements($round, $phase);
        }

        if ($draft->items->isEmpty()) {
            return ['Der Bestellvorschlag hat noch keine Positionen.'];
        }

        return $draft->items
            ->map(fn (ProposalItem $item): ?string => $item->blockingProblem())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Things worth a second look that don't stop the phase change.
     *
     * @return array<int, string>
     */
    protected function phaseHints(?RoundPhase $phase): array
    {
        $round = $this->getRound();

        return match (true) {
            $round->phase === RoundPhase::Negotiating && $phase === RoundPhase::Finalizing => $this->adjustmentHints(),
            $round->phase === RoundPhase::Ordering && $phase === RoundPhase::Delivery => app(SupplierOrders::class)->notOrdered($round)
                ->map(fn (Supplier $supplier): string => "Bei „{$supplier->name}“ ist noch nicht abgehakt, dass bestellt ist.")
                ->all(),
            $round->phase === RoundPhase::Delivery && $phase === RoundPhase::Pickup => app(SupplierOrders::class)->notDelivered($round)
                ->map(fn (Supplier $supplier): string => "Von „{$supplier->name}“ ist noch nicht abgehakt, dass die Ware da ist.")
                ->all(),
            default => [],
        };
    }

    /**
     * Before the vote: suppliers that haven't answered and goods left over.
     *
     * @return array<int, string>
     */
    private function adjustmentHints(): array
    {
        $round = $this->getRound();
        $answered = $round->roundSuppliers->filter(fn (RoundSupplier $record): bool => $record->hasResponded())->pluck('supplier_id');
        $hints = app(SupplierFeedback::class)->suppliersFor($round)
            ->reject(fn (Supplier $supplier): bool => $answered->contains($supplier->id))
            ->map(fn (Supplier $supplier): string => "Von „{$supplier->name}“ fehlt noch die Rückmeldung — es gelten die Listenpreise.")
            ->values()
            ->all();

        foreach ($this->draftForVote()?->items ?? [] as $item) {
            if ($item->overhang() > 0.001) {
                $hints[] = sprintf(
                    '%s: %s %s übrig und werden anteilig mitbezahlt.',
                    $item->product?->name,
                    CartItem::formatAmount($item->overhang(), $item->product?->unitLabel()),
                    abs($item->overhang() - 1) < 0.0005 ? 'bleibt' : 'bleiben',
                );
            }
        }

        return $hints;
    }

    /**
     * @param  array<int, string>  $hints
     */
    protected function phaseChecklist(?RoundPhase $phase, ?string $intro = null, array $hints = []): Htmlable
    {
        return new HtmlString(view('filament.rounds.partials.phase-checklist', [
            'intro' => $intro,
            'missing' => $this->missingRequirements($phase),
            'hints' => $hints,
        ])->render());
    }

    /**
     * The notification to the group, written right in the dialog.
     *
     * @return array<int, mixed>
     */
    protected function notificationFields(): array
    {
        return [
            Toggle::make('notify')
                ->label('Gruppe per E-Mail benachrichtigen')
                ->default(true)
                ->live(),
            TextInput::make('subject')
                ->label('Betreff')
                ->required(fn (Get $get): bool => (bool) $get('notify'))
                ->maxLength(255)
                ->visible(fn (Get $get): bool => (bool) $get('notify')),
            MarkdownEditor::make('body')
                ->label('Nachricht')
                ->required(fn (Get $get): bool => (bool) $get('notify'))
                ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                ->visible(fn (Get $get): bool => (bool) $get('notify')),
            Toggle::make('all_members')
                ->label('An alle Gruppenmitglieder (statt nur an die Teilnehmer der Runde)')
                ->visible(fn (Get $get): bool => (bool) $get('notify')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notificationDefaults(RoundPhase $to): array
    {
        return [
            'notify' => true,
            ...app(DraftBuilder::class)->compose($this->getRound(), NotificationKind::forPhase($to), $this->currentUser()),
            'all_members' => in_array($to, [RoundPhase::Shopping, RoundPhase::Cancelled], true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Notification fields of the dialog.
     */
    protected function switchPhase(RoundPhase $to, ?string $reason, array $data = []): void
    {
        $switched = $this->attempt(function () use ($to, $reason): void {
            DB::transaction(function () use ($to, $reason): void {
                $round = $this->getRound();
                $draft = $round->phase === RoundPhase::Negotiating && $to === RoundPhase::Finalizing ? $this->draftForVote() : null;

                if ($draft !== null) {
                    app(ProposalWorkflow::class)->publish($draft, $this->currentUser());
                }

                app(PhaseTransitioner::class)->transition($round, $to, $this->currentUser(), $reason);
            });
        }, 'Phasenwechsel nicht möglich');

        if (! $switched) {
            return;
        }

        $this->selectedPhase = null;

        Notification::make()
            ->title('Phase: '.$to->getLabel())
            ->success()
            ->send();

        if ($data['notify'] ?? false) {
            $this->sendNotification(NotificationKind::forPhase($to), $data);
        }
    }

    /**
     * Sends what was written in a dialog to the group. It stays in the
     * history as a sent notification.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sendNotification(NotificationKind $kind, array $data): void
    {
        $draft = NotificationDraft::create([
            'round_id' => $this->getRound()->id,
            'prepared_by_user_id' => $this->currentUser()->id,
            'kind' => $kind,
            'subject' => (string) $data['subject'],
            'body' => (string) $data['body'],
            'generated_at' => now(),
        ]);

        $result = ['sent' => 0, 'failed' => []];

        $sent = $this->attempt(
            function () use ($draft, $data, &$result): void {
                $sender = app(DraftSender::class);
                $result = $sender->send(
                    $draft,
                    $sender->recipients($this->getRound(), (bool) ($data['all_members'] ?? false)),
                    $this->currentUser(),
                    RoundResource::getUrl('view', ['record' => $this->getRound()]),
                );
            },
            'Benachrichtigung nicht verschickt',
        );

        if ($sent) {
            $this->reportSentNotification($result);
        }
    }
}
