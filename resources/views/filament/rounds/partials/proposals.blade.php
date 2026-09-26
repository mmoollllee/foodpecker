@php
    use App\Enums\ProposalStatus;

    /** @var \App\Models\Round $round */
    [$withdrawn, $current] = $round->proposals->sortByDesc('id')->partition(fn ($proposal) => $proposal->status === ProposalStatus::Withdrawn);
@endphp

<div class="mt-4 space-y-4">
    @forelse ($current as $proposal)
        @if ($this->canEditProposal($proposal))
            @include('filament.rounds.partials.draft-editor', ['proposal' => $proposal])
        @else
            @include('filament.rounds.partials.proposal', ['proposal' => $proposal])
        @endif
    @empty
        <p class="text-sm text-gray-500">
            Noch kein Bestellvorschlag. Er entsteht, sobald die Einkaufsphase endet; in der Bestätigung können alle Gegenvorschläge machen.
        </p>
    @endforelse

    @if ($withdrawn->isNotEmpty())
        <x-filament::section heading="Zurückgezogene Vorschläge" collapsible collapsed compact>
            <ul class="space-y-2 text-sm">
                @foreach ($withdrawn as $proposal)
                    <li class="flex flex-wrap items-center justify-between gap-2">
                        <span>{{ $proposal->title }} <span class="text-xs text-gray-500">· {{ $proposal->proposedBy?->fullName() }}</span></span>
                        <x-foodpecker.action :action="($this->newProposalVersionAction)(['proposal' => $proposal->id])" />
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</div>
