<?php

namespace App\Http\Controllers;

use App\Models\GroupInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvitationController extends Controller
{
    public const SESSION_KEY = 'foodpecker.invitation_token';

    /**
     * Accepts an invitation link:
     *
     *   - logged in with the invited address → join the group
     *   - not logged in, account exists → log in, then come back here
     *   - not logged in, no account → register with the address prefilled
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = GroupInvitation::query()->where('token', $token)->first();

        if (! $request->hasValidSignature() || $invitation === null) {
            return $this->fail('Der Einladungs-Link ist ungültig. Bitte frag nach einer neuen Einladung.');
        }

        if ($invitation->isExpired() && ! $invitation->isAccepted()) {
            return $this->fail('Diese Einladung ist abgelaufen — bitte frag nach einer neuen.');
        }

        $user = Auth::user();

        if ($user instanceof User) {
            if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
                return $this->fail(sprintf(
                    'Diese Einladung ist an %s adressiert. Bitte melde dich ab und mit dieser Adresse an.',
                    $invitation->email,
                ));
            }

            $invitation->accept($user);
            $request->session()->forget(self::SESSION_KEY);

            Notification::make()
                ->title('Willkommen in der Gruppe „'.$invitation->group->name.'“!')
                ->success()
                ->send();

            return redirect(Filament::getPanel('global')->getUrl($invitation->group));
        }

        if ($invitation->isAccepted()) {
            Notification::make()
                ->title('Du hast diese Einladung bereits angenommen — bitte melde dich an.')
                ->info()
                ->send();

            return redirect()->route('filament.global.auth.login');
        }

        $request->session()->put(self::SESSION_KEY, $token);

        $hasAccount = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])
            ->exists();

        if ($hasAccount) {
            redirect()->setIntendedUrl($request->fullUrl());

            Notification::make()
                ->title('Bitte melde dich an, um der Gruppe „'.$invitation->group->name.'“ beizutreten.')
                ->info()
                ->send();

            return redirect()->route('filament.global.auth.login');
        }

        return redirect()->route('filament.global.auth.register');
    }

    private function fail(string $message): RedirectResponse
    {
        Notification::make()
            ->title($message)
            ->danger()
            ->persistent()
            ->send();

        return Auth::check()
            ? redirect(Filament::getUrl())
            : redirect()->route('filament.global.auth.login');
    }
}
