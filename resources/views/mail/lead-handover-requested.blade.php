<x-mail::message>
# Lead-Rolle für „{{ $round->title }}“

Hallo,

{{ $requestedBy->fullName() }} möchte dir die Lead-Rolle für die Bestellrunde **{{ $round->title }}** übergeben.

Als Lead koordinierst du die Runde: Preise bei den Lieferanten einholen, den Bestellvorschlag zur Abstimmung stellen, die finale Bestellung aufgeben, Zahlungen abhaken und die Abholung organisieren. Dafür bekommst du die Aufwandsentschädigung von {{ number_format((float) $round->lead_fee_percent, 1, ',', '.') }} % der Bestellsumme.

Die Übergabe gilt erst, wenn du zustimmst.

<x-mail::button :url="$roundUrl">
Zur Runde — annehmen oder ablehnen
</x-mail::button>

Viele Grüße<br>
Dein Foodpecker
</x-mail::message>
