@php
    use App\Models\CartItem;
    use App\Services\Money\Money;

    /** @var \App\Models\OrderProposal $proposal */
    $round = $this->getRound();
    $totals = $this->totalsFor($proposal);
    $estimate = $this->listPriceEstimate();
    $problems = $proposal->items->map(fn ($item) => $item->blockingProblem())->filter()->values();
    $itemsBySupplier = $proposal->items
        ->sortBy(fn ($item) => $item->product?->name)
        ->groupBy(fn ($item) => (int) $item->product?->supplier_id);
    $names = $round->participants->mapWithKeys(fn ($participant) => [(int) $participant->user_id => $participant->user?->first_name ?? '—']);
    $wishes = $round->cartItems->keyBy(fn (CartItem $cartItem): string => $cartItem->product_id.'-'.$cartItem->user_id);
@endphp

<x-filament::section
    :heading="$proposal->title"
    :description="'Entwurf von '.($proposal->proposedBy?->fullName() ?? '—').' · rechnet sich bei jeder Änderung neu'"
    collapsible
    persist-collapsed
    :collapse-id="'proposal-'.$proposal->id"
>
    <x-slot name="afterHeader">
        <div class="flex items-center gap-3">
            <x-filament::badge color="gray">Entwurf</x-filament::badge>
            <span class="text-sm font-semibold tabular-nums">{{ Money::format($totals->grandTotalCents) }}</span>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if ($proposal->description)
            <p class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-400">{{ $proposal->description }}</p>
        @endif

        @include('filament.rounds.partials.proposal-changes', ['proposal' => $proposal])

        <div class="flex flex-wrap items-center gap-2 [&:not(:has(*))]:hidden">
            <x-foodpecker.action :action="($this->publishProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->roundProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->editProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->deleteProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="$this->draftMenu($proposal)" />
        </div>

        @if ($problems->isNotEmpty())
            <div class="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-300">
                <p class="font-medium">Bevor der Vorschlag zur Abstimmung kann:</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($itemsBySupplier as $supplierId => $items)
            @php
                $supplier = $items->first()->product?->supplier;
                $shipping = $proposal->shippingCentsFor($supplierId);
            @endphp
            <div>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h4 class="text-sm font-semibold">{{ $supplier?->name ?? 'Lieferant' }}</h4>
                    <span class="text-xs text-gray-500">Versand {{ ! $proposal->hasShippingFor((int) $supplierId) ? 'noch offen' : ($shipping > 0 ? Money::format($shipping) : 'frei') }}</span>
                </div>

                <div class="mt-2 space-y-3">
                    @foreach ($items as $item)
                        @php
                            $unit = $item->product?->unitLabel();
                            $problem = $item->blockingProblem();
                            $overhang = $item->overhang();
                            $roundingOptions = $this->roundingOptions($item);
                            $allocations = $item->allocations->sortBy(fn ($allocation) => $names->get((int) $allocation->user_id));
                        @endphp
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="draft-item-{{ $item->id }}-{{ $item->updated_at?->getTimestamp() }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $item->product?->name }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ $item->describePackages() }}
                                        · {{ CartItem::formatAmount($item->totalQuantity(), $unit) }}
                                        · {{ Money::format($item->total_price_cents) }}
                                        @if ($item->packages_fixed)
                                            · <span class="font-medium">Anzahl von Hand</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-3">
                                        <x-foodpecker.action :action="($this->editPackagesAction)(['item' => $item->id])" />
                                        @if ($roundingOptions !== [])
                                            <label class="flex items-center gap-1 text-xs text-gray-500">
                                                Runden auf
                                                <select
                                                    class="rounded-md border-gray-300 py-0 pe-7 ps-2 text-xs dark:border-white/10 dark:bg-white/5"
                                                    wire:change="updateRounding({{ $item->id }}, $event.target.value)"
                                                >
                                                    <option value="" @selected($item->rounding_step === null)>{{ CartItem::formatAmount((float) $item->portion_size, $unit) }} (Portion)</option>
                                                    @foreach ($roundingOptions as $step => $label)
                                                        <option value="{{ $step }}" @selected($item->rounding_step !== null && abs((float) $item->rounding_step - (float) $step) < 0.0001)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @endif
                                    </div>
                                </div>

                                @if ($problem)
                                    <span class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-medium text-rose-700 dark:text-rose-300">passt nicht</span>
                                @elseif ($overhang > 0.001)
                                    <span class="rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">{{ CartItem::formatAmount($overhang, $unit) }} übrig</span>
                                @else
                                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">✓ passt</span>
                                @endif
                            </div>

                            @if ($item->notes)
                                <div class="mt-1 whitespace-pre-line text-xs text-amber-700 dark:text-amber-300">{{ $item->notes }}</div>
                            @endif

                            <div class="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($allocations as $allocation)
                                    @php
                                        $wish = $wishes->get($item->product_id.'-'.$allocation->user_id);
                                        $wishText = $wish ? 'will '.$wish->summary() : null;
                                    @endphp
                                    {{-- Allocations are saved anew on every change, so the key replaces inputs that still show typed values. --}}
                                    <div class="flex items-center gap-2 text-sm" wire:key="allocation-{{ $allocation->id }}">
                                        <span class="w-20 shrink-0 truncate" title="{{ $allocation->user?->fullName() }}">{{ $names->get((int) $allocation->user_id) }}</span>
                                        <x-filament::input.wrapper :suffix="$unit" class="w-28 shrink-0">
                                            <x-filament::input
                                                type="text"
                                                inputmode="decimal"
                                                :value="CartItem::toInputString((float) $allocation->quantity)"
                                                wire:change="updateAllocation({{ $item->id }}, {{ $allocation->user_id }}, $event.target.value)"
                                                :aria-label="'Menge für '.$names->get((int) $allocation->user_id)"
                                            />
                                        </x-filament::input.wrapper>
                                        <span class="min-w-0 text-xs text-gray-500">
                                            {{ Money::format((int) $allocation->share_cents) }}
                                            @if ($allocation->is_manual)
                                                · von Hand
                                                <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" wire:click="resetAllocation({{ $item->id }}, {{ $allocation->user_id }})" title="Wieder automatisch verteilen">zurücksetzen</button>
                                            @elseif ($wishText)
                                                · {{ $wishText }}
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($totals->perParticipant !== [])
            <div class="border-t border-gray-100 pt-3 dark:border-white/5">
                <div class="text-xs font-medium text-gray-500">Pro Person — mit Versand und Beiträgen</div>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($totals->perParticipant as $share)
                        @php
                            // Goods and fees on both sides — shipping and rounding differences aren't price changes.
                            $expected = $estimate->totalCentsFor($share->userId);
                            $difference = $estimate->withFees($share->goodsCents) - $expected;
                        @endphp
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs dark:bg-white/5">
                            <span class="font-medium">{{ $names->get($share->userId) }}</span>
                            <span class="tabular-nums">{{ Money::format($share->subtotalCents()) }}</span>
                            @if ($expected > 0 && abs($difference) >= 1)
                                <span @class(['tabular-nums', 'text-rose-600 dark:text-rose-400' => $difference > 0, 'text-emerald-600 dark:text-emerald-400' => $difference < 0])
                                      title="Gegenüber der Schätzung mit Listenpreisen, ohne Versand">
                                    {{ $difference > 0 ? '+' : '−' }}{{ Money::format(abs($difference)) }}
                                </span>
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament::section>
