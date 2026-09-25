<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\NotificationKind;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\Schemas\RoundForm;
use App\Models\Round;
use App\Services\Notifications\DraftBuilder;
use App\Services\Rounds\PhaseTransitioner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Phase changes of a round: start, next step, step back, cancel.
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
            ->modalSubmitActionLabel('Ja, jetzt starten')
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled($this->missingRequirements(RoundPhase::Shopping) !== []))
            ->schema([
                $this->prepareNotificationToggle(),
            ])
            ->action(fn (array $data) => $this->switchPhase(RoundPhase::Shopping, null, (bool) ($data['prepare_notification'] ?? false)));
    }

    public function nextPhaseAction(): Action
    {
        return Action::make('nextPhase')
            ->label(fn (): string => 'Weiter zu: '.($this->nextPhase()?->getLabel() ?? '—'))
            ->icon(fn () => $this->nextPhase()?->getIcon() ?? Heroicon::OutlinedForward)
            ->color('primary')
            ->visible(fn (): bool => $this->getRound()->phase !== RoundPhase::Draft
                && $this->nextPhase() !== null
                && $this->canManage())
            ->modalHeading(fn (): string => 'Weiter zu: '.$this->nextPhase()?->getLabel())
            ->modalDescription(fn (): Htmlable => $this->phaseChecklist($this->nextPhase()))
            ->modalSubmitActionLabel('Phase wechseln')
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled(
                $this->nextPhase() === null || $this->missingRequirements($this->nextPhase()) !== [],
            ))
            ->schema([
                Textarea::make('reason')
                    ->label('Kommentar für den Verlauf (optional)')
                    ->rows(2)
                    ->maxLength(500),
                $this->prepareNotificationToggle(),
            ])
            ->action(function (array $data): void {
                if ($next = $this->nextPhase()) {
                    $this->switchPhase($next, $data['reason'] ?? null, (bool) ($data['prepare_notification'] ?? false));
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
                    $this->switchPhase($previous, $data['reason'] ?? null, false);
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
            ->schema([
                Textarea::make('reason')
                    ->label('Grund')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->rows(3),
                $this->prepareNotificationToggle(),
            ])
            ->action(fn (array $data) => $this->switchPhase(RoundPhase::Cancelled, $data['reason'], (bool) ($data['prepare_notification'] ?? false)));
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

    protected function nextPhase(): ?RoundPhase
    {
        return app(PhaseTransitioner::class)->nextPhase($this->getRound());
    }

    protected function previousPhase(): ?RoundPhase
    {
        return app(PhaseTransitioner::class)->previousPhase($this->getRound());
    }

    /**
     * @return array<int, string>
     */
    protected function missingRequirements(?RoundPhase $phase): array
    {
        return $phase ? app(PhaseTransitioner::class)->missingRequirements($this->getRound(), $phase) : [];
    }

    protected function phaseChecklist(?RoundPhase $phase, ?string $intro = null): Htmlable
    {
        $missing = $this->missingRequirements($phase);

        return new HtmlString(view('filament.rounds.partials.phase-checklist', [
            'intro' => $intro,
            'missing' => $missing,
        ])->render());
    }

    protected function prepareNotificationToggle(): Toggle
    {
        return Toggle::make('prepare_notification')
            ->label('Danach Benachrichtigung an die Gruppe vorbereiten')
            ->default(true);
    }

    protected function switchPhase(RoundPhase $to, ?string $reason, bool $prepareNotification): void
    {
        $switched = $this->attempt(
            fn () => app(PhaseTransitioner::class)->transition($this->getRound(), $to, $this->currentUser(), $reason),
            'Phasenwechsel nicht möglich',
        );

        if (! $switched) {
            return;
        }

        $this->selectedPhase = null;

        Notification::make()
            ->title('Phase: '.$to->getLabel())
            ->success()
            ->send();

        if ($prepareNotification) {
            $draft = app(DraftBuilder::class)->buildDraft($this->getRound(), NotificationKind::forPhase($to), $this->currentUser());
            $this->refreshRound();
            $this->replaceMountedAction('sendDraft', ['draft' => $draft->id]);
        }
    }
}
