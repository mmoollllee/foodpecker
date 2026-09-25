@php
    /** @var \Illuminate\Support\Collection $activities */
@endphp

@if ($activities->isEmpty())
    <p class="text-sm text-gray-500">Noch keine Aktivitäten.</p>
@else
    <ul class="space-y-2 text-sm">
        @foreach ($activities as $activity)
            <li class="flex items-baseline gap-3">
                <span class="whitespace-nowrap text-xs tabular-nums text-gray-400">{{ $activity->created_at->format('d.m.Y H:i') }}</span>
                <span class="text-gray-700 dark:text-gray-200">
                    <strong>{{ $activity->user ? $this->authorName($activity->user, $activity->group_id) : 'System' }}</strong> · {{ $activity->describeAction() }}
                </span>
            </li>
        @endforeach
    </ul>
@endif
