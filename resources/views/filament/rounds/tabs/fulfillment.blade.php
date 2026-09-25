@php
    use App\Enums\PaymentStatus;
    use App\Services\Money\Money;

    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $currentUserId = $this->currentUser()->id;
    $myPayment = $round->payments->firstWhere('user_id', $currentUserId);
    $payments = $round->payments->sortBy(fn ($payment) => $payment->user?->first_name);
    $pickups = $round->pickups->sortBy(fn ($pickup) => $pickup->user?->first_name);
    $pickupCounts = $round->pickups->whereNotNull('pickup_date_id')->countBy('pickup_date_id');
@endphp

<div class="space-y-4">
    @if ($myPayment)
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-sm text-gray-500">Dein Anteil</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ Money::format($myPayment->totalCents()) }}</div>
                    <div class="text-xs text-gray-500">
                        an {{ $round->lead?->fullName() ?? 'den Lead' }}
                        @if ($round->payment_deadline)
                            · bis {{ $round->payment_deadline->format('d.m.Y') }}
                        @endif
                    </div>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <x-filament::badge :color="$myPayment->status->getColor()">{{ $myPayment->status->getLabel() }}</x-filament::badge>
                    <x-foodpecker.action :action="$this->roundUpAction" />
                </div>
            </div>
            @if ($myPayment->round_up_donation_cents > 0)
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                    Darin enthalten: {{ Money::format($myPayment->round_up_donation_cents) }} Spende an den Foodpecker-Verein — danke!
                </p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section heading="Zahlungen">
        <x-slot name="description">Die Zahlung läuft außerhalb von Foodpecker (Überweisung, bar, …). Der Lead hakt eingegangene Beträge hier ab; erst wenn alle bezahlt haben, geht die Bestellung raus.</x-slot>

        @if ($payments->isEmpty())
            <p class="text-sm text-gray-500">Noch keine Zahlungen — sie entstehen automatisch, sobald ein Vorschlag als finale Bestellung gewählt wurde.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-white/10">
                        <tr class="text-left">
                            <th class="py-2 pr-4 font-semibold">Teilnehmer</th>
                            <th class="px-3 py-2 text-right font-semibold">Betrag</th>
                            <th class="px-3 py-2 text-right font-semibold">Spende</th>
                            <th class="px-3 py-2 text-right font-semibold">∑</th>
                            <th class="px-3 py-2 font-semibold">Status</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($payments as $payment)
                            <tr>
                                <td class="py-2 pr-4">{{ $payment->user?->fullName() }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ Money::format($payment->amount_cents) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-amber-700 dark:text-amber-300">
                                    {{ $payment->round_up_donation_cents > 0 ? '+ '.Money::format($payment->round_up_donation_cents) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ Money::format($payment->totalCents()) }}</td>
                                <td class="px-3 py-2">
                                    <x-filament::badge :color="$payment->status->getColor()" size="sm">{{ $payment->status->getLabel() }}</x-filament::badge>
                                    @if ($payment->paid_at)
                                        <span class="text-xs text-gray-500"> · {{ $payment->paid_at->format('d.m.') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-3">
                                        <x-foodpecker.action :action="($this->markPaymentAction)(['payment' => $payment->id])" />
                                        <x-foodpecker.action :action="($this->waivePaymentAction)(['payment' => $payment->id])" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->canManage() && ($transfer = $this->associationTransfer()))
                <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">
                    An den Foodpecker-Verein weiterleiten: Vereinsbeitrag {{ Money::format($transfer['fee']) }}
                    + Spenden {{ Money::format($transfer['donations']) }}
                    = <strong>{{ Money::format($transfer['fee'] + $transfer['donations']) }}</strong>
                </p>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section heading="Abholung">
        <x-slot name="description">Jede:r wählt einen Abholtermin und hakt die eigene Abholung ab — der Lead kann das für alle tun.</x-slot>

        @if ($round->pickupDates->isNotEmpty())
            <div class="mb-3 flex flex-wrap gap-2 text-xs">
                @foreach ($round->pickupDates as $pickupDate)
                    <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/5">
                        📅 {{ $pickupDate->scheduled_at->format('d.m. H:i') }}{{ $pickupDate->location ? ' · '.$pickupDate->location : '' }}
                        · <strong>{{ $pickupCounts[$pickupDate->id] ?? 0 }}</strong> angemeldet
                    </span>
                @endforeach
            </div>
        @endif

        @if ($pickups->isEmpty())
            <p class="text-sm text-gray-500">Noch keine Abholungen — sie entstehen zusammen mit den Zahlungen.</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($pickups as $pickup)
                    <div @class([
                        'rounded-lg border p-3',
                        'border-emerald-500/40 bg-emerald-500/5' => $pickup->isPickedUp(),
                        'border-gray-200 dark:border-white/10' => ! $pickup->isPickedUp(),
                    ])>
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="text-sm font-medium">{{ $pickup->user?->fullName() }}</div>
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
    </x-filament::section>
</div>
