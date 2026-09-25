<x-mail::message>
{{ $body }}

<x-mail::button :url="$roundUrl">
Zur Bestellrunde „{{ $roundTitle }}“
</x-mail::button>
</x-mail::message>
