<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;

class Login extends BaseLogin
{
    public function mount(): void
    {
        parent::mount();

        if (app()->isLocal()) {
            $this->form->fill([
                'email' => config('foodpecker.demo.owner_email'),
                'password' => config('foodpecker.demo.owner_password'),
                'remember' => true,
            ]);
        }
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->label('E-Mail');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->label('Passwort');
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->label('Eingeloggt bleiben');
    }
}
