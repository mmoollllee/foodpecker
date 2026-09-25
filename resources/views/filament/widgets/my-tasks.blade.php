@php /** @var array $tasks */ @endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Was steht für dich an?
        </x-slot>
        <x-slot name="description">
            Deine nächsten Schritte in der laufenden Bestellrunde und in der Gruppe.
        </x-slot>

        @if (empty($tasks))
            <div class="text-center py-8 text-gray-500">
                <div class="text-3xl mb-2">☕</div>
                <p class="text-sm">Gerade nichts zu tun — entspann dich.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($tasks as $task)
                    <a href="{{ $task['url'] }}" @class([
                        'block rounded-lg border p-3 transition hover:bg-gray-50 dark:hover:bg-white/5',
                        'border-amber-500/30 bg-amber-500/5'   => $task['color'] === 'amber',
                        'border-sky-500/30 bg-sky-500/5'       => $task['color'] === 'sky',
                        'border-rose-500/30 bg-rose-500/5'     => $task['color'] === 'danger',
                        'border-emerald-500/30 bg-emerald-500/5' => $task['color'] === 'success',
                        'border-orange-500/30 bg-orange-500/5' => $task['color'] === 'warning',
                        'border-gray-200 dark:border-white/10' => ! in_array($task['color'], ['amber','sky','danger','success','warning']),
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 flex-1 min-w-0">
                                <div class="text-2xl">{{ $task['icon'] }}</div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium text-sm">{{ $task['title'] }}</div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">{{ $task['description'] }}</div>
                                </div>
                            </div>
                            <div class="text-xs font-medium text-gray-500 whitespace-nowrap">
                                {{ $task['cta'] }} →
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
