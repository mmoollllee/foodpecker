@php
    use App\Enums\QuantityMode;
    use App\Enums\RoundPhase;
    use App\Models\CartItem;
    use App\Services\Money\Money;

    /** @var \App\Models\Round|null $round */
    /** @var \App\Services\Estimates\RoundEstimate|null $estimate */
    $userId = auth()->id();
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
                        Such dir unten etwas aus — exakt oder als flexible Spanne.
                    @endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 dark:border-white/10">
                            <tr class="text-left">
                                <th class="py-2 pr-4 font-semibold">Produkt</th>
                                <th class="px-3 py-2 font-semibold">Lieferant</th>
                                <th class="px-3 py-2 font-semibold">Menge</th>
                                <th class="px-3 py-2 text-right font-semibold">Voraussichtlich</th>
                                <th class="px-3 py-2 font-semibold">Notiz</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($items as $item)
                                @php $share = $estimate->forProduct($item->product_id)?->shareCentsFor($userId) ?? 0; @endphp
                                <tr>
                                    <td class="py-2 pr-4">
                                        <div class="font-medium">{{ $item->product?->name }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ $item->preferredTier?->label ?? $item->product?->packagingSummary() }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $item->product?->supplier?->name }}</td>
                                    <td class="px-3 py-2">
                                        <span @class([
                                            'inline-block rounded px-2 py-0.5 text-xs',
                                            'bg-sky-500/10 text-sky-700 dark:text-sky-300' => $item->quantity_mode === QuantityMode::Exact,
                                            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $item->quantity_mode === QuantityMode::Flexible,
                                        ])>{{ $item->summary() }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $share > 0 ? '≈ '.Money::format($share) : '—' }}</td>
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
                        <tfoot class="border-t border-gray-200 dark:border-white/10">
                            <tr>
                                <td class="py-2 pr-4 font-semibold" colspan="3">
                                    Voraussichtlich gesamt
                                    <span class="block text-xs font-normal text-gray-500">inkl. Aufwandsentschädigung und Vereinsbeitrag · der Versand kommt nach den Rückmeldungen der Lieferanten dazu</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">≈ {{ Money::format($estimate->totalCentsFor($userId)) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
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
                            + {{ $suggestion['product_name'] }} · {{ CartItem::formatAmount($suggestion['quantity'], $suggestion['unit']) }}
                        </button>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @if ($canShop)
            <x-filament::section heading="Sortiment dieser Runde">
                <x-slot name="description">Stöbern, vergleichen, in den Korb legen. Die Preise sind geschätzt — je mehr die Gruppe bestellt, desto günstiger wird es oft.</x-slot>

                <div @class(['grid gap-2', 'sm:grid-cols-[minmax(0,1fr)_16rem]' => $suppliers->count() > 1])>
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Produkt oder Lieferant suchen" />
                    </x-filament::input.wrapper>

                    @if ($suppliers->count() > 1)
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="supplier">
                                <option value="">Alle Lieferanten</option>
                                @foreach ($suppliers as $supplierOption)
                                    <option value="{{ $supplierOption->id }}">{{ $supplierOption->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif
                </div>

                @if ($categories->count() > 1)
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($categories as $categoryOption)
                            <button
                                type="button"
                                wire:click="filterByCategory('{{ $categoryOption->value }}')"
                                @class([
                                    'rounded-full px-3 py-1 text-xs font-medium transition',
                                    'bg-primary-600 text-white' => $category === $categoryOption->value,
                                    'bg-gray-100 text-gray-700 hover:bg-primary-500/10 dark:bg-white/5 dark:text-gray-300' => $category !== $categoryOption->value,
                                ])
                            >{{ $categoryOption->getLabel() }}</button>
                        @endforeach
                    </div>
                @endif

                @forelse ($catalog as $categoryLabel => $products)
                    <h3 class="mt-6 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $categoryLabel }}</h3>

                    <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($products as $product)
                            @php
                                $productEstimate = $estimate->forProduct($product->id);
                                $unit = $product->unitLabel();
                                $perUnit = $productEstimate?->pricePerUnitCents()
                                    ?? $product->priceTiers->map(fn ($tier) => $tier->pricePerUnit() * 100)->min();
                                $wishes = $demand->get($product->id, collect());
                                $wantedMin = $wishes->sum(fn (CartItem $item): float => $item->effectiveMin());
                                $wantedMax = $wishes->sum(fn (CartItem $item): float => $item->effectiveMax());
                                $mine = $myItems->get($product->id);
                            @endphp

                            <div @class([
                                'flex gap-3 rounded-xl border p-3',
                                'border-primary-500/50 bg-primary-500/5' => $mine,
                                'border-gray-200 dark:border-white/10' => ! $mine,
                            ]) wire:key="product-{{ $product->id }}">
                                <div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5">
                                    @if ($product->imageUrl())
                                        <img src="{{ $product->imageUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                                    @else
                                        <div class="flex h-full w-full items-center justify-center text-lg font-semibold text-gray-400">{{ $product->initials() }}</div>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="font-medium leading-tight">{{ $product->name }}</div>
                                    <div class="truncate text-xs text-gray-500">{{ $product->supplier?->name }} · {{ $product->distributionLabel() }}</div>

                                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                        @if ($perUnit !== null)
                                            <span class="font-medium tabular-nums">{{ $productEstimate ? '≈' : 'ab' }} {{ number_format($perUnit / 100, 2, ',', '.') }} € / {{ $unit }}</span>
                                        @endif
                                        @if ($wishes->isNotEmpty())
                                            · Gruppe: {{ CartItem::formatRange($wantedMin, $wantedMax, $unit) }}
                                        @endif
                                    </div>

                                    @if ($productEstimate && $productEstimate->freeQuantity() > 0.001)
                                        <div class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                                            Noch {{ CartItem::formatAmount($productEstimate->freeQuantity(), $unit) }} frei im Gebinde — sie sind ohnehin bestellt.
                                        </div>
                                    @endif

                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                        @if ($mine)
                                            <span class="rounded bg-primary-500/10 px-2 py-0.5 text-xs font-medium text-primary-700 dark:text-primary-300">Im Korb: {{ $mine->summary() }}</span>
                                            <x-filament::button size="xs" color="gray" wire:click="mountAction('editItem', { item: {{ $mine->id }} })">Ändern</x-filament::button>
                                        @else
                                            <span></span>
                                            <x-filament::button size="xs" icon="heroicon-m-plus" wire:click="mountAction('addItem', { product: {{ $product->id }} })">Hinzufügen</x-filament::button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="mt-4 text-sm text-gray-500">Keine Produkte gefunden.</p>
                @endforelse
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
