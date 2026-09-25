@php
    use App\Enums\ManufacturerMailType;
    use App\Services\Money\Money;

    /** @var \App\Models\Round $round */
    /** @var \App\Enums\RoundPhase $phase */
    $chosen = $round->proposals->firstWhere('id', $round->chosen_proposal_id);
    $itemsByManufacturer = $chosen
        ? $chosen->items->loadMissing('product.manufacturer')->groupBy(fn ($item) => $item->product?->manufacturer_id)
        : collect();
@endphp

<x-foodpecker.phase-panel :round="$round" :phase="$phase">
    Alle haben bezahlt — jetzt bestellt der Lead bei den Herstellern. Die Mail an jeden Hersteller ist vorformuliert.

    <x-slot name="actions">
        @if ($phase === $round->phase)
            <x-foodpecker.action :action="$this->nextPhaseAction" />
        @endif
    </x-slot>

    <x-slot name="details">
        @if ($itemsByManufacturer->isEmpty())
            <p class="mt-4 text-sm text-gray-500">Was bestellt wird, steht fest, sobald ein Vorschlag als finale Bestellung gewählt ist.</p>
        @else
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                @foreach ($itemsByManufacturer as $manufacturerId => $items)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ $items->first()->product?->manufacturer?->name ?? '—' }}</span>
                            <x-foodpecker.action :action="($this->composeManufacturerMailAction)(['type' => ManufacturerMailType::Order->value, 'manufacturer' => (int) $manufacturerId])" />
                        </div>
                        <table class="mt-2 w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($items as $item)
                                    <tr>
                                        <td class="py-1.5 pe-3">{{ $item->product?->name }}</td>
                                        <td class="whitespace-nowrap px-3 py-1.5 text-gray-500">{{ $item->packages_ordered }} × {{ $item->packageLabel() }}</td>
                                        <td class="whitespace-nowrap py-1.5 ps-3 text-end tabular-nums">{{ Money::format((int) $item->total_price_cents) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-gray-200 font-semibold dark:border-white/10">
                                <tr>
                                    <td class="py-1.5 pe-3" colspan="2">Summe</td>
                                    <td class="whitespace-nowrap py-1.5 ps-3 text-end tabular-nums">{{ Money::format((int) $items->sum('total_price_cents')) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endforeach
            </div>
        @endif
    </x-slot>
</x-foodpecker.phase-panel>
