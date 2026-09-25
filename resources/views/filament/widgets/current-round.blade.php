@php
    /** @var \App\Models\Round|null $round */
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Aktuelle Bestellrunde">
        @if ($round)
            <x-slot name="afterHeader">
                <x-filament::badge :color="$round->phase->getColor()" :icon="$round->phase->getIcon()">{{ $round->phase->getLabel() }}</x-filament::badge>
            </x-slot>

            <div class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ $url }}" class="text-lg font-semibold hover:underline">{{ $round->title }}</a>
                        <p class="text-sm text-gray-500">
                            Lead: {{ $round->lead?->fullName() ?? '—' }}
                            · {{ $round->activeParticipantCount() }}{{ $round->max_participants ? ' von '.$round->max_participants : '' }} Teilnehmer
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($cartUrl)
                            <x-filament::button tag="a" :href="$cartUrl" color="gray" size="sm" icon="heroicon-o-shopping-bag">Mein Warenkorb</x-filament::button>
                        @endif
                        <x-filament::button tag="a" :href="$url" size="sm" icon="heroicon-m-arrow-right" icon-position="after">Zur Runde</x-filament::button>
                    </div>
                </div>

                <x-foodpecker.phase-steps :round="$round" />

                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-5">
                    @foreach ($dates as $label => [$date, $isCurrent])
                        <div>
                            <dt class="text-gray-500">{{ $label }}</dt>
                            <dd @class(['font-medium', 'text-primary-600 dark:text-primary-400' => $isCurrent])>{{ $date?->format('d.m.Y') ?? '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($round->pickup_location || $round->pickupDates->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-gray-500">Abholung:</span>
                        @if ($round->pickup_location)
                            <span class="font-medium">{{ $round->pickup_location }}</span>
                        @endif
                        @foreach ($round->pickupDates as $pickupDate)
                            <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">📅 {{ $pickupDate->scheduled_at->format('d.m. H:i') }}</span>
                        @endforeach
                    </div>
                @endif

                <p @class([
                    'rounded-lg px-3 py-2 text-sm',
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $participation['color'] === 'success',
                    'bg-rose-500/10 text-rose-700 dark:text-rose-300' => $participation['color'] === 'danger',
                    'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' => $participation['color'] === 'gray',
                ])>{{ $participation['text'] }}</p>
            </div>
        @else
            <div class="py-6 text-center">
                <div class="mb-2 text-3xl">🌱</div>
                <p class="text-sm font-medium">Gerade läuft keine Bestellrunde.</p>
                @if ($lastRound)
                    <p class="mt-1 text-sm text-gray-500">
                        Zuletzt:
                        <a href="{{ $urlFor($lastRound) }}" class="underline hover:text-primary-600">{{ $lastRound->title }}</a>
                        — {{ $lastRound->phase->getLabel() }}{{ $lastRound->phase_changed_at ? ' am '.$lastRound->phase_changed_at->format('d.m.Y') : '' }}.
                    </p>
                @endif

                <div class="mt-4 flex justify-center">
                    @if ($myDraft)
                        <x-filament::button tag="a" :href="$urlFor($myDraft)" icon="heroicon-o-play">Entwurf „{{ $myDraft->title }}“ starten</x-filament::button>
                    @elseif ($canCreate)
                        <x-filament::button tag="a" :href="$createUrl" icon="heroicon-o-plus">Neue Runde starten</x-filament::button>
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
