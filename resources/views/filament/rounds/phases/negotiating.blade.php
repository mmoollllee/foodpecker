@php
    use App\Models\RoundSupplier;

    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
    $suppliers = $this->feedbackSuppliers();
    $records = $round->roundSuppliers->keyBy('supplier_id');
    $asked = $suppliers->filter(fn ($supplier): bool => $records->get($supplier->id)?->inquired_at !== null || $records->get($supplier->id)?->hasResponded())->count();
    $answered = $suppliers->filter(fn ($supplier): bool => (bool) $records->get($supplier->id)?->hasResponded())->count();
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Der Lead fragt bei den Lieferanten Preise und Versandkosten an und trägt ihre Rückmeldungen ein. Der Bestellvorschlag rechnet sich dabei von selbst neu — Mengen lassen sich pro Person anpassen. Oben rechts geht er zur Abstimmung.

    <x-slot name="actions">
        <x-foodpecker.action :action="$this->createProposalAction" />
    </x-slot>

    <x-slot name="details">
        @if ($suppliers->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                <span @class([
                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $asked === $suppliers->count(),
                    'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' => $asked < $suppliers->count(),
                ])>Angefragt {{ $asked }}/{{ $suppliers->count() }}</span>
                <span @class([
                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $answered === $suppliers->count(),
                    'bg-amber-500/10 text-amber-700 dark:text-amber-300' => $answered < $suppliers->count(),
                ])>Rückmeldungen {{ $answered }}/{{ $suppliers->count() }}</span>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-2">
                @foreach ($suppliers as $supplier)
                    @include('filament.rounds.partials.supplier-card', ['supplier' => $supplier, 'record' => $records->get($supplier->id)])
                @endforeach
            </div>
        @endif

        @include('filament.rounds.partials.proposals', ['round' => $round])
    </x-slot>
</x-foodpecker.phase-panel>
