@php
    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Alle überweisen ihren Anteil an den Lead — außerhalb von Foodpecker. Der Lead hakt eingegangene Zahlungen ab; erst wenn alle bezahlt haben, geht die Bestellung raus.

    <x-slot name="details">
        @include('filament.rounds.partials.payments', ['round' => $round])
    </x-slot>
</x-foodpecker.phase-panel>
