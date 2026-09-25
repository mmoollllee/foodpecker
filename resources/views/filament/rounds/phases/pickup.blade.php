@php
    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Alle holen ihre Ware am Abholort ab — zu einem der Termine. Wer abgeholt hat, hakt das ab; der Lead kann das für alle tun.

    <x-slot name="facts">
        <x-foodpecker.fact label="Abholort" class="sm:col-span-2 lg:col-span-4">
            <span class="whitespace-pre-line">{{ $round->pickup_location ?? '—' }}</span>
        </x-foodpecker.fact>
    </x-slot>

    <x-slot name="details">
        @include('filament.rounds.partials.pickups', ['round' => $round])
    </x-slot>
</x-foodpecker.phase-panel>
