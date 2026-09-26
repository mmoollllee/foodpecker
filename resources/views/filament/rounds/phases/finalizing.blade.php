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
    Jede Spalte zeigt, was eine Person bekommt und zahlt. Über eine Position entscheiden alle, die das Produkt bestellt haben — auch wenn sie davon nichts bekommen. Einen einstimmigen Vorschlag macht der Lead zur finalen Bestellung; wem etwas nicht passt, der macht einen Gegenvorschlag.
    @if ($myOpenVotes > 0)
        <strong class="text-gray-950 dark:text-white">Du hast noch {{ $myOpenVotes }} {{ $myOpenVotes === 1 ? 'offene Stimme' : 'offene Stimmen' }}.</strong>
    @endif

    <x-slot name="details">
        @include('filament.rounds.partials.proposals', ['round' => $round])
    </x-slot>
</x-foodpecker.phase-panel>
