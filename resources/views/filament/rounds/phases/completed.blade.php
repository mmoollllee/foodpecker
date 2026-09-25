@php
    use App\Filament\Pages\MyOrders;

    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Die Runde ist abgeschlossen. Haltet in den Notizen der Übersicht fest, was gut lief und was nicht — so wird die nächste Runde leichter.

    <x-slot name="actions">
        @if ($phase === $round->phase)
            <x-filament::button tag="a" :href="MyOrders::getUrl()" color="gray" icon="heroicon-o-clipboard-document-list">Meine Bestellungen</x-filament::button>
        @endif
    </x-slot>
</x-foodpecker.phase-panel>
