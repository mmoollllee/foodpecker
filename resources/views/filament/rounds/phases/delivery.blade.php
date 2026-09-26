@php
    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
    $suppliers = $this->orderSuppliers();
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Die Bestellung ist raus. Der Lead hakt ab, welche Lieferung angekommen ist — ist alles da, geht es mit der Abholung weiter. Verschiebt sich die Lieferung, trägt der Lead den neuen Termin unter „Eckdaten bearbeiten“ ein und sagt der Gruppe per Benachrichtigung Bescheid.

    <x-slot name="actions">
        @include('filament.rounds.partials.packing-list-button', ['round' => $round])
    </x-slot>

    <x-slot name="details">
        @if ($suppliers->isNotEmpty())
            @include('filament.rounds.partials.supplier-order-progress', ['round' => $round])

            <ul class="mt-4 divide-y divide-gray-100 text-sm dark:divide-white/5">
                @foreach ($suppliers as $supplier)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $supplier->name }}</span>
                            @include('filament.rounds.partials.supplier-order-status', ['record' => $this->supplierOrderFor($supplier->id)])
                        </div>
                        <x-foodpecker.action :action="($this->toggleSupplierDeliveredAction)(['supplier' => $supplier->id, 'delivered' => (bool) $this->supplierOrderFor($supplier->id)?->isDelivered()])" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-slot>
</x-foodpecker.phase-panel>
