<?php

namespace App\Http\Controllers;

use App\Models\GroupInvitation;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvitationController extends Controller
{
    public const SESSION_KEY = 'foodpecker.invitation_token';

    public function accept(Request $request, string $token): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('filament.global.auth.login')
                ->withErrors(['email' => 'Der Einladungs-Link ist ungültig oder abgelaufen.']);
        }

        $invitation = GroupInvitation::query()->where('token', $token)->first();

        if (! $invitation) {
            return redirect()->route('filament.global.auth.login')
                ->withErrors(['email' => 'Diese Einladung existiert nicht (mehr).']);
        }

        if ($invitation->isAccepted()) {
            return redirect()->route('filament.global.tenant', ['tenant' => $invitation->group->slug])
                ->with('status', 'Du hast diese Einladung bereits angenommen.');
        }

        if ($invitation->isExpired()) {
            return redirect()->route('filament.global.auth.login')
                ->withErrors(['email' => 'Diese Einladung ist abgelaufen — bitte um eine neue bitten.']);
        }

        $user = Auth::user();

        if ($user) {
            if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
                return redirect()->route('filament.global.auth.login')
                    ->withErrors(['email' => 'Diese Einladung ist an '.$invitation->email.' adressiert. Bitte mit dieser Mailadresse einloggen.']);
            }

            $invitation->accept($user);

            Filament::setTenant($invitation->group);

            return redirect()->route('filament.global.tenant', ['tenant' => $invitation->group->slug])
                ->with('status', 'Willkommen in der Gruppe „'.$invitation->group->name.'"!');
        }

        // Nicht eingeloggt: Token in Session, zur Registrierung schicken
        $request->session()->put(self::SESSION_KEY, $token);

        return redirect()->route('filament.global.auth.register');
    }
}
