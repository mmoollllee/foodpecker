<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Filament\Resources\Rounds\RoundResource;
use App\Models\User;
use App\Services\Rounds\LeadHandover;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Handing the lead role over — only with the consent of the new lead.
 */
trait InteractsWithLeadHandover
{
    public function requestLeadHandoverAction(): Action
    {
        return Action::make('requestLeadHandover')
            ->label('Lead-Rolle übergeben')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (): bool => $this->canManage()
                && $this->getRound()->phase->isActive()
                && $this->getRound()->pending_lead_user_id === null)
            ->modalHeading('Lead-Rolle übergeben')
            ->modalDescription('Die Übergabe gilt erst, wenn die Person zustimmt. Sie bekommt dafür eine Mail. Mit der Rolle wandert auch die Aufwandsentschädigung — sie steht dem Lead zu, der die Runde zu Ende bringt.')
            ->modalSubmitActionLabel('Anfragen')
            ->schema([
                Select::make('user_id')
                    ->label('An wen?')
                    ->options(fn (): array => $this->getRound()->group->memberOptions([
                        $this->getRound()->lead_user_id,
                        ...$this->getRound()->participants->where('removed', true)->pluck('user_id')->all(),
                    ]))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $nominee = $this->getRound()->group->members()->whereKey((int) $data['user_id'])->first();

                $requested = $nominee instanceof User && $this->attempt(
                    fn () => app(LeadHandover::class)->request(
                        $this->getRound(),
                        $nominee,
                        $this->currentUser(),
                        RoundResource::getUrl('view', ['record' => $this->getRound()]),
                    ),
                    'Übergabe nicht möglich',
                );

                if ($requested) {
                    Notification::make()
                        ->title('Anfrage an '.$nominee->fullName().' verschickt.')
                        ->body('Bis zur Zustimmung bleibst du Lead.')
                        ->success()
                        ->send();
                }
            });
    }

    public function cancelLeadHandoverAction(): Action
    {
        return Action::make('cancelLeadHandover')
            ->label('Übergabe zurückziehen')
            ->icon(Heroicon::OutlinedXMark)
            ->visible(fn (): bool => $this->canManage() && $this->getRound()->pending_lead_user_id !== null)
            ->requiresConfirmation()
            ->modalHeading('Lead-Übergabe zurückziehen?')
            ->action(function (): void {
                $this->attempt(
                    fn () => app(LeadHandover::class)->cancel($this->getRound(), $this->currentUser()),
                    'Zurückziehen nicht möglich',
                );
            });
    }

    public function acceptLeadHandoverAction(): Action
    {
        return Action::make('acceptLeadHandover')
            ->label('Lead-Rolle übernehmen')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (): bool => $this->getRound()->pending_lead_user_id === $this->currentUser()->id)
            ->requiresConfirmation()
            ->modalHeading('Lead-Rolle übernehmen?')
            ->modalDescription(fn (): string => 'Du koordinierst die Runde ab jetzt statt '.($this->getRound()->lead?->fullName() ?? 'des bisherigen Leads').' und bekommst die Aufwandsentschädigung.')
            ->modalSubmitActionLabel('Übernehmen')
            ->action(function (): void {
                $accepted = $this->attempt(
                    fn () => app(LeadHandover::class)->accept($this->getRound(), $this->currentUser()),
                    'Übernahme nicht möglich',
                );

                if ($accepted) {
                    Notification::make()->title('Du bist jetzt Lead dieser Runde.')->success()->send();
                }
            });
    }

    public function declineLeadHandoverAction(): Action
    {
        return Action::make('declineLeadHandover')
            ->label('Ablehnen')
            ->color('gray')
            ->visible(fn (): bool => $this->getRound()->pending_lead_user_id === $this->currentUser()->id)
            ->requiresConfirmation()
            ->modalHeading('Lead-Rolle ablehnen?')
            ->action(function (): void {
                $this->attempt(
                    fn () => app(LeadHandover::class)->decline($this->getRound(), $this->currentUser()),
                    'Ablehnen nicht möglich',
                );
            });
    }
}
