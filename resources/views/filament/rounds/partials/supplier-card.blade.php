@php
    use App\Enums\SupplierMailType;
    use App\Services\Money\Money;

    /** @var \App\Models\Supplier $supplier */
    /** @var \App\Models\RoundSupplier|null $record */
    $editable = $this->canRecordSupplierFeedback();
    $packagesByProduct = $this->feedbackPackages($supplier);
    $confirmed = $this->getRound()->packagePrices()->get()->keyBy('price_tier_id');
    $hasResponded = (bool) $record?->hasResponded();
    $wasAsked = $record?->inquired_at !== null;
@endphp

<section class="rounded-xl border border-gray-200 p-4 dark:border-white/10" wire:key="supplier-{{ $supplier->id }}" aria-label="{{ $supplier->name }}">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="font-medium">{{ $supplier->name }}</div>
            <div class="mt-1 text-xs">
                @if ($hasResponded)
                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 font-medium text-emerald-700 dark:text-emerald-300">✓ Rückmeldung {{ $record->responded_at->format('d.m.') }}</span>
                @elseif ($wasAsked)
                    <span class="rounded-full bg-amber-500/10 px-2 py-0.5 font-medium text-amber-700 dark:text-amber-300">angefragt {{ $record->inquired_at->format('d.m.') }} · wartet auf Antwort</span>
                @else
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300">noch nicht angefragt</span>
                @endif
            </div>
        </div>

        @if ($editable)
            <div class="flex flex-wrap items-center gap-2">
                @unless ($hasResponded)
                    <x-filament::button size="xs" :color="$wasAsked ? 'gray' : 'primary'" icon="heroicon-o-envelope" wire:click="openSupplierMail({{ $supplier->id }}, '{{ SupplierMailType::PriceInquiry->value }}')">
                        {{ $wasAsked ? 'Anfrage erneut öffnen' : 'Anfrage öffnen' }}
                    </x-filament::button>
                @endunless
                @if ($wasAsked && ! $hasResponded)
                    <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-uturn-right" wire:click="openSupplierMail({{ $supplier->id }}, '{{ SupplierMailType::FollowUp->value }}')">
                        Nachfassen
                    </x-filament::button>
                @endif
                <x-foodpecker.action :action="($this->composeSupplierMailAction)(['type' => SupplierMailType::PriceInquiry->value, 'supplier' => $supplier->id, 'label' => 'Text anpassen'])" />
            </div>
        @endif
    </div>

    <div class="mt-3 space-y-3">
        @foreach ($packagesByProduct as $productId => $tiers)
            <div>
                <div class="text-sm font-medium">
                    {{ $tiers->first()->product?->name }}
                    <span class="font-normal text-gray-500">· gewünscht {{ $this->wantedQuantity((int) $productId) }}</span>
                </div>

                <div class="mt-1 divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($tiers as $tier)
                        @php
                            $price = $confirmed->get($tier->id);
                            $difference = $price?->price_cents !== null && $tier->price_cents > 0 ? ($price->price_cents - $tier->price_cents) / $tier->price_cents * 100 : null;
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-2 py-1.5 text-sm">
                            <div class="min-w-0">
                                {{ $tier->label }}
                                @if ($tier->article_number)
                                    <span class="text-xs text-gray-500">Art.-Nr. {{ $tier->article_number }}</span>
                                @endif
                                <div class="text-xs text-gray-500">
                                    Liste {{ $tier->formattedPrice() }}
                                    @if ($difference !== null && abs($difference) >= 0.5)
                                        <span @class(['font-medium', 'text-rose-600 dark:text-rose-400' => $difference > 0, 'text-emerald-600 dark:text-emerald-400' => $difference < 0])>
                                            · {{ $difference > 0 ? '+' : '−' }}{{ number_format(abs($difference), 0, ',', '.') }} %
                                        </span>
                                    @endif
                                    @if ($price && ! $price->is_available)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">· nicht lieferbar</span>
                                    @endif
                                </div>
                            </div>

                            @if ($editable)
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                                        <x-filament::input.checkbox wire:model="supplierFeedback.{{ $supplier->id }}.unavailable.{{ $tier->id }}" />
                                        nicht lieferbar
                                    </label>
                                    <x-filament::input.wrapper suffix="€" class="w-32">
                                        <x-filament::input
                                            type="text"
                                            inputmode="decimal"
                                            wire:model="supplierFeedback.{{ $supplier->id }}.prices.{{ $tier->id }}"
                                            :placeholder="Money::toInputString($tier->price_cents)"
                                            :aria-label="'Bestätigter Preis für '.$tier->label"
                                        />
                                    </x-filament::input.wrapper>
                                </div>
                            @else
                                <div class="text-sm tabular-nums">{{ Money::format($price?->price_cents ?? $tier->price_cents) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-white/5">
        @if ($editable)
            <div class="flex items-center gap-2 text-sm">
                <span>Versand</span>
                <x-filament::input.wrapper suffix="€" class="w-32">
                    <x-filament::input
                        type="text"
                        inputmode="decimal"
                        wire:model="supplierFeedback.{{ $supplier->id }}.shipping"
                        placeholder="0,00"
                        aria-label="Versandkosten"
                    />
                </x-filament::input.wrapper>
                <span class="text-xs text-gray-500">wird anteilig zum Warenwert verteilt</span>
            </div>
            <x-filament::button size="sm" wire:click="saveSupplierFeedback({{ $supplier->id }})">
                Rückmeldung speichern
            </x-filament::button>
        @else
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Versand: {{ $record?->shipping_cents !== null ? Money::format($record->shipping_cents) : 'noch offen' }}
            </div>
        @endif
    </div>
</section>
