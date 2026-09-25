@php
    use App\Enums\ManufacturerMailType;
    use App\Services\Notifications\ManufacturerMailComposer;

    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
    $manufacturers = app(ManufacturerMailComposer::class)->manufacturersFor($round);
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Der Lead fragt bei den Herstellern die Preise für die gewünschten Mengen an, trägt die verhandelten Preise ein und stellt einen Vorschlag zur Abstimmung.

    <x-slot name="actions">
        <x-foodpecker.action :action="$this->createProposalAction" />
    </x-slot>

    <x-slot name="details">
        @if ($manufacturers->isNotEmpty())
            <ul class="mt-4 divide-y divide-gray-100 text-sm dark:divide-white/5">
                @foreach ($manufacturers as $manufacturer)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="font-medium">{{ $manufacturer->name }}</span>
                        <x-foodpecker.action :action="($this->composeManufacturerMailAction)(['type' => ManufacturerMailType::PriceInquiry->value, 'manufacturer' => $manufacturer->id])" />
                    </li>
                @endforeach
            </ul>
        @endif

        @include('filament.rounds.partials.proposals', ['round' => $round])
    </x-slot>
</x-foodpecker.phase-panel>
