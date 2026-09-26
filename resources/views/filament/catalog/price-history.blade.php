@php
    use App\Services\Money\Money;

    /** @var \App\Models\Product $product */
    $product = $this->getRecord();
    $tenant = \Filament\Facades\Filament::getTenant();
    $observations = $product->recentPriceObservations($tenant instanceof \App\Models\Group ? $tenant : null, limit: 20);
@endphp

@if ($observations->isEmpty())
    <p class="text-sm text-gray-500">Noch keine abgeschlossenen Bestellungen mit diesem Produkt.</p>
@else
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 text-left dark:border-white/10">
                <tr>
                    <th class="py-2 pr-4 font-semibold">Bestellt</th>
                    <th class="px-3 py-2 font-semibold">Gebinde</th>
                    <th class="px-3 py-2 text-right font-semibold">Preis</th>
                    <th class="px-3 py-2 text-right font-semibold">pro {{ $product->unitLabel() }}</th>
                    <th class="px-3 py-2 font-semibold">Gruppe</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($observations as $observation)
                    <tr>
                        <td class="py-2 pr-4">{{ $observation->observed_on->format('d.m.Y') }}</td>
                        <td class="px-3 py-2">{{ \App\Models\CartItem::formatAmount((float) $observation->package_amount, $product->unitLabel()) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ Money::format($observation->observed_price_cents) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($observation->pricePerUnitCents() / 100, 2, ',', '.') }} €</td>
                        <td class="px-3 py-2 text-gray-500">{{ $observation->group_id === $tenant?->getKey() ? 'eure Gruppe' : 'andere Gruppe' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
