@php
    use App\Enums\ProposalStatus;

    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
    $userId = $this->currentUser()->id;
    $myOpenVotes = $phase === $round->phase
        ? $round->proposals->where('status', ProposalStatus::Published)->sum(
            fn ($proposal) => collect($this->consensusFor($proposal)->items)->filter(fn ($item) => in_array($userId, $item->pendingIds, true))->count(),
        )
        : 0;
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Jede Spalte zeigt, was eine Person bekommt und zahlt. Pro Position stimmen die ab, die davon etwas bekommen — nur ihr 👍 zählt. Einen einstimmigen Vorschlag wählt der Lead als finale Bestellung.
    @if ($myOpenVotes > 0)
        <strong class="text-gray-950 dark:text-white">Du hast noch {{ $myOpenVotes }} {{ $myOpenVotes === 1 ? 'offene Stimme' : 'offene Stimmen' }}.</strong>
    @endif

    <x-slot name="actions">
        <x-foodpecker.action :action="$this->createProposalAction" />
    </x-slot>

    <x-slot name="details">
        @include('filament.rounds.partials.proposals', ['round' => $round])
    </x-slot>
</x-foodpecker.phase-panel>
