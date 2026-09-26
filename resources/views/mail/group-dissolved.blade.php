<x-mail::message>
# Die Gruppe „{{ $groupName }}“ gibt es nicht mehr

Hallo,

{{ $dissolvedBy->fullName() }} hat die Foodpecker-Gruppe **{{ $groupName }}** aufgelöst. Deine Mitgliedschaft und die Bestellrunden der Gruppe wurden gelöscht.

Lieferanten und Produkte, die die Gruppe mit allen geteilt hat, bleiben für die anderen Gruppen erhalten — ohne Bezug zu euch.

Dein Foodpecker-Konto bleibt bestehen: Du kannst dich einer anderen Gruppe anschließen oder selbst eine gründen.

<x-mail::button :url="$loginUrl">
Zu Foodpecker
</x-mail::button>

Bei Fragen antworte einfach auf diese E-Mail.

Viele Grüße<br>
Dein Foodpecker
</x-mail::message>
