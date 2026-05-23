Hallo,

@if ($invitedBy)
{{ $invitedBy }} hat Dich zur Gruppe **{{ $group->name }}** bei Foodpecker eingeladen.
@else
Du wurdest zur Gruppe **{{ $group->name }}** bei Foodpecker eingeladen.
@endif

Foodpecker ist eine Plattform, mit der Freundesgruppen gemeinsam direkt beim Hersteller Lebensmittel in größeren Mengen bestellen können.

Klicke auf den folgenden Link, um die Einladung anzunehmen. Falls Du noch kein Konto hast, kannst Du Dich im Anschluss direkt registrieren — Deine Mailadresse ist bereits vorausgefüllt.

[Einladung annehmen]({{ $acceptUrl }})

Der Link ist gültig bis {{ $invitation->expires_at?->format('d.m.Y H:i') }}.

Viele Grüße
Dein Foodpecker
