{{--
    Everything about one phase of a round: whether it is done, running or
    still ahead, what it is about, what can be done and its content, e.g.
    the carts while shopping.
--}}
@props([
    /** @var \App\Models\Round $round */
    'round',
    /** @var \App\Enums\RoundPhase $phase */
    'phase',
])

@php
    use App\Enums\RoundPhase;

    [$statusLabel, $statusColor] = match (true) {
        $phase === $round->phase => ['läuft gerade', 'primary'],
        $round->hasPassed($phase) => ['erledigt', 'success'],
        $round->phase === RoundPhase::Cancelled => ['entfällt', 'gray'],
        default => ['kommt noch', 'gray'],
    };
@endphp

<div {{ $attributes->class('mt-6 border-t border-gray-200 pt-6 dark:border-white/10') }}>
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::icon :icon="$phase->getIcon()" class="h-5 w-5 text-gray-400" />
        <h3 class="font-semibold">{{ $phase->getLabel() }}</h3>
        <x-filament::badge :color="$statusColor" size="sm">{{ $statusLabel }}</x-filament::badge>
    </div>

    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $slot }}</p>

    @isset($facts)
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            {{ $facts }}
        </dl>
    @endisset

    @isset($actions)
        <div class="mt-4 flex flex-wrap items-center gap-2 [&:not(:has(*))]:hidden">
            {{ $actions }}
        </div>
    @endisset

    {{ $details ?? '' }}
</div>
