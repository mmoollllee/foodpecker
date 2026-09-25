{{--
    The phases of a round as a row of steps: passed ones green, the running
    one highlighted. On the round page a click selects a phase and shows its
    details; elsewhere the steps can link there.
--}}
@props([
    /** @var \App\Models\Round $round */
    'round',
    /** @var \App\Enums\RoundPhase|null $selected Phase whose details are shown. */
    'selected' => null,
    /** Steps select a phase on the Livewire page they are rendered on. */
    'selectable' => false,
    /** @var \Closure|null $href Closure(RoundPhase): string — steps link there instead. */
    'href' => null,
    'withDates' => false,
])

@php
    use App\Enums\RoundPhase;
    use Illuminate\Support\Arr;
@endphp

<div {{ $attributes->class('flex flex-wrap gap-2') }}>
    @foreach (RoundPhase::cases() as $phase)
        @continue(in_array($phase, [RoundPhase::Draft, RoundPhase::Cancelled], true))
        @php
            $active = $phase === $round->phase;
            $passed = $round->hasPassed($phase);
            $isSelected = $phase === $selected;
            $date = $withDates ? $round->dateFor($phase) : null;
            $classes = Arr::toCssClasses([
                'flex items-center gap-2 rounded-lg px-3 py-2 text-left text-sm',
                'bg-amber-500/10 text-amber-700 ring-1 ring-amber-500/30 dark:text-amber-300' => $active,
                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $passed,
                'bg-gray-100 text-gray-500 dark:bg-white/5' => ! $active && ! $passed,
                'ring-2! ring-current!' => $isSelected,
                'cursor-pointer transition hover:brightness-95 focus-visible:outline-2 focus-visible:outline-primary-500 dark:hover:brightness-125' => $selectable || $href,
            ]);
        @endphp

        @if ($selectable)
            <button
                type="button"
                wire:click="$set('selectedPhase', @js($phase->value))"
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                class="{{ $classes }}"
            >
        @elseif ($href)
            <a href="{{ $href($phase) }}" class="{{ $classes }}">
        @else
            <div class="{{ $classes }}">
        @endif
                <x-filament::icon :icon="$phase->getIcon()" class="h-4 w-4 shrink-0" />
                <span>
                    <span class="block">{{ $phase->getLabel() }}</span>
                    @if ($date)
                        <span class="block text-xs opacity-75">{{ $date[0] }} {{ $date[1]->format('d.m.') }}</span>
                    @endif
                </span>
        @if ($selectable)
            </button>
        @elseif ($href)
            </a>
        @else
            </div>
        @endif
    @endforeach
</div>
