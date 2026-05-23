<?php

namespace App\Filament\Pages\Auth;

use App\Http\Controllers\InvitationController;
use App\Models\GroupInvitation;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class Register extends BaseRegister
{
    public ?GroupInvitation $invitation = null;

    public function mount(): void
    {
        $token = session(InvitationController::SESSION_KEY)
            ?? request()->query('invitation_token');

        if ($token) {
            $this->invitation = GroupInvitation::query()
                ->where('token', $token)
                ->whereNull('accepted_at')
                ->first();

            if ($this->invitation && ! $this->invitation->isExpired()) {
                $this->data['email'] = $this->invitation->email;
            }
        }

        parent::mount();

        if ($this->invitation) {
            $this->form->fill(array_merge($this->form->getRawState(), [
                'email' => $this->invitation->email,
            ]));
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make($this->invitation
                    ? 'Einladung zu '.$this->invitation->group->name.' annehmen'
                    : 'Konto anlegen')
                ->description($this->invitation
                    ? 'Lege Dein Konto an, um der Gruppe als '.$this->invitation->role->getLabel().' beizutreten.'
                    : 'Foodpecker ist nur nach Registrierung nutzbar. Du kannst entweder eine eigene Gruppe gründen oder einer Gruppe per Einladung beitreten.')
                ->schema([
                    TextInput::make('first_name')
                        ->label('Vorname')
                        ->required()
                        ->maxLength(255)
                        ->autofocus(),
                    TextInput::make('last_name')
                        ->label('Nachname')
                        ->required()
                        ->maxLength(255),
                    $this->getEmailFormComponent(),
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                ])->columns(2),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        $field = parent::getEmailFormComponent()->label('E-Mail');
        if ($this->invitation) {
            $field->disabled()->dehydrated();
        }

        return $field;
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->label('Passwort');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()->label('Passwort bestätigen');
    }

    protected function mutateFormDataBeforeRegister(array $data): array
    {
        $data['name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        return $data;
    }

    protected function handleRegistration(array $data): Model
    {
        $user = $this->getUserModel()::create($data);

        if ($this->invitation && ! $this->invitation->isExpired()) {
            $this->invitation->accept($user);
            session()->forget(InvitationController::SESSION_KEY);
        }

        return $user;
    }
}
