{{--
    The phases of a round as a row of steps: passed ones green, the current
    one highlighted.
--}}
@props([
    /** @var \App\Models\Round $round */
    'round',
])

@php
    use App\Enums\RoundPhase;
@endphp

<div {{ $attributes->class('flex flex-wrap gap-2') }}>
    @foreach (RoundPhase::cases() as $phase)
        @continue(in_array($phase, [RoundPhase::Draft, RoundPhase::Cancelled], true))
        @php
            $active = $phase === $round->phase;
            $passed = $round->phase !== RoundPhase::Cancelled && $phase->order() < $round->phase->order();
        @endphp
        <div @class([
            'flex items-center gap-2 rounded-lg px-3 py-2 text-sm',
            'bg-amber-500/10 text-amber-700 ring-1 ring-amber-500/30 dark:text-amber-300' => $active,
            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $passed,
            'bg-gray-100 text-gray-500 dark:bg-white/5' => ! $active && ! $passed,
        ])>
            <x-filament::icon :icon="$phase->getIcon()" class="h-4 w-4" />
            <span>{{ $phase->getLabel() }}</span>
        </div>
    @endforeach
</div>
