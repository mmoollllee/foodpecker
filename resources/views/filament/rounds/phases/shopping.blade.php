@php
    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Alle füllen ihre Warenkörbe — mit einer exakten Menge oder einer flexiblen Spanne.
    @if ($phase === $round->phase)
        {{ $this->canManage() ? 'Klick auf eine Menge, um sie zu ändern — als Lead auch bei den anderen.' : 'Klick auf deine Menge, um sie zu ändern.' }}
    @endif

    <x-slot name="facts">
        <x-foodpecker.fact label="Sortiment" class="sm:col-span-2 lg:col-span-4">
            {{ $round->availableProducts->isEmpty() ? 'Alle Produkte der Gruppe sind bestellbar.' : $round->availableProducts->pluck('name')->implode(', ') }}
        </x-foodpecker.fact>
    </x-slot>

    <x-slot name="details">
        @include('filament.rounds.partials.carts-table', ['round' => $round])
    </x-slot>
</x-foodpecker.phase-panel>
