@php
    /** @var string|null $intro */
    /** @var array<int, string> $missing */
@endphp

<div class="space-y-2 text-sm">
    @if ($intro)
        <p>{{ $intro }}</p>
    @endif

    @if ($missing === [])
        <p class="text-emerald-700 dark:text-emerald-300">✅ Alle Voraussetzungen sind erfüllt.</p>
    @else
        <p class="font-medium text-rose-700 dark:text-rose-300">Vorher fehlt noch:</p>
        <ul class="list-disc space-y-1 pl-5 text-rose-700 dark:text-rose-300">
            @foreach ($missing as $requirement)
                <li>{{ $requirement }}</li>
            @endforeach
        </ul>
    @endif
</div>
