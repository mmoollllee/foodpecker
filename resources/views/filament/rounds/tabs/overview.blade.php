@php
    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $participants = $round->participants->sortBy(fn ($participant) => [$participant->removed, $participant->user?->first_name]);
    $cartCountByUser = $round->cartItems->countBy('user_id');
@endphp

<div class="space-y-4">
    <x-filament::section heading="Zusammenfassung">
        <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-gray-500">Lead</div>
                <div class="font-semibold">{{ $round->lead?->fullName() ?? '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500">Teilnehmer</div>
                <div class="font-semibold">{{ $round->activeParticipantCount() }}</div>
            </div>
            <div>
                <div class="text-gray-500">Warenkorb-Positionen</div>
                <div class="font-semibold">{{ $round->cartItems->count() }}</div>
            </div>
            <div>
                <div class="text-gray-500">Vorschläge</div>
                <div class="font-semibold">{{ $round->proposals->count() }}</div>
            </div>
        </div>

        @if ($round->description)
            <div class="mt-4 text-sm text-gray-700 dark:text-gray-200">
                <div class="mb-1 text-gray-500">Beschreibung des Leads</div>
                <p class="whitespace-pre-line">{{ $round->description }}</p>
            </div>
        @endif

        @if ($round->availableProducts->isNotEmpty())
            <div class="mt-4 text-sm">
                <div class="mb-2 text-gray-500">Sortiment dieser Runde ({{ $round->availableProducts->count() }} Produkte)</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($round->availableProducts as $product)
                        <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs dark:bg-white/5">{{ $product->name }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Teilnehmer" description="Wer bestellt mit?">

        @if ($participants->isEmpty())
            <p class="text-sm text-gray-500">Noch niemand — sobald jemand etwas in den Warenkorb legt, erscheint die Person hier.</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                @foreach ($participants as $participant)
                    <li class="py-2">
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
                            @else
                                <span class="ml-1 text-xs text-gray-500">{{ $cartCountByUser[$participant->user_id] ?? 0 }} Artikel</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</div>
