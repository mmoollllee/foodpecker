@php
    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Die Bestellung ist raus. Sobald die Ware beim Lead ist, geht es mit der Abholung weiter. Verschiebt sich die Lieferung, trägt der Lead den neuen Termin unter „Eckdaten bearbeiten“ ein und sagt der Gruppe per Benachrichtigung Bescheid.
</x-foodpecker.phase-panel>
