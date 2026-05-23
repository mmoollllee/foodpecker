@php /** @var \App\Models\Round $round */ @endphp
<div class="space-y-2">
    @forelse ($round->pickupDates as $pd)
        <div class="rounded border border-gray-200 dark:border-white/10 p-3 text-sm">
            <div class="font-medium">{{ $pd->scheduled_at->format('d.m.Y H:i') }}</div>
            @if ($pd->location)
                <div class="text-gray-500">{{ $pd->location }}</div>
            @endif
            @if ($pd->notes)
                <div class="text-xs italic text-gray-400 mt-1">{{ $pd->notes }}</div>
            @endif
        </div>
    @empty
        <p class="text-gray-500">Noch keine Abholtermine angelegt. Trag sie über "Eckdaten bearbeiten" ein.</p>
    @endforelse
</div>
