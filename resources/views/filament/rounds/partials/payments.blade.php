@php
    use App\Services\Money\Money;

    /** @var \App\Models\Round $round */
    $myPayment = $round->payments->firstWhere('user_id', $this->currentUser()->id);
    $payments = $round->payments->sortBy(fn ($payment) => $payment->user?->first_name);
@endphp

@if ($payments->isEmpty())
    <p class="mt-4 text-sm text-gray-500">Die Zahlungen entstehen, sobald ein Vorschlag als finale Bestellung gewählt ist.</p>
@else
    @if ($myPayment)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 p-4 dark:bg-white/5">
            <div>
                <div class="text-sm text-gray-500">Dein Anteil</div>
                <div class="text-2xl font-semibold tabular-nums">{{ Money::format($myPayment->totalCents()) }}</div>
                <div class="text-xs text-gray-500">an {{ $round->lead?->fullName() ?? 'den Lead' }}</div>
                @if ($myPayment->round_up_donation_cents > 0)
                    <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                        Darin enthalten: {{ Money::format($myPayment->round_up_donation_cents) }} Spende an den Foodpecker-Verein — danke!
                    </div>
                @endif
            </div>
            <div class="flex flex-col items-end gap-2">
                <x-filament::badge :color="$myPayment->status->getColor()">{{ $myPayment->status->getLabel() }}</x-filament::badge>
                <x-foodpecker.action :action="$this->roundUpAction" />
            </div>
        </div>
    @endif

    <div class="mt-4 overflow-x-auto">
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
