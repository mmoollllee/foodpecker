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
     * Accepts an invitation link. Whoever holds the link may join:
     *
     *   - logged in → join the group; an account under another address
     *     takes the invitation over — unless it belongs to the group
     *     already: then the link stays open for the person it was sent to
     *   - not logged in, account with the invited address → log in, then come back here
     *   - otherwise → register (the address is prefilled, but may be changed)
     *     or log in with an existing account, then come back here
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = GroupInvitation::query()->where('token', $token)->first();

        if (! $request->hasValidSignature() || $invitation === null) {
            return $this->fail('Der Einladungs-Link ist ungültig. Bitte frag nach einer neuen Einladung.');
        }

        $user = Auth::user();

        if ($user instanceof User && $invitation->group->hasMember($user)) {
            if (mb_strtolower($invitation->email) === mb_strtolower($user->email)) {
                $invitation->accept($user);
            } elseif (! $invitation->isAccepted()) {
                Notification::make()
                    ->title('Du bist schon Mitglied der Gruppe „'.$invitation->group->name.'“.')
                    ->body('Die Einladung an '.$invitation->email.' bleibt offen.')
                    ->info()
                    ->send();
            }

            $request->session()->forget(self::SESSION_KEY);

            return redirect(Filament::getPanel('global')->getUrl($invitation->group));
        }

        if ($invitation->isAccepted()) {
            return $this->fail('Diese Einladung wurde bereits angenommen. Bitte frag nach einer neuen.');
        }

        if ($invitation->isExpired()) {
            return $this->fail('Diese Einladung ist abgelaufen — bitte frag nach einer neuen.');
        }

        if ($user instanceof User) {
            $invitation->acceptAs($user);
            $request->session()->forget(self::SESSION_KEY);

            Notification::make()
                ->title('Willkommen in der Gruppe „'.$invitation->group->name.'“!')
                ->success()
                ->send();

            return redirect(Filament::getPanel('global')->getUrl($invitation->group));
        }

        $request->session()->put(self::SESSION_KEY, $token);
        redirect()->setIntendedUrl($request->fullUrl());

        $hasAccount = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])
            ->exists();

        if ($hasAccount) {
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
