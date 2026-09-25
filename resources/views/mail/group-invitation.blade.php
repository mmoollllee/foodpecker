<x-mail::message>
# Einladung zu „{{ $group->name }}“

Hallo,

@if ($invitedBy)
{{ $invitedBy }} hat dich zur Gruppe **{{ $group->name }}** bei Foodpecker eingeladen.
@else
Du wurdest zur Gruppe **{{ $group->name }}** bei Foodpecker eingeladen.
@endif

Mit Foodpecker bestellen Freundesgruppen gemeinsam direkt beim Hersteller Lebensmittel in größeren Mengen.

<x-mail::button :url="$acceptUrl">
Einladung annehmen
</x-mail::button>

Falls du noch kein Konto hast, kannst du dich im Anschluss direkt registrieren — deine Mailadresse ist bereits eingetragen. Wenn du schon ein Konto hast, meldest du dich einfach an.

Der Link ist gültig bis {{ $invitation->expires_at?->format('d.m.Y H:i') }} Uhr.

Viele Grüße<br>
Dein Foodpecker
</x-mail::message>
