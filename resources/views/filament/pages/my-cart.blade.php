@php
    use App\Enums\QuantityMode;
    use App\Enums\RoundPhase;
    use App\Models\CartItem;

    /** @var \App\Models\Round|null $round */
@endphp

<x-filament-panels::page>
    @if (! $round)
        <x-filament::section>
            <div class="py-8 text-center text-gray-500">
                <div class="mb-2 text-3xl">🛒</div>
                <p class="text-sm">
                    Gerade läuft keine Bestellrunde.
                    Sobald die nächste startet, füllst du hier deinen Warenkorb.
                </p>
            </div>
        </x-filament::section>
    @else
        @if ($participant?->removed)
            <div class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-4 text-sm text-rose-700 dark:text-rose-300">
                Du wurdest aus dieser Bestellung ausgeschlossen: „{{ $participant->remove_reason }}“. Bei Fragen sprich den Lead direkt an.
            </div>
        @elseif ($round->phase !== RoundPhase::Shopping)
            <div class="rounded-xl border border-gray-200 p-4 text-sm text-gray-600 dark:border-white/10 dark:text-gray-300">
                Der Einkauf ist vorbei — die Runde ist in der Phase „{{ $round->phase->getLabel() }}“. Deine Wünsche lassen sich nicht mehr ändern;
                was du tatsächlich bekommst, steht in den
                <a href="{{ $roundUrl }}?phase=finalizing" class="font-medium text-primary-600 underline dark:text-primary-400">Vorschlägen der Runde</a>.
            </div>
        @elseif ($isFull)
            <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm text-amber-800 dark:text-amber-200">
                Die Runde ist voll (maximal {{ $round->max_participants }} Teilnehmer). Frag den Lead, ob noch jemand dazukommen kann.
            </div>
        @endif

        <x-filament::section heading="Dein Warenkorb">
            <x-slot name="description">
                {{ $items->count() }} Artikel · Alle in der Gruppe sehen, was du in den Korb legst.
            </x-slot>

            @if ($items->isEmpty())
                <p class="text-sm text-gray-500">
                    Noch nichts im Warenkorb.
                    @if ($canShop)
                        Leg los mit „Artikel hinzufügen“ — exakt oder als flexible Spanne.
                    @endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 dark:border-white/10">
                            <tr class="text-left">
                                <th class="py-2 pr-4 font-semibold">Produkt</th>
                                <th class="px-3 py-2 font-semibold">Hersteller</th>
                                <th class="px-3 py-2 font-semibold">Menge</th>
                                <th class="px-3 py-2 font-semibold">Notiz</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($items as $item)
                                <tr>
                                    <td class="py-2 pr-4">
                                        <div class="font-medium">{{ $item->product?->name }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ $item->preferredTier?->label ?? $item->product?->packagingSummary() }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $item->product?->manufacturer?->name }}</td>
                                    <td class="px-3 py-2">
                                        <span @class([
                                            'inline-block rounded px-2 py-0.5 text-xs',
                                            'bg-sky-500/10 text-sky-700 dark:text-sky-300' => $item->quantity_mode === QuantityMode::Exact,
                                            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $item->quantity_mode === QuantityMode::Flexible,
                                        ])>{{ $item->summary() }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-xs italic text-gray-500">{{ \Illuminate\Support\Str::limit($item->notes ?? '', 40) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <x-foodpecker.action :action="($this->editItemAction)(['item' => $item->id])" />
                                            <x-foodpecker.action :action="($this->removeItemAction)(['item' => $item->id])" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @if ($suggestions->isNotEmpty())
            <x-filament::section :heading="'Letztes Mal ('.$suggestions->first()['round_title'].') hast du bekommen'">
                <x-slot name="afterHeader">
                    <x-foodpecker.action :action="$this->copyPreviousOrderAction" />
                </x-slot>

                <div class="flex flex-wrap gap-2">
                    @foreach ($suggestions as $suggestion)
                        <button
                            type="button"
                            wire:click="mountAction('addItem', @js(['product' => $suggestion['product_id'], 'quantity' => $suggestion['quantity']]))"
                            class="rounded-full bg-gray-100 px-3 py-1 text-xs hover:bg-primary-500/10 dark:bg-white/5"
                        >
                            + {{ $suggestion['product_name'] }} · {{ CartItem::formatQuantity($suggestion['quantity']) }} {{ $suggestion['unit'] }}
                        </button>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
