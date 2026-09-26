<x-mail::message>
# Einladung zu „{{ $group->name }}“

Hallo,

@if ($invitedBy)
{{ $invitedBy }} hat dich zur Gruppe **{{ $group->name }}** bei Foodpecker eingeladen.
@else
Du wurdest zur Gruppe **{{ $group->name }}** bei Foodpecker eingeladen.
@endif

Mit Foodpecker bestellen Freundesgruppen gemeinsam Lebensmittel in größeren Mengen — möglichst direkt beim Erzeuger.

<x-mail::button :url="$acceptUrl">
Einladung annehmen
</x-mail::button>

Wenn du schon ein Konto hast — auch unter einer anderen Mailadresse —, meldest du dich einfach an. Sonst legst du im Anschluss direkt eines an.

Der Link ist gültig bis {{ $invitation->expires_at?->format('d.m.Y H:i') }} Uhr.

Viele Grüße<br>
Dein Foodpecker
</x-mail::message>
