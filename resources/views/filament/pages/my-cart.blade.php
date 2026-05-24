@php
    /** @var \Illuminate\Support\Collection $rounds */
    /** @var \Closure $detailUrl */
@endphp

<x-filament-panels::page>
    @if ($rounds->isEmpty())
        <x-filament::section>
            <div class="text-center py-8 text-gray-500">
                <div class="text-3xl mb-2">🛒</div>
                <p class="text-sm">
                    Gerade ist keine Bestellrunde in der Einkaufsphase.
                    Sobald eine Runde startet, kannst du hier deinen Warenkorb füllen.
                </p>
            </div>
        </x-filament::section>
    @else
        @foreach ($rounds as $round)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-3">
                        <span>{{ $round->title }}</span>
                        <a href="{{ $detailUrl($round) }}" class="text-xs font-normal text-gray-500 hover:text-primary-600 underline">
                            Zur Runde →
                        </a>
                    </div>
                </x-slot>
                <x-slot name="description">
                    <div class="flex flex-wrap items-center gap-3 text-xs">
                        <span>Lead: <strong>{{ $round->lead?->fullName() ?? '—' }}</strong></span>
                        @if ($round->shopping_deadline)
                            <span>· Einkauf bis: <strong>{{ $round->shopping_deadline->format('d.m.Y') }}</strong></span>
                        @endif
                    </div>
                </x-slot>

                @if ($round->cartItems->isEmpty())
                    <p class="text-sm text-gray-500 mb-3">
                        Du hast in dieser Runde noch nichts im Warenkorb.
                    </p>
                @else
                    <div class="overflow-x-auto mb-3">
                        <table class="min-w-full text-sm">
                            <thead class="border-b border-gray-200 dark:border-white/10">
                                <tr class="text-left">
                                    <th class="py-2 pr-4 font-semibold">Produkt</th>
                                    <th class="py-2 px-3 font-semibold">Hersteller</th>
                                    <th class="py-2 px-3 font-semibold">Verpackung</th>
                                    <th class="py-2 px-3 font-semibold">Menge</th>
                                    <th class="py-2 px-3 font-semibold">Notiz</th>
                                    <th class="py-2 px-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($round->cartItems as $item)
                                    <tr>
                                        <td class="py-2 pr-4 font-medium">{{ $item->product?->name }}</td>
                                        <td class="py-2 px-3 text-gray-600 dark:text-gray-300">{{ $item->product?->manufacturer?->name }}</td>
                                        <td class="py-2 px-3 text-gray-500">{{ $item->product?->packagingSummary() }}</td>
                                        <td class="py-2 px-3">
                                            <span @class([
                                                'inline-block rounded px-2 py-0.5 text-xs',
                                                'bg-sky-500/10 text-sky-700 dark:text-sky-300'     => $item->quantity_mode->value === 'exact',
                                                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $item->quantity_mode->value === 'flexible',
                                            ])>{{ $item->summary() }}</span>
                                        </td>
                                        <td class="py-2 px-3 text-xs text-gray-500 italic">{{ \Illuminate\Support\Str::limit($item->notes ?? '', 40) }}</td>
                                        <td class="py-2 px-3 text-right space-x-2 whitespace-nowrap">
                                            {{ ($this->editItemAction)(['cart_item_id' => $item->id]) }}
                                            <button wire:click="removeItem({{ $item->id }})"
                                                    wire:confirm="Diesen Artikel wirklich entfernen?"
                                                    class="text-xs text-rose-600 hover:text-rose-700 underline">
                                                entfernen
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div>
                    {{ ($this->addItemAction)(['round_id' => $round->id]) }}
                </div>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
