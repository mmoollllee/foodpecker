@php
    use App\Enums\QuantityMode;
    use App\Enums\RoundPhase;
    use App\Filament\Pages\MyCart;
    use App\Models\CartItem;

    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $activeParticipants = $round->participants->where('removed', false)->sortBy(fn ($participant) => $participant->user?->first_name)->values();
    $excludedUserIds = $round->participants->where('removed', true)->pluck('user_id')->all();
    $activeItems = $round->cartItems->reject(fn (CartItem $item) => in_array($item->user_id, $excludedUserIds, true));
    $itemsByProduct = $activeItems->groupBy('product_id');
@endphp

<x-filament::section heading="Warenkörbe der Teilnehmer">
    <x-slot name="description">
        Alle Mitglieder sehen alle Warenkörbe — volle Transparenz innerhalb der Gruppe.
        @if ($round->phase === RoundPhase::Shopping && $this->currentUser()->can('shop', $round))
            <a href="{{ MyCart::getUrl() }}" class="font-medium text-primary-600 underline dark:text-primary-400">Deinen Warenkorb bearbeiten →</a>
        @endif
    </x-slot>

    @if ($itemsByProduct->isEmpty())
        <p class="text-sm text-gray-500">Noch keine Artikel im Warenkorb dieser Runde.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-white/10">
                    <tr class="text-left">
                        <th class="py-2 pr-4 font-semibold">Produkt</th>
                        @foreach ($activeParticipants as $participant)
                            <th class="px-3 py-2 text-center font-semibold">{{ $participant->user?->first_name ?? '—' }}</th>
                        @endforeach
                        <th class="px-3 py-2 text-right font-semibold">∑ Min</th>
                        <th class="px-3 py-2 text-right font-semibold">∑ Max</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($itemsByProduct as $items)
                        @php
                            $product = $items->first()->product;
                            $unit = $product?->unitLabel();
                        @endphp
                        <tr>
                            <td class="py-2 pr-4">
                                <div class="font-medium">
                                    {{ $product?->name }}
                                    @if ($product?->trashed())
                                        <span class="text-xs text-gray-400">(archiviert)</span>
                                    @endif
                                </div>
                                <div class="text-xs text-gray-500">{{ $product?->manufacturer?->name }} · {{ $product?->packagingSummary() }}</div>
                            </td>
                            @foreach ($activeParticipants as $participant)
                                @php $item = $items->firstWhere('user_id', $participant->user_id); @endphp
                                <td class="px-3 py-2 text-center">
                                    @if ($item)
                                        <span @class([
                                            'inline-block rounded px-2 py-0.5 text-xs',
                                            'bg-sky-500/10 text-sky-700 dark:text-sky-300' => $item->quantity_mode === QuantityMode::Exact,
                                            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $item->quantity_mode === QuantityMode::Flexible,
                                        ]) @if ($item->notes) title="{{ $item->notes }}" @endif>{{ $item->summary() }}</span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-right tabular-nums">{{ CartItem::formatQuantity($items->sum(fn (CartItem $item) => $item->effectiveMin())) }} {{ $unit }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ CartItem::formatQuantity($items->sum(fn (CartItem $item) => $item->effectiveMax())) }} {{ $unit }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($excludedUserIds !== [])
        <p class="mt-3 text-xs text-gray-500">
            Warenkörbe ausgeschlossener Teilnehmer werden nicht mehr berücksichtigt.
        </p>
    @endif
</x-filament::section>
