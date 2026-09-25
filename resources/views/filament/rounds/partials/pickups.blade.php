@php
    /** @var \App\Models\Round $round */
    $currentUserId = $this->currentUser()->id;
    $pickups = $round->pickups->sortBy(fn ($pickup) => [(int) $pickup->user_id !== $currentUserId, $pickup->user?->first_name]);
@endphp

@include('filament.rounds.partials.pickup-date-chips', ['round' => $round, 'withCounts' => $pickups->isNotEmpty()])

@if ($pickups->isEmpty())
    <p class="mt-4 text-sm text-gray-500">Die Abholungen entstehen zusammen mit den Zahlungen, sobald die finale Bestellung gewählt ist.</p>
@else
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($pickups as $pickup)
            <div @class([
                'rounded-lg border p-3',
                'border-emerald-500/40 bg-emerald-500/5' => $pickup->isPickedUp(),
                'border-primary-500/40' => ! $pickup->isPickedUp() && (int) $pickup->user_id === $currentUserId,
                'border-gray-200 dark:border-white/10' => ! $pickup->isPickedUp() && (int) $pickup->user_id !== $currentUserId,
            ])>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-sm font-medium">
                            {{ $pickup->user?->fullName() }}
                            @if ((int) $pickup->user_id === $currentUserId)
                                <span class="text-xs font-normal text-gray-500">(du)</span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500">
                            @if ($pickup->isPickedUp())
                                ✅ abgeholt {{ $pickup->picked_up_at?->diffForHumans() }}
                            @elseif ($pickup->pickupDate)
                                📅 {{ $pickup->pickupDate->scheduled_at->format('d.m. H:i') }}
                            @else
                                noch kein Termin gewählt
                            @endif
                        </div>
                    </div>
                    <x-foodpecker.action :action="($this->togglePickupAction)(['pickup' => $pickup->id])" />
                </div>
                <div class="mt-1">
                    <x-foodpecker.action :action="($this->choosePickupDateAction)(['pickup' => $pickup->id])" />
                </div>
            </div>
        @endforeach
    </div>
@endif
