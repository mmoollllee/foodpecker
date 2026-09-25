@php
    use App\Enums\QuantityMode;
    use App\Models\CartItem;

    /** @var \App\Models\Round $round */
    $currentUserId = $this->currentUser()->id;
    $excludedIds = $round->participants->where('removed', true)->pluck('user_id')->map(fn ($userId): int => (int) $userId)->all();

    // One column per person taking part: you first, then everybody else.
    $columns = $round->participants
        ->where('removed', false)
        ->sortBy(fn ($participant) => [(int) $participant->user_id !== $currentUserId, $participant->user?->first_name])
        ->values();
    $canEdit = $columns->mapWithKeys(fn ($participant): array => [(int) $participant->user_id => $this->canEditCartOf($participant->user)]);

    $itemsByProduct = $round->cartItems
        ->reject(fn (CartItem $item): bool => in_array((int) $item->user_id, $excludedIds, true))
        ->groupBy('product_id')
        ->sortBy(fn ($items) => $items->first()->product?->name);

    $chip = fn (CartItem $item): string => $item->quantity_mode === QuantityMode::Exact
        ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300'
        : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    $stickyCell = 'sticky left-0 z-10 bg-white dark:bg-gray-900';
@endphp

@if ($itemsByProduct->isEmpty())
    <p class="mt-4 text-sm text-gray-500">Noch nichts in den Warenkörben.</p>
@else
    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 dark:border-white/10">
                <tr class="text-left">
                    <th class="{{ $stickyCell }} min-w-48 py-2 pe-4 font-semibold">Produkt</th>
                    <th class="px-3 py-2 text-end font-semibold">Summe</th>
                    @foreach ($columns as $participant)
                        @php $isMe = (int) $participant->user_id === $currentUserId; @endphp
                        <th @class(['px-3 py-2 text-center font-semibold', 'bg-primary-500/5' => $isMe]) title="{{ $participant->user?->fullName() }}">
                            {{ $participant->user?->first_name ?? '—' }}
                            @if ($isMe)
                                <span class="block text-xs font-normal text-gray-500">du</span>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($itemsByProduct as $productId => $items)
                    @php
                        $product = $items->first()->product;
                        $unit = $product?->unitLabel();
                        $min = $items->sum(fn (CartItem $item): float => $item->effectiveMin());
                        $max = $items->sum(fn (CartItem $item): float => $item->effectiveMax());
                    @endphp
                    <tr class="align-top">
                        <td class="{{ $stickyCell }} py-2 pe-4">
                            <div class="font-medium">
                                {{ $product?->name }}
                                @if ($product?->trashed())
                                    <span class="text-xs text-gray-400">(archiviert)</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500">{{ $product?->manufacturer?->name }} · {{ $product?->packagingSummary() }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">
                            {{ CartItem::formatQuantity($min) }}{{ abs($max - $min) > 0.0001 ? '–'.CartItem::formatQuantity($max) : '' }} {{ $unit }}
                        </td>
                        @foreach ($columns as $participant)
                            @php
                                $userId = (int) $participant->user_id;
                                $item = $items->first(fn (CartItem $item): bool => (int) $item->user_id === $userId);
                                $tooltip = $item?->notes ? '„'.$item->notes.'“' : null;
                            @endphp
                            <td @class(['whitespace-nowrap px-3 py-2 text-center', 'bg-primary-500/5' => $userId === $currentUserId])>
                                @if ($canEdit[$userId] ?? false)
                                    <button
                                        type="button"
                                        wire:click="mountAction('editCartItem', @js(['product' => (int) $productId, 'user' => $userId]))"
                                        @if ($tooltip) x-tooltip="{ content: @js($tooltip), theme: $store.theme }" @endif
                                        @class([
                                            'rounded px-2 py-0.5 text-xs transition hover:ring-2 hover:ring-primary-500/50',
                                            $item ? $chip($item) : 'text-gray-400 hover:text-primary-600',
                                        ])
                                        aria-label="{{ $item ? 'Menge von '.$participant->user?->first_name.' ändern' : $product?->name.' für '.$participant->user?->first_name.' hinzufügen' }}"
                                    >{{ $item ? $item->summary() : '+' }}</button>
                                @elseif ($item)
                                    <span
                                        @if ($tooltip) x-tooltip="{ content: @js($tooltip), theme: $store.theme }" @endif
                                        class="inline-block rounded px-2 py-0.5 text-xs {{ $chip($item) }}"
                                    >{{ $item->summary() }}</span>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($excludedIds !== [])
    <p class="mt-3 text-xs text-gray-500">Warenkörbe ausgeschlossener Teilnehmer zählen nicht mehr.</p>
@endif
