@php
    /** @var \App\Models\Round $round */
@endphp

@if ($round->chosen_proposal_id !== null)
    <x-filament::button
        tag="a"
        :href="route('rounds.packing-list', $round)"
        target="_blank"
        color="gray"
        icon="heroicon-o-printer"
        size="sm"
    >
        Packliste
    </x-filament::button>
@endif
