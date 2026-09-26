@php
    use App\Enums\PaymentStatus;
    use App\Services\Money\Money;

    /** @var \App\Models\Round $round */
    $myPayment = $round->payments->firstWhere('user_id', $this->currentUser()->id);
    $payments = $round->payments->sortBy(fn ($payment) => $payment->user?->first_name);
    $lead = $round->lead;
    $isLead = $round->isLead($this->currentUser());
    $transfersToLead = $myPayment?->status === PaymentStatus::Pending && ! $isLead;
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

            @if ($transfersToLead)
                @if ($giroCode = $this->giroCodeFor($myPayment))
                    <div class="flex w-full flex-wrap items-start gap-4 border-t border-gray-200 pt-3 dark:border-white/10">
                        <img src="{{ $giroCode }}" alt="GiroCode für die Überweisung an {{ $lead->bankAccountHolderName() }}" class="h-36 w-36 shrink-0 rounded-md bg-white p-1">
                        <div class="min-w-0 flex-1 text-sm">
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                                <dt class="text-gray-500">Empfänger</dt>
                                <dd>{{ $lead->bankAccountHolderName() }}</dd>
                                <dt class="text-gray-500">IBAN</dt>
                                <dd class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="font-mono tabular-nums">{{ $lead->formattedIban() }}</span>
                                    <x-foodpecker.copy-button :value="$lead->iban" />
                                </dd>
                                @if ($lead->bic)
                                    <dt class="text-gray-500">BIC</dt>
                                    <dd class="font-mono">{{ $lead->bic }}</dd>
                                @endif
                                <dt class="text-gray-500">Betrag</dt>
                                <dd class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="tabular-nums">{{ Money::format($myPayment->totalCents()) }}</span>
                                    <x-foodpecker.copy-button :value="Money::toInputString($myPayment->totalCents())" />
                                </dd>
                                <dt class="text-gray-500">Verwendungszweck</dt>
                                <dd class="flex flex-wrap items-baseline gap-x-2">
                                    <span>{{ $myPayment->transferReference() }}</span>
                                    <x-foodpecker.copy-button :value="$myPayment->transferReference()" />
                                </dd>
                            </dl>
                            <p class="mt-2 text-xs text-gray-500">Den GiroCode mit der Banking-App scannen — Empfänger, Betrag und Verwendungszweck sind dann schon ausgefüllt.</p>
                        </div>
                    </div>
                @else
                    <p class="w-full border-t border-gray-200 pt-3 text-sm text-amber-700 dark:border-white/10 dark:text-amber-300">
                        {{ $lead?->first_name ?? 'Der Lead' }} hat noch keine Bankverbindung hinterlegt — frag direkt nach der IBAN.
                    </p>
                @endif
            @endif
        </div>
    @endif

    @if ($isLead)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 px-4 py-3 text-sm dark:border-white/10">
            @if ($lead->hasBankDetails())
                <span>Überweisungen gehen an <strong>{{ $lead->bankAccountHolderName() }}</strong> · <span class="font-mono">{{ $lead->formattedIban() }}</span></span>
            @else
                <span class="text-amber-700 dark:text-amber-300">Hinterlege deine Bankverbindung — dann sehen alle Empfänger, Verwendungszweck und einen GiroCode für ihre Überweisung.</span>
            @endif
            <x-foodpecker.action :action="$this->editBankDetailsAction" />
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
