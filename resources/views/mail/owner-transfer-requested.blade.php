<x-mail::message>
# Owner-Rolle für „{{ $group->name }}“

Hallo,

{{ $requestedBy->fullName() }} möchte dir die Foodpecker-Gruppe **{{ $group->name }}** übergeben.

Als Owner hast du alle Rechte in der Gruppe: Du lädst Leute ein, vergibst Rollen, entfernst Mitglieder und könntest die Gruppe auch auflösen. {{ $requestedBy->first_name }} bleibt als Moderator dabei.

Die Übergabe gilt erst, wenn du zustimmst.

<x-mail::button :url="$membersUrl">
Zur Gruppe — annehmen oder ablehnen
</x-mail::button>

Viele Grüße<br>
Dein Foodpecker
</x-mail::message>
