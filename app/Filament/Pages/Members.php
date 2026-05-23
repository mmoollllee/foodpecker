<?php

namespace App\Filament\Pages;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupUser;
use App\Services\Invitations\GroupInvitationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Members extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

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
            ->label('Mitglied einladen')
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->visible(fn (): bool => $this->canManageMembers())
            ->schema([
                TextInput::make('email')
                    ->label('E-Mail-Adresse')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Select::make('role')
                    ->label('Rolle')
                    ->options(collect(GroupRole::assignable())->mapWithKeys(fn (GroupRole $r) => [$r->value => $r->getLabel()]))
                    ->default(GroupRole::Participant->value)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $group = $this->getGroup();
                if (! $group) {
                    return;
                }
                app(GroupInvitationService::class)->invite(
                    $group,
                    $data['email'],
                    GroupRole::from($data['role']),
                    auth()->user(),
                );
                Notification::make()
                    ->title('Einladung verschickt!')
                    ->body('Mailer steht im Dev-Modus auf log — Du findest die Mail in storage/logs/laravel.log.')
                    ->success()->send();
            });
    }

    public function resendInvitation(int $invitationId): void
    {
        $invitation = GroupInvitation::findOrFail($invitationId);
        app(GroupInvitationService::class)->resend($invitation);
        Notification::make()->title('Einladung erneut verschickt.')->success()->send();
    }

    public function withdrawInvitation(int $invitationId): void
    {
        $invitation = GroupInvitation::findOrFail($invitationId);
        app(GroupInvitationService::class)->withdraw($invitation);
        Notification::make()->title('Einladung zurückgezogen.')->success()->send();
    }

    public function changeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label('Rolle ändern')
            ->visible(fn (): bool => $this->canManageMembers())
            ->schema([
                Select::make('user_id')
                    ->label('Mitglied')
                    ->options(fn () => $this->getGroup()?->members()
                        ->whereKeyNot($this->getGroup()?->owner_id)
                        ->get()
                        ->mapWithKeys(fn ($u) => [$u->id => $u->fullName().' · '.$u->email])
                        ->all() ?? [])
                    ->searchable()
                    ->required(),
                Select::make('role')
                    ->label('Neue Rolle')
                    ->options(collect(GroupRole::assignable())->mapWithKeys(fn (GroupRole $r) => [$r->value => $r->getLabel()]))
                    ->required(),
            ])
            ->action(function (array $data): void {
                $group = $this->getGroup();
                if (! $group) {
                    return;
                }
                GroupUser::where('group_id', $group->id)
                    ->where('user_id', $data['user_id'])
                    ->update(['role' => $data['role']]);
                Notification::make()->title('Rolle aktualisiert.')->success()->send();
            });
    }

    public function removeMember(int $userId): void
    {
        $group = $this->getGroup();
        if (! $group) {
            return;
        }
        if ($group->owner_id === $userId) {
            Notification::make()->title('Owner kann nicht entfernt werden.')->danger()->send();

            return;
        }
        $group->members()->detach($userId);
        Notification::make()->title('Mitglied entfernt.')->success()->send();
    }

    public function getGroup(): ?Group
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Group ? $tenant : null;
    }

    public function canManageMembers(): bool
    {
        $group = $this->getGroup();
        $user = auth()->user();

        return $group && $user && $user->canInGroup($group, 'group:manage-members');
    }

    public function getViewData(): array
    {
        $group = $this->getGroup();

        return [
            'group' => $group,
            'members' => $group ? $group->members()->orderBy('first_name')->get() : collect(),
            'invitations' => $group ? $group->invitations()->whereNull('accepted_at')->latest()->get() : collect(),
            'canManage' => $this->canManageMembers(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->inviteAction(),
            $this->changeRoleAction(),
        ];
    }
}
