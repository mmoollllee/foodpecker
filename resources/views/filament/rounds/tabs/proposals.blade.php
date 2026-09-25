@php
    use App\Enums\ProposalStatus;

    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $excluded = $round->participants->where('removed', true)->sortBy(fn ($participant) => $participant->user?->first_name);
    [$withdrawn, $current] = $round->proposals->sortByDesc('id')->partition(fn ($proposal) => $proposal->status === ProposalStatus::Withdrawn);
@endphp

<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Jede Spalte zeigt, was eine Person bekommt und zahlt. Pro Position stimmen die ab, die davon etwas bekommen — nur ihr 👍 zählt.
        Erst wenn alle Betroffenen jeder Position zugestimmt haben, kann der Lead den Vorschlag als finale Bestellung wählen.
    </p>

    @if ($excluded->isNotEmpty())
        <div class="rounded-xl border border-gray-200 p-4 text-sm dark:border-white/10">
            <p class="font-medium">Aus der Bestellung ausgeschlossen</p>
            <ul class="mt-2 space-y-2">
                @foreach ($excluded as $participant)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <span>
                            <span class="font-medium">{{ $participant->user?->fullName() ?? '—' }}</span>
                            <span class="text-gray-600 dark:text-gray-400">— „{{ $participant->remove_reason }}“</span>
                            @if ($participant->removed_at)
                                <span class="text-xs text-gray-500">· {{ $participant->removed_at->format('d.m.Y') }}{{ $participant->removedBy ? ' von '.$participant->removedBy->first_name : '' }}</span>
                            @endif
                        </span>
                        <x-foodpecker.action :action="($this->readmitParticipantAction)(['participant' => $participant->id])" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @forelse ($current as $proposal)
        @include('filament.rounds.partials.proposal', ['proposal' => $proposal])
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500">
                Es liegen noch keine Bestellvorschläge vor. Der Lead erstellt in der Verhandlungsphase einen Vorschlag aus den Warenkörben; in der Bestätigungsphase können alle Teilnehmer Gegenvorschläge machen.
            </p>
        </x-filament::section>
    @endforelse

    @if ($withdrawn->isNotEmpty())
        <x-filament::section heading="Zurückgezogene Vorschläge" collapsible collapsed>
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
