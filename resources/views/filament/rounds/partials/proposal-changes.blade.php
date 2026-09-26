@php
    use App\Models\CartItem;
    use App\Services\Money\Money;

    /** @var \App\Models\OrderProposal $proposal */
    $base = $this->baseVersionOf($proposal);
    $changes = $base ? $this->changesFor($proposal) : null;
    $myId = $this->currentUser()->id;
    $amount = fn (float $value, string $unit): string => $value > 0 ? CartItem::formatAmount($value, $unit) : '—';
    $who = fn (array $change): string => $change['user_id'] === $myId ? 'du' : $change['name'];
@endphp

@if ($changes)
    {{-- wire:ignore.self: updates of the page leave it open or closed as the reader left it. --}}
    <details class="rounded-lg border border-sky-500/30 bg-sky-500/5 px-3 py-2 text-sm" wire:ignore.self @if (! $changes->isEmpty()) open @endif>
        <summary class="cursor-pointer select-none font-medium text-sky-800 dark:text-sky-200">
            Änderungen gegenüber „{{ $base->title }}“{{ $changes->isEmpty() ? ': keine' : '' }}
        </summary>

        @unless ($changes->isEmpty())
            <ul class="mt-2 space-y-2">
                @foreach ($changes->items as $change)
                    <li>
                        @if ($change->isAdded())
                            <span class="font-medium">Neu: {{ $change->product }}</span> · {{ $change->packagesAfter }}
                        @elseif ($change->isRemoved())
                            <span class="font-medium">Entfällt: {{ $change->product }}</span>
                        @else
                            <span class="font-medium">{{ $change->product }}</span>
                            @if ($change->packagesChanged())
                                · {{ $change->packagesBefore }} → {{ $change->packagesAfter }}
                            @endif
                        @endif

                        @if ($change->prices !== [] || $change->quantities !== [])
                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                @foreach ($change->prices as $price)
                                    <span class="me-2">Preis {{ $price['label'] }}: {{ Money::format($price['before']) }} → {{ Money::format($price['after']) }}</span>
                                @endforeach
                                @foreach ($change->quantities as $quantity)
                                    <span @class(['me-2', 'font-semibold text-gray-950 dark:text-white' => $quantity['user_id'] === $myId])>{{ $who($quantity) }}: {{ $amount($quantity['before'], $change->unit) }} → {{ $amount($quantity['after'], $change->unit) }}</span>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach

                @foreach ($changes->shipping as $shipping)
                    <li>
                        <span class="font-medium">Versand {{ $shipping['supplier'] }}</span> · {{ Money::format($shipping['before']) }} → {{ Money::format($shipping['after']) }}
                    </li>
                @endforeach
            </ul>

            <p class="mt-2 border-t border-sky-500/20 pt-2 text-xs text-gray-600 dark:text-gray-400">
                Summe {{ Money::format($changes->totalBefore) }} → {{ Money::format($changes->totalAfter) }}
                @foreach ($changes->subtotals as $subtotal)
                    · <span @class(['font-semibold text-gray-950 dark:text-white' => $subtotal['user_id'] === $myId])>{{ $who($subtotal) }}: {{ Money::format($subtotal['before']) }} → {{ Money::format($subtotal['after']) }}</span>
                @endforeach
            </p>
        @endunless
    </details>
@endif
