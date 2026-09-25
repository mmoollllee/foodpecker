<?php

namespace App\Filament\Resources\Rounds\Pages\Concerns;

use App\Enums\NotificationKind;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\NotificationDraft;
use App\Models\User;
use App\Services\Notifications\DraftBuilder;
use App\Services\Notifications\DraftSender;
use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Manual notifications: the system drafts a summary of what changed, the
 * lead edits it and sends it by mail — no automatic spam.
 */
trait InteractsWithNotifications
{
    public function generateNotificationAction(): Action
    {
        return Action::make('generateNotification')
            ->label('Benachrichtigung vorbereiten')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (): bool => $this->canManage())
            ->modalDescription('Foodpecker erzeugt einen Entwurf mit allem, was sich seit der letzten Nachricht geändert hat. Du kannst ihn vor dem Versand anpassen.')
            ->schema([
                Select::make('kind')
                    ->label('Anlass')
                    ->options(NotificationKind::class)
                    ->default(fn (): string => NotificationKind::forPhase($this->getRound()->phase)->value)
                    ->required(),
            ])
            ->modalSubmitActionLabel('Entwurf erzeugen')
            ->action(function (array $data): void {
                $kind = $data['kind'] instanceof NotificationKind ? $data['kind'] : NotificationKind::from($data['kind']);
                $draft = app(DraftBuilder::class)->buildDraft($this->getRound(), $kind, $this->currentUser());

                $this->refreshRound();
                $this->replaceMountedAction('sendDraft', ['draft' => $draft->id]);
            });
    }

    public function sendDraftAction(): Action
    {
        return Action::make('sendDraft')
            ->label('Ansehen & senden')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('info')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->draftFromArguments($arguments)?->isSent() === false && $this->canManage())
            ->modalHeading('Benachrichtigung senden')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Jetzt senden')
            ->fillForm(fn (array $arguments): array => [
                ...($this->draftFromArguments($arguments)?->only(['subject', 'body']) ?? []),
                'all_members' => app(DraftSender::class)->defaultsToAllMembers($this->getRound()),
            ])
            ->schema([
                TextInput::make('subject')
                    ->label('Betreff')
                    ->required()
                    ->maxLength(255),
                MarkdownEditor::make('body')
                    ->label('Nachricht')
                    ->required()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
                Toggle::make('all_members')
                    ->label('An alle Gruppenmitglieder (statt nur an die Teilnehmer der Runde)')
                    ->live(),
                Text::make(fn (Get $get): string => 'Empfänger: '.$this->recipientNames((bool) $get('all_members'))),
            ])
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveOnly', arguments: ['save_only' => true])
                    ->label('Nur speichern')
                    ->color('gray'),
            ])
            ->action(function (array $data, array $arguments): void {
                $draft = $this->draftFromArguments($arguments);

                if (! $draft) {
                    return;
                }

                $draft->update(['subject' => $data['subject'], 'body' => $data['body']]);

                if ($arguments['save_only'] ?? false) {
                    Notification::make()->title('Entwurf gespeichert.')->success()->send();
                    $this->refreshRound();

                    return;
                }

                $result = ['sent' => 0, 'failed' => []];

                $sent = $this->attempt(
                    function () use ($draft, $data, &$result): void {
                        $sender = app(DraftSender::class);
                        $result = $sender->send(
                            $draft,
                            $sender->recipients($this->getRound(), (bool) $data['all_members']),
                            $this->currentUser(),
                            RoundResource::getUrl('view', ['record' => $this->getRound()]),
                        );
                    },
                    'Versand nicht möglich',
                );

                if (! $sent) {
                    return;
                }

                if ($result['failed'] !== []) {
                    Notification::make()
                        ->title("Benachrichtigung an {$result['sent']} Personen verschickt.")
                        ->body('Nicht zugestellt an: '.implode(', ', $result['failed']).'. Sag ihnen am besten direkt Bescheid.')
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Benachrichtigung an {$result['sent']} Personen verschickt.")
                    ->body(config('mail.default') === 'log' ? 'Hinweis: Der Mailer steht auf „log“ — die Mails landen im Log statt im Postfach.' : null)
                    ->success()
                    ->send();
            });
    }

    public function deleteDraftAction(): Action
    {
        return Action::make('deleteDraft')
            ->label('Verwerfen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->draftFromArguments($arguments)?->isSent() === false && $this->canManage())
            ->requiresConfirmation()
            ->modalHeading('Entwurf verwerfen?')
            ->action(function (array $arguments): void {
                $this->draftFromArguments($arguments)?->delete();
                $this->refreshRound();
            });
    }

    protected function recipientNames(bool $allMembers): string
    {
        $recipients = app(DraftSender::class)->recipients($this->getRound(), $allMembers);

        return $recipients->isEmpty()
            ? 'niemand'
            : $recipients->map(fn (User $user): string => $user->fullName())->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function draftFromArguments(array $arguments): ?NotificationDraft
    {
        return $this->getRound()->notificationDrafts->firstWhere('id', (int) ($arguments['draft'] ?? 0));
    }
}
