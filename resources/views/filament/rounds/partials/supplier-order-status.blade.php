@php
    /** @var \App\Models\RoundSupplier|null $record */
@endphp

@if ($record?->isDelivered())
    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">✓ angekommen {{ $record->delivered_at->format('d.m.') }}</span>
@elseif ($record?->isOrdered())
    <span class="rounded-full bg-sky-500/10 px-2 py-0.5 text-xs font-medium text-sky-700 dark:text-sky-300">✓ bestellt {{ $record->ordered_at->format('d.m.') }}</span>
@else
    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">noch nicht bestellt</span>
@endif
