@php
    use App\Services\Money\Money;

    /** @var \Illuminate\Support\Collection<int, \App\Services\Rounds\CatalogPriceChange> $changes */
@endphp

<div class="space-y-2 text-start text-sm">
    <p>Diese Preise haben die Lieferanten in der Runde bestätigt. Im Sortiment gelten sie dann als Listenpreise — die nächste Runde schätzt damit.</p>

    <ul class="list-disc space-y-1 ps-5">
        @foreach ($changes as $change)
            <li>
                {{ $change->label() }}:
                <span class="whitespace-nowrap tabular-nums text-gray-500 line-through">{{ Money::format($change->fromCents) }}</span>
                → <span class="whitespace-nowrap font-medium tabular-nums">{{ Money::format($change->toCents) }}</span>
            </li>
        @endforeach
    </ul>
</div>
