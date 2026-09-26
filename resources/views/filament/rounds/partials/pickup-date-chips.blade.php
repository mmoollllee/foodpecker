@php
    /** @var \App\Models\Round $round */
    /** @var bool $withCounts Show how many people chose each date. */
    $withCounts ??= false;
    $counts = $withCounts ? $round->pickups->whereNotNull('pickup_date_id')->countBy('pickup_date_id') : collect();
@endphp

@if ($round->pickupDates->isNotEmpty())
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($round->pickupDates as $pickupDate)
            <span class="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                📅 {{ $pickupDate->label() }}{{ $pickupDate->location ? ' · '.$pickupDate->location : '' }}{{ $withCounts ? ' · '.($counts[$pickupDate->id] ?? 0).' angemeldet' : '' }}
            </span>
        @endforeach
    </div>
@endif
