<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Models\OrderProposal;
use App\Models\RoundParticipant;
use App\Services\Rounds\ParticipantExclusion;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The last resort when several proposals fail because of single people:
 * while preparing a new version, the lead may exclude somebody who did not
 * agree before — so one person can't block everybody else — and take them
 * back in.
 */
trait InteractsWithParticipants
{
    public function excludeParticipantAction(): Action
    {
        return Action::make('excludeParticipant')
            ->label('Person ausschließen …')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->visible(function (array $arguments): bool {
                $draft = $this->proposalFromArguments($arguments);

                return $draft !== null
                    && $draft->isDraft()
                    && in_array($this->getRound()->phase, ParticipantExclusion::PHASES, true)
                    && $this->canManage()
                    && $this->exclusionCandidates($draft) !== [];
            })
            ->modalHeading('Person aus der Bestellung ausschließen?')
            ->modalDescription(fn (array $arguments): string => 'Der letzte Ausweg, wenn Vorschläge an einzelnen Personen scheitern. Die Person bleibt Mitglied der Gruppe, ihr Warenkorb zählt in dieser Runde aber nicht mehr. „'
                .($this->proposalFromArguments($arguments)?->title ?? 'Der Entwurf')
                .'“ wird ohne sie neu berechnet, freigegebene Vorschläge mit ihr werden zurückgezogen.')
            ->modalSubmitActionLabel('Ausschließen und neu berechnen')
            ->schema(fn (array $arguments): array => [
                Select::make('user_id')
                    ->label('Wen?')
                    ->helperText('Zur Wahl steht, wer einem früheren Vorschlag nicht zugestimmt oder nicht abgestimmt hat.')
                    ->options(fn (): array => $this->exclusionOptions($this->proposalFromArguments($arguments)))
                    ->required(),
                Textarea::make('reason')
                    ->label('Grund (für alle sichtbar)')
                    ->required()
                    ->minLength(ParticipantExclusion::MIN_REASON_LENGTH)
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (array $data, array $arguments): void {
                $draft = $this->proposalFromArguments($arguments);
                $user = $this->getRound()->participants->firstWhere('user_id', (int) $data['user_id'])?->user;
                $withdrawn = 0;

                $excluded = $draft && $user && $this->attempt(
                    function () use ($user, $data, &$withdrawn): void {
                        $withdrawn = app(ParticipantExclusion::class)->exclude(
                            $this->getRound(),
                            $user,
                            $data['reason'],
                            $this->currentUser(),
                        );
                    },
                    'Ausschluss nicht möglich',
                );

                if ($excluded) {
                    Notification::make()
                        ->title($user->fullName().' ist ausgeschlossen.')
                        ->body(collect([
                            '„'.$draft->title.'“ ist ohne '.$user->first_name.' neu berechnet — prüf ihn und gib ihn zur Abstimmung frei.',
                            match (true) {
                                $withdrawn === 1 => 'Ein freigegebener Vorschlag wurde zurückgezogen.',
                                $withdrawn > 1 => $withdrawn.' freigegebene Vorschläge wurden zurückgezogen.',
                                default => null,
                            },
                        ])->filter()->implode(' '))
                        ->success()
                        ->send();
                }
            });
    }

    public function readmitParticipantAction(): Action
    {
        return Action::make('readmitParticipant')
            ->label('Wieder aufnehmen')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(function (array $arguments): bool {
                $participant = $this->participantFromArguments($arguments);

                return $participant !== null
                    && $participant->removed
                    && in_array($this->getRound()->phase, ParticipantExclusion::READMIT_PHASES, true)
                    && $this->canManage();
            })
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => ($this->participantFromArguments($arguments)?->user?->fullName() ?? 'Person').' wieder aufnehmen?')
            ->modalDescription('Entwürfe werden mit der Person neu berechnet. Zurückgezogene Vorschläge bleiben zurückgezogen.')
            ->action(function (array $arguments): void {
                $participant = $this->participantFromArguments($arguments);

                $readmitted = $participant && $this->attempt(
                    fn () => app(ParticipantExclusion::class)->readmit($this->getRound(), $participant->user, $this->currentUser()),
                    'Aufnahme nicht möglich',
                );

                if ($readmitted) {
                    Notification::make()->title($participant->user->fullName().' ist wieder dabei.')->success()->send();
                }
            });
    }

    /**
     * Rarely needed actions of a draft, tucked away in a menu.
     */
    public function draftMenu(OrderProposal $draft): ActionGroup
    {
        return ActionGroup::make([
            ($this->excludeParticipantAction)(['proposal' => $draft->id]),
        ])
            ->label('Mehr zum Entwurf')
            ->icon(Heroicon::EllipsisHorizontal)
            ->iconButton()
            ->color('gray')
            ->tooltip('Mehr zum Entwurf');
    }

    /**
     * @return array<int, string>
     */
    protected function exclusionOptions(?OrderProposal $draft): array
    {
        if ($draft === null) {
            return [];
        }

        $participants = $this->getRound()->participants->keyBy('user_id');

        return collect($this->exclusionCandidates($draft))
            ->mapWithKeys(fn (string $disagreement, int $userId): array => [
                $userId => ($participants->get($userId)?->user?->fullName() ?? '—').' — '.$disagreement,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function participantFromArguments(array $arguments): ?RoundParticipant
    {
        return $this->getRound()->participants->firstWhere('id', (int) ($arguments['participant'] ?? 0));
    }
}
