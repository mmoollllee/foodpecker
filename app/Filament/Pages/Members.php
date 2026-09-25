<?php

namespace App\Filament\Pages;

use App\Enums\GroupRole;
use App\Filament\Concerns\ResolvesAuthorNames;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Services\Groups\GroupMembership;
use App\Services\Groups\OwnerTransfer;
use App\Services\Invitations\GroupInvitationService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Members, invitations and history of the current group. Owner and
 * moderators invite and assign roles; only the owner removes people and
 * hands the group over; everybody else may leave.
 */
class Members extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use ResolvesAuthorNames;

    /**
     * Invitations name the invited addresses — like the list of open
     * invitations, only people who may invite see them in the history.
     *
     * @var array<int, string>
     */
    protected const INVITATION_ACTIVITIES = ['invited', 'invitation_withdrawn'];

    protected const MAX_INVITATIONS_AT_ONCE = 50;

    protected string $view = 'filament.pages.members';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Mitglieder & Einladungen';

    protected static ?string $title = 'Mitglieder & Einladungen';

    protected static string|\UnitEnum|null $navigationGroup = 'Gruppe';

    protected static ?int $navigationSort = 50;

    public function getTitle(): string|Htmlable
    {
        return 'Mitglieder & Einladungen';
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')
            ->label('Mitglieder einladen')
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->visible(fn (): bool => $this->currentUser()->can('invite', $this->getGroup()))
            ->modalHeading('Mitglieder einladen')
            ->modalDescription(fn (): string => 'Alle bekommen eine Mail mit einem persönlichen Link, der '.config('foodpecker.invitations.expires_after_days').' Tage gilt.')
            ->modalSubmitActionLabel('Einladen')
            ->schema([
                Textarea::make('emails')
                    ->label('E-Mail-Adressen')
                    ->helperText('Mehrere Adressen durch Komma getrennt — auch direkt aus dem Mailprogramm kopiert.')
                    ->placeholder('anna@example.org, ben@example.org')
                    ->rows(3)
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $addresses = GroupInvitationService::parseAddresses((string) $value);

                        if ($addresses['invalid'] !== []) {
                            $fail('Das sind keine gültigen E-Mail-Adressen: '.implode(', ', $addresses['invalid']).'.');
                        } elseif ($addresses['valid'] === []) {
                            $fail('Bitte mindestens eine E-Mail-Adresse eingeben.');
                        } elseif (count($addresses['valid']) > self::MAX_INVITATIONS_AT_ONCE) {
                            $fail('Höchstens '.self::MAX_INVITATIONS_AT_ONCE.' Adressen auf einmal.');
                        }
                    }),
                Select::make('role')
                    ->label('Rolle')
                    ->helperText('Gilt für alle Adressen.')
                    ->options(collect(GroupRole::assignable())->mapWithKeys(fn (GroupRole $role): array => [$role->value => $role->getLabel()]))
                    ->default(GroupRole::Participant->value)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $result = app(GroupInvitationService::class)->inviteMany(
                    $this->getGroup(),
                    GroupInvitationService::parseAddresses($data['emails'])['valid'],
                    GroupRole::from($data['role']),
                    $this->currentUser(),
                );

                $this->notifyAboutInvitations($result);
            });
    }

    /**
     * @param  array{invited: array<int, string>, members: array<int, string>, failed: array<int, string>}  $result
     */
    protected function notifyAboutInvitations(array $result): void
    {
        $invited = count($result['invited']);

        $details = array_filter([
            $result['members'] !== [] ? 'Schon Mitglied: '.implode(', ', $result['members']).'.' : null,
            $result['failed'] !== [] ? 'Mail nicht verschickt — bitte unten „Nochmal senden“: '.implode(', ', $result['failed']).'.' : null,
            $invited > 0 ? $this->logMailerHint() : null,
        ]);

        Notification::make()
            ->title(match (true) {
                $invited === 1 => 'Einladung an '.$result['invited'][0].' verschickt.',
                $invited > 1 => $invited.' Einladungen verschickt.',
                $result['failed'] !== [] => 'Die Einladungen konnten nicht verschickt werden.',
                count($result['members']) === 1 => 'Diese Person ist schon Mitglied der Gruppe.',
                default => 'Diese Personen sind schon Mitglied der Gruppe.',
            })
            ->body($details !== [] ? implode(' ', $details) : null)
            ->status($invited > 0 && $result['failed'] === [] ? 'success' : 'warning')
            ->send();
    }

    /**
     * The role badge in the member list is the switch: one click turns a
     * participant into a moderator and back. Only stepping down yourself
     * asks first — nobody but the owner or another moderator can undo it.
     */
    public function toggleRoleAction(): Action
    {
        return Action::make('toggleRole')
            ->badge()
            ->size('sm')
            ->label(fn (array $arguments): ?string => $this->shownRoleFromArguments($arguments)?->getLabel())
            ->color(fn (array $arguments): string => $this->shownRoleFromArguments($arguments)?->getColor() ?? 'gray')
            ->tooltip(fn (array $arguments): ?string => ($role = $this->switchedRoleFromArguments($arguments)) ? 'Zum '.$role->getLabel().' machen' : null)
            ->extraAttributes(['class' => 'transition hover:ring-2 hover:ring-primary-500/50'])
            ->visible(fn (array $arguments): bool => $this->currentUser()->can('manageMembers', $this->getGroup())
                && ($member = $this->memberFromArguments($arguments)) !== null
                && ! $member->isOwnerOf($this->getGroup()))
            ->requiresConfirmation()
            ->modal(fn (array $arguments): bool => (int) ($arguments['member'] ?? 0) === $this->currentUser()->id)
            ->modalHeading('Moderator-Rolle abgeben?')
            ->modalDescription('Du bist danach Teilnehmer. Zurück zum Moderator macht dich nur der Owner oder ein anderer Moderator.')
            ->modalSubmitActionLabel('Abgeben')
            ->action(function (array $arguments): void {
                $member = $this->memberFromArguments($arguments);
                $role = $this->switchedRoleFromArguments($arguments);

                $changed = $member instanceof User && $role instanceof GroupRole && $this->attempt(
                    fn () => app(GroupMembership::class)->changeRole($this->getGroup(), $member, $role, $this->currentUser()),
                    'Rolle nicht geändert',
                );

                if ($changed) {
                    Notification::make()->title($member->fullName().' ist jetzt '.$role->getLabel().'.')->success()->send();
                }
            });
    }

    public function removeMemberAction(): Action
    {
        return Action::make('removeMember')
            ->label('Entfernen')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => ($member = $this->memberFromArguments($arguments)) !== null
                && $this->currentUser()->can('removeMember', [$this->getGroup(), $member]))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => ($this->memberFromArguments($arguments)?->fullName() ?? 'Mitglied').' aus der Gruppe entfernen?')
            ->modalDescription('Laufende Bestellungen bleiben erhalten; für neue Runden braucht die Person eine neue Einladung.')
            ->action(function (array $arguments): void {
                $member = $this->memberFromArguments($arguments);

                $removed = $member instanceof User && $this->attempt(
                    fn () => app(GroupMembership::class)->remove($this->getGroup(), $member, $this->currentUser()),
                    'Entfernen nicht möglich',
                );

                if ($removed) {
                    Notification::make()->title($member->fullName().' wurde aus der Gruppe entfernt.')->success()->send();
                }
            });
    }

    public function leaveGroupAction(): Action
    {
        return Action::make('leaveGroup')
            ->label('Gruppe verlassen')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('gray')
            ->visible(fn (): bool => $this->currentUser()->can('leave', $this->getGroup()))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => '„'.$this->getGroup()->name.'“ verlassen?')
            ->modalDescription('Du siehst die Gruppe danach nicht mehr. Zurück kommst du nur mit einer neuen Einladung.')
            ->modalSubmitActionLabel('Verlassen')
            ->action(function () {
                $group = $this->getGroup();

                if (! $this->attempt(fn () => app(GroupMembership::class)->leave($group, $this->currentUser()), 'Verlassen nicht möglich')) {
                    return null;
                }

                Notification::make()->title('Du hast „'.$group->name.'“ verlassen.')->success()->send();

                return redirect(Filament::getUrl());
            });
    }

    public function transferOwnershipAction(): Action
    {
        return Action::make('transferOwnership')
            ->label('Owner-Rolle übergeben')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (): bool => $this->currentUser()->can('transferOwnership', $this->getGroup())
                && $this->getGroup()->pending_owner_id === null
                && $this->getGroup()->members()->whereKeyNot($this->getGroup()->owner_id)->exists())
            ->modalHeading('Owner-Rolle übergeben')
            ->modalDescription('Die Übergabe gilt erst, wenn die Person zustimmt. Sie bekommt dafür eine Mail. Du bleibst danach als Moderator in der Gruppe und kannst sie dann auch verlassen.')
            ->modalSubmitActionLabel('Anfragen')
            ->schema([
                Select::make('user_id')
                    ->label('An wen?')
                    ->options(fn (): array => $this->getGroup()->memberOptions([$this->getGroup()->owner_id]))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $nominee = $this->getGroup()->members()->whereKey((int) $data['user_id'])->first();

                $requested = $nominee instanceof User && $this->attempt(
                    fn () => app(OwnerTransfer::class)->request($this->getGroup(), $nominee, $this->currentUser(), static::getUrl()),
                    'Übergabe nicht möglich',
                );

                if ($requested) {
                    Notification::make()
                        ->title('Anfrage an '.$nominee->fullName().' verschickt.')
                        ->body('Bis zur Zustimmung bleibst du Owner.')
                        ->success()
                        ->send();
                }
            });
    }

    public function cancelOwnerTransferAction(): Action
    {
        return Action::make('cancelOwnerTransfer')
            ->label('Übergabe zurückziehen')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (): bool => $this->currentUser()->can('transferOwnership', $this->getGroup())
                && $this->getGroup()->pending_owner_id !== null)
            ->requiresConfirmation()
            ->modalHeading('Owner-Übergabe zurückziehen?')
            ->action(function (): void {
                $this->attempt(
                    fn () => app(OwnerTransfer::class)->cancel($this->getGroup(), $this->currentUser()),
                    'Zurückziehen nicht möglich',
                );
            });
    }

    public function acceptOwnerTransferAction(): Action
    {
        return Action::make('acceptOwnerTransfer')
            ->label('Owner-Rolle übernehmen')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (): bool => $this->getGroup()->pending_owner_id === $this->currentUser()->id)
            ->requiresConfirmation()
            ->modalHeading('Owner-Rolle übernehmen?')
            ->modalDescription(fn (): string => 'Du hast ab jetzt alle Rechte in der Gruppe — auch Mitglieder zu entfernen und die Gruppe aufzulösen. '
                .($this->getGroup()->owner?->fullName() ?? 'Der bisherige Owner').' bleibt als Moderator dabei.')
            ->modalSubmitActionLabel('Übernehmen')
            ->action(function (): void {
                $accepted = $this->attempt(
                    fn () => app(OwnerTransfer::class)->accept($this->getGroup(), $this->currentUser()),
                    'Übernahme nicht möglich',
                );

                if ($accepted) {
                    Notification::make()->title('Du bist jetzt Owner der Gruppe.')->success()->send();
                }
            });
    }

    public function declineOwnerTransferAction(): Action
    {
        return Action::make('declineOwnerTransfer')
            ->label('Ablehnen')
            ->color('gray')
            ->visible(fn (): bool => $this->getGroup()->pending_owner_id === $this->currentUser()->id)
            ->requiresConfirmation()
            ->modalHeading('Owner-Rolle ablehnen?')
            ->action(function (): void {
                $this->attempt(
                    fn () => app(OwnerTransfer::class)->decline($this->getGroup(), $this->currentUser()),
                    'Ablehnen nicht möglich',
                );
            });
    }

    public function resendInvitationAction(): Action
    {
        return Action::make('resendInvitation')
            ->label('Nochmal senden')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->invitationFromArguments($arguments) !== null
                && $this->currentUser()->can('invite', $this->getGroup()))
            ->action(function (array $arguments): void {
                $invitation = $this->invitationFromArguments($arguments);

                if (! $invitation) {
                    return;
                }

                app(GroupInvitationService::class)->resend($invitation);

                Notification::make()
                    ->title('Einladung erneut verschickt.')
                    ->body($this->logMailerHint())
                    ->success()
                    ->send();
            });
    }

    public function withdrawInvitationAction(): Action
    {
        return Action::make('withdrawInvitation')
            ->label('Zurückziehen')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->link()
            ->size('xs')
            ->visible(fn (array $arguments): bool => $this->invitationFromArguments($arguments) !== null
                && $this->currentUser()->can('invite', $this->getGroup()))
            ->requiresConfirmation()
            ->modalHeading('Einladung zurückziehen?')
            ->action(function (array $arguments): void {
                $invitation = $this->invitationFromArguments($arguments);

                if ($invitation) {
                    app(GroupInvitationService::class)->withdraw($invitation);
                    Notification::make()->title('Einladung zurückgezogen.')->success()->send();
                }
            });
    }

    public function getGroup(): Group
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Group, 404);

        return $tenant;
    }

    public function getViewData(): array
    {
        $group = $this->getGroup();
        $canInvite = $this->currentUser()->can('invite', $group);

        $members = $group->members()->orderBy('first_name')->get();

        return [
            'group' => $group,
            'pendingOwner' => $group->pendingOwner,
            'members' => $members,
            'householdSummary' => $this->householdSummary($members),
            'invitations' => $group->invitations()->whereNull('accepted_at')->latest()->get(),
            'canInvite' => $canInvite,
            'activities' => $group->activities()
                ->with('user')
                ->unless($canInvite, fn (Builder $query) => $query->whereNotIn('action', self::INVITATION_ACTIVITIES))
                ->limit(50)
                ->get(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->acceptOwnerTransferAction(),
            $this->declineOwnerTransferAction(),
            $this->inviteAction(),
            $this->transferOwnershipAction(),
            $this->cancelOwnerTransferAction(),
            $this->leaveGroupAction(),
        ];
    }

    /**
     * "6 Haushalte mit zusammen 17 Personen." — counting everybody who said
     * how many people they shop for.
     *
     * @param  Collection<int, User>  $members
     */
    protected function householdSummary(Collection $members): string
    {
        $households = $members->count();
        $people = (int) $members->sum('household_size');
        $summary = $households.' '.($households === 1 ? 'Haushalt' : 'Haushalte');

        return $people > 0
            ? $summary.' mit zusammen '.$people.' '.($people === 1 ? 'Person' : 'Personen').'.'
            : $summary.'.';
    }

    /**
     * Runs a domain operation. A broken rule becomes a readable danger
     * notification instead of an error page.
     */
    protected function attempt(callable $operation, string $failureTitle): bool
    {
        try {
            $operation();

            return true;
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($failureTitle)
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            return false;
        }
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function memberFromArguments(array $arguments): ?User
    {
        return $this->getGroup()->members()->whereKey((int) ($arguments['member'] ?? 0))->first();
    }

    /**
     * The role a member's badge showed when it was clicked.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function shownRoleFromArguments(array $arguments): ?GroupRole
    {
        $role = $arguments['role'] ?? null;

        return is_string($role) ? GroupRole::tryFrom($role) : null;
    }

    /**
     * A badge click switches away from the role the badge showed, not from
     * the stored one — so a second click on an outdated badge (a double
     * click, two moderators at once) does not undo the first.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function switchedRoleFromArguments(array $arguments): ?GroupRole
    {
        return match ($this->shownRoleFromArguments($arguments)) {
            GroupRole::Participant => GroupRole::Moderator,
            GroupRole::Moderator => GroupRole::Participant,
            default => null,
        };
    }

    /**
     * Only open invitations of the current group can be resolved.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function invitationFromArguments(array $arguments): ?GroupInvitation
    {
        return $this->getGroup()->invitations()
            ->whereNull('accepted_at')
            ->whereKey((int) ($arguments['invitation'] ?? 0))
            ->first();
    }

    protected function logMailerHint(): ?string
    {
        return config('mail.default') === 'log'
            ? 'Der Mailer steht auf „log“ — die Mail landet im Log. Kopiere den Einladungslink unten und schick ihn selbst.'
            : null;
    }
}
