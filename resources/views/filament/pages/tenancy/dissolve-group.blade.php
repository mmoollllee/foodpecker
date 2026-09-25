@php
    /** @var array{deleted: array<int, string>, kept: array<int, string>} $consequences */
    /** @var array<int, string> $blockingReasons */
    /** @var string|null $transferUrl */
@endphp

<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    @if ($blockingReasons !== [])
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300">
            <p class="font-semibold">Gerade geht das noch nicht.</p>
            @foreach ($blockingReasons as $reason)
                <p class="mt-1">{{ $reason }}</p>
            @endforeach
        </div>
    @endif

    <div>
        <p class="font-semibold text-gray-950 dark:text-white">Das wird gelöscht</p>
        <ul class="mt-1 list-disc space-y-1 ps-5">
            @foreach ($consequences['deleted'] as $consequence)
                <li>{{ $consequence }}</li>
            @endforeach
        </ul>
    </div>

    <div>
        <p class="font-semibold text-gray-950 dark:text-white">Das bleibt für die anderen Gruppen</p>
        <ul class="mt-1 list-disc space-y-1 ps-5">
            @foreach ($consequences['kept'] as $consequence)
                <li>{{ $consequence }}</li>
            @endforeach
        </ul>
    </div>

    @if ($transferUrl)
        <p class="text-gray-500 dark:text-gray-400">
            Soll die Gruppe ohne dich weiterlaufen? Dann übergib stattdessen die Owner-Rolle unter
            <a href="{{ $transferUrl }}" class="font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400">Mitglieder & Einladungen</a>.
        </p>
    @endif

    <p class="font-semibold text-danger-600 dark:text-danger-400">Das lässt sich nicht rückgängig machen.</p>
</div>
