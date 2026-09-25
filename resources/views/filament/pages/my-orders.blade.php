@php
    use App\Models\CartItem;
    use App\Services\Money\Money;

    /** @var \Illuminate\Support\Collection $orders */
    /** @var \Closure $roundUrl */
@endphp

<x-filament-panels::page>
    @forelse ($orders as $order)
        @php
            /** @var \App\Models\Round $round */
            $round = $order['round'];
            $totals = $order['totals'];
            $payment = $order['payment'];
        @endphp

        <x-filament::section>
            <x-slot name="heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span>{{ $round->title }}</span>
                    <div class="flex items-center gap-2">
                        <x-filament::badge :color="$round->phase->getColor()" size="sm">{{ $round->phase->getLabel() }}</x-filament::badge>
                        <a href="{{ $roundUrl($round) }}" class="text-xs font-normal text-gray-500 underline hover:text-primary-600">Zur Runde →</a>
                    </div>
                </div>
            </x-slot>
            <x-slot name="description">
                Lead: {{ $round->lead?->fullName() ?? '—' }}
                @if ($round->expected_delivery)
                    · Lieferung {{ $round->expected_delivery->format('d.m.Y') }}
                @endif
            </x-slot>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 text-left dark:border-white/10">
                        <tr>
                            <th class="py-2 pr-4 font-semibold">Produkt</th>
                            <th class="px-3 py-2 font-semibold">Gebinde</th>
                            <th class="px-3 py-2 text-right font-semibold">Menge</th>
                            <th class="px-3 py-2 text-right font-semibold">Anteil</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($order['lines'] as $line)
                            <tr>
                                <td class="py-2 pr-4 font-medium">{{ $line['product'] }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ $line['package'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ CartItem::formatQuantity($line['quantity']) }} {{ $line['unit'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ Money::format($line['share_cents']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($totals)
                <div class="mt-3 grid grid-cols-2 gap-2 border-t border-gray-100 pt-2 text-xs text-gray-500 sm:grid-cols-4 dark:border-white/5">
                    <div>Waren: {{ Money::format($totals->goodsCents) }}</div>
                    <div>Versand: {{ Money::format($totals->shippingShareCents) }}</div>
                    <div>Beiträge: {{ Money::format($totals->leadFeeCents + $totals->platformFeeCents) }}</div>
                    <div>Spende: {{ Money::format((int) $payment?->round_up_donation_cents) }}</div>
                </div>
                <div class="mt-2 flex items-center justify-end gap-3 text-sm">
                    <span class="font-semibold tabular-nums">∑ {{ Money::format($payment?->totalCents() ?? $totals->subtotalCents()) }}</span>
                    @if ($payment)
                        <x-filament::badge :color="$payment->status->getColor()" size="sm">{{ $payment->status->getLabel() }}</x-filament::badge>
                    @endif
                </div>
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <div class="py-8 text-center text-gray-500">
                <div class="mb-2 text-3xl">🧾</div>
                <p class="text-sm">Noch keine Bestellungen. Sobald in einer Runde die finale Bestellung steht, erscheint sie hier.</p>
            </div>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
