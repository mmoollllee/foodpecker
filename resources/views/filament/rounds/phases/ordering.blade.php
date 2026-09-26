@php
    use App\Enums\SupplierMailType;
    use App\Services\Money\Money;

    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
    $chosen = $round->proposals->firstWhere('id', $round->chosen_proposal_id);
    $itemsBySupplier = $chosen
        ? $chosen->items->loadMissing(['product.supplier', 'packages'])->groupBy(fn ($item) => $item->product?->supplier_id)
        : collect();
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Alle haben bezahlt — jetzt bestellt der Lead bei den Lieferanten und hakt pro Lieferant ab, was raus ist. Die Mail an jeden Lieferanten ist vorformuliert.

    <x-slot name="actions">
        <x-foodpecker.action :action="$this->adoptCatalogPricesAction" />
    </x-slot>

    <x-slot name="details">
        @if ($itemsBySupplier->isEmpty())
            <p class="mt-4 text-sm text-gray-500">Was bestellt wird, steht fest, sobald ein Vorschlag als finale Bestellung gewählt ist.</p>
        @else
            @include('filament.rounds.partials.supplier-order-progress', ['round' => $round])

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                @foreach ($itemsBySupplier as $supplierId => $items)
                    @php
                        $supplierName = $items->first()->product?->supplier?->name ?? '—';
                        $order = $this->supplierOrderFor((int) $supplierId);
                    @endphp
                    <section class="rounded-lg border border-gray-200 p-4 dark:border-white/10" aria-label="{{ $supplierName }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $supplierName }}</div>
                                <div class="mt-1">
                                    @include('filament.rounds.partials.supplier-order-status', ['record' => $order])
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-foodpecker.action :action="($this->composeSupplierMailAction)([
                                    'type' => SupplierMailType::Order->value,
                                    'supplier' => (int) $supplierId,
                                    ...($order?->isOrdered() ? ['label' => 'Bestellmail erneut öffnen'] : []),
                                ])" />
                                <x-foodpecker.action :action="($this->toggleSupplierOrderedAction)(['supplier' => (int) $supplierId, 'ordered' => (bool) $order?->isOrdered()])" />
                            </div>
                        </div>
                        <table class="mt-2 w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($items as $item)
                                    @foreach ($item->packages as $package)
                                        <tr>
                                            <td class="py-1.5 pe-3">
                                                {{ $item->product?->name }}
                                                @if ($package->article_number)
                                                    <span class="block text-xs text-gray-500">Art.-Nr. {{ $package->article_number }}</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-1.5 text-gray-500">{{ $package->count }} × {{ $package->label }}</td>
                                            <td class="whitespace-nowrap py-1.5 ps-3 text-end tabular-nums">{{ Money::format($package->totalPriceCents()) }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-gray-200 font-semibold dark:border-white/10">
                                @if ($shipping = $chosen->shippingCentsFor((int) $supplierId))
                                    <tr class="text-xs font-normal text-gray-500">
                                        <td class="py-1 pe-3" colspan="2">Versand</td>
                                        <td class="whitespace-nowrap py-1 ps-3 text-end tabular-nums">{{ Money::format($shipping) }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td class="py-1.5 pe-3" colspan="2">Summe Ware</td>
                                    <td class="whitespace-nowrap py-1.5 ps-3 text-end tabular-nums">{{ Money::format((int) $items->sum('total_price_cents')) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </section>
                @endforeach
            </div>
        @endif
    </x-slot>
</x-foodpecker.phase-panel>
