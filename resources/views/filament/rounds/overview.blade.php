@php
    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $participants = $round->participants->sortBy(fn ($participant) => [$participant->removed, $participant->user?->first_name]);
    $percent = fn ($value): string => number_format((float) $value, 1, ',', '.').' %';
@endphp

<x-filament::section heading="Übersicht">
    <div class="space-y-4">
        <x-filament::section heading="Eckdaten">
            @if ($round->description)
                <p class="mb-4 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $round->description }}</p>
            @endif

            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <x-foodpecker.fact label="Aufwandsentschädigung Lead">{{ $percent($round->lead_fee_percent) }}</x-foodpecker.fact>
                <x-foodpecker.fact label="Vereinsbeitrag">{{ $percent($round->platform_fee_percent) }}</x-foodpecker.fact>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="'Teilnehmer · '.$round->activeParticipantCount().($round->max_participants ? ' von '.$round->max_participants : '')">
            @if ($participants->isEmpty())
                <p class="text-sm text-gray-500">Noch niemand — sobald jemand etwas in den Warenkorb legt, erscheint die Person hier.</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @foreach ($participants as $participant)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                            <div class="min-w-0">
                                <span @class(['font-medium', 'line-through text-gray-400' => $participant->removed])>
                                    {{ $participant->user?->fullName() ?? '—' }}
                                </span>
                                @if ($participant->user_id === $round->lead_user_id)
                                    <span class="ml-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-300">Lead</span>
                                @endif
                                @if ($participant->removed)
                                    <span class="ml-1 rounded-full bg-rose-500/10 px-2 py-0.5 text-xs text-rose-700 dark:text-rose-300">ausgeschlossen</span>
                                    <div class="mt-0.5 text-xs text-gray-500">
                                        „{{ $participant->remove_reason }}“
                                        @if ($participant->removed_at)
                                            · {{ $participant->removed_at->format('d.m.Y') }}{{ $participant->removedBy ? ' von '.$participant->removedBy->first_name : '' }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <x-foodpecker.action :action="($this->readmitParticipantAction)(['participant' => $participant->id])" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @include('filament.partials.notes-and-documents', [
            'notesDescription' => 'Erfahrungen festhalten — z. B. „Lieferung war drei Wochen zu spät, nächstes Mal früher bestellen“.',
        ])
    </div>
</x-filament::section>
