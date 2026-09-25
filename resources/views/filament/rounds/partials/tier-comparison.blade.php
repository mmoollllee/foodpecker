@php
    use App\Models\CartItem;
    use App\Services\Money\Money;

    /** @var \App\Models\ProposalItem $item */
    /** @var \Illuminate\Support\Collection $options */
    $unit = $item->product?->unitLabel();
    $sumMin = $options->first() ? collect($options->first()['result']->allocations)->sum('requestedMin') : 0;
    $sumMax = $options->first() ? collect($options->first()['result']->allocations)->sum('requestedMax') : 0;
@endphp

<div class="space-y-2 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        Gewünscht: {{ CartItem::formatQuantity($sumMin) }}–{{ CartItem::formatQuantity($sumMax) }} {{ $unit }}.
        So sähe es mit den Listenpreisen der Gebindegrößen aus:
    </p>

    <div class="overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="border-b border-gray-200 text-left dark:border-white/10">
                <tr>
                    <th class="py-1.5 pr-3 font-semibold">Gebinde</th>
                    <th class="px-3 py-1.5 text-right font-semibold">Anzahl</th>
                    <th class="px-3 py-1.5 text-right font-semibold">Menge</th>
                    <th class="px-3 py-1.5 text-right font-semibold">Preis</th>
                    <th class="px-3 py-1.5 text-right font-semibold">pro {{ $unit }}</th>
                    <th class="px-3 py-1.5 font-semibold">Passt?</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($options as $option)
                    <tr @class(['bg-amber-500/5' => $option['tier']->id === $item->price_tier_id])>
                        <td class="py-1.5 pr-3">{{ $option['tier']->label }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $option['result']->packagesOrdered }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ CartItem::formatQuantity($option['result']->totalQuantity) }} {{ $unit }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ Money::format($option['result']->totalPriceCents) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ number_format($option['price_per_unit_cents'] / 100, 2, ',', '.') }} €</td>
                        <td class="px-3 py-1.5">
                            @if ($option['result']->feasible)
                                <span class="text-emerald-700 dark:text-emerald-300">✅ passt</span>
                            @else
                                <span class="text-amber-700 dark:text-amber-300" title="{{ implode(' ', $option['result']->notes) }}">⚠️ {{ \Illuminate\Support\Str::limit(implode(' ', $option['result']->notes), 60) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
