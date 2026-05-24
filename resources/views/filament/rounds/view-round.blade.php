@php
    use App\Enums\PaymentStatus;
    use App\Enums\ProposalStatus;
    use App\Enums\RoundPhase;
    use App\Enums\VoteValue;

    /** @var \App\Models\Round $round */
    $round = $this->round;
    $participants = $round->participants->filter(fn ($p) => ! $p->removed);
    $cartItemsByUser = $round->cartItems->groupBy('user_id');
    $cartItemsByProduct = $round->cartItems->groupBy('product_id');
    $isLead = auth()->id() === $round->lead_user_id;
    $isOwner = auth()->id() === $round->group?->owner_id;
    $isLeadOrOwner = $isLead || $isOwner;

    $hasProposals = $round->proposals->isNotEmpty();
    $hasPayments = $round->payments->isNotEmpty();
    $hasPickups = $round->pickups->isNotEmpty();
    $activitiesCount = $round->activities()->count();
    $draftsCount = $round->notificationDrafts->count();

    $tabs = [
        'overview'  => ['label' => 'Übersicht', 'icon' => 'heroicon-o-eye'],
        'carts'     => ['label' => 'Warenkörbe', 'icon' => 'heroicon-o-shopping-cart', 'count' => $cartItemsByProduct->count()],
        'proposals' => ['label' => 'Vorschläge', 'icon' => 'heroicon-o-document-text', 'count' => $round->proposals->count()],
        'payments'  => ['label' => 'Zahlungen & Abholungen', 'icon' => 'heroicon-o-banknotes', 'count' => $round->payments->count()],
        'activity'  => ['label' => 'Aktivitäten & Benachrichtigungen', 'icon' => 'heroicon-o-clock', 'count' => $activitiesCount + $draftsCount],
    ];
@endphp

<x-filament-panels::page>
    {{-- Phase-Fortschrittsbalken (immer sichtbar oben) --}}
    <x-filament::section :heading="$round->phase === RoundPhase::Draft ? 'Entwurf' : 'Phase: ' . $round->phase->getLabel()">
        @if ($round->phase === RoundPhase::Draft)
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm text-amber-700 dark:text-amber-300 mb-3">
                Diese Bestellrunde ist noch nicht für die Gruppe sichtbar. Sobald du sie startest, sehen alle Mitglieder die Runde und können Warenkörbe füllen.
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            @foreach (RoundPhase::cases() as $phase)
                @if (in_array($phase, [RoundPhase::Draft, RoundPhase::Cancelled], true)) @continue @endif
                @php
                    $active = $phase === $round->phase;
                    $passed = $phase->order() < $round->phase->order();
                @endphp
                <div @class([
                    'flex items-center gap-2 rounded-lg px-3 py-2 text-sm',
                    'bg-amber-500/10 text-amber-700 dark:text-amber-300 ring-1 ring-amber-500/30' => $active,
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $passed,
                    'bg-gray-100 dark:bg-white/5 text-gray-500' => ! $active && ! $passed,
                ])>
                    <x-filament::icon :icon="$phase->getIcon()" class="h-4 w-4"/>
                    <span>{{ $phase->getLabel() }}</span>
                </div>
            @endforeach
        </div>

        <dl class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-3 text-sm">
            @php
                $deadlines = [
                    'Einkauf bis' => $round->shopping_deadline,
                    'Verhandlung bis' => $round->negotiation_deadline,
                    'Bestätigung bis' => $round->finalization_deadline,
                    'Zahlung bis' => $round->payment_deadline,
                    'Lieferung' => $round->expected_delivery,
                ];
            @endphp
            @foreach ($deadlines as $label => $date)
                <div>
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="font-medium">{{ $date?->format('d.m.Y') ?? '—' }}</dd>
                </div>
            @endforeach
            <div>
                <dt class="text-gray-500">Lead-Honorar</dt>
                <dd class="font-medium">{{ number_format($round->lead_fee_percent, 1, ',', '.') }} %</dd>
            </div>
            <div>
                <dt class="text-gray-500">Vereinsbeitrag</dt>
                <dd class="font-medium">{{ number_format($round->platform_fee_percent, 1, ',', '.') }} %</dd>
            </div>
        </dl>

        @if ($round->pickup_location)
            <div class="mt-4 text-sm">
                <span class="text-gray-500">Abholort:</span>
                <span class="font-medium">{{ $round->pickup_location }}</span>
            </div>
        @endif

        @if ($round->pickupDates->isNotEmpty())
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($round->pickupDates as $pd)
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                        📅 {{ $pd->scheduled_at->format('d.m.Y H:i') }}{{ $pd->location ? ' · ' . $pd->location : '' }}
                    </span>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Tab-Navigation --}}
    <div x-data="{ tab: 'overview' }" class="space-y-4">
        <div class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-white/10">
            @foreach ($tabs as $key => $tabMeta)
                <button
                    @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}'
                        ? 'border-amber-500 text-amber-700 dark:text-amber-300'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition"
                    type="button"
                >
                    <x-dynamic-component :component="$tabMeta['icon']" class="h-4 w-4"/>
                    <span>{{ $tabMeta['label'] }}</span>
                    @if (isset($tabMeta['count']) && $tabMeta['count'] > 0)
                        <span class="rounded-full bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-xs">{{ $tabMeta['count'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- TAB: Übersicht --}}
        <div x-show="tab === 'overview'" x-cloak>
            <x-filament::section heading="Zusammenfassung">
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500">Lead</div>
                        <div class="font-semibold">{{ $round->lead?->fullName() ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Teilnehmer</div>
                        <div class="font-semibold">{{ $participants->count() }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Warenkorb-Positionen</div>
                        <div class="font-semibold">{{ $round->cartItems->count() }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Vorschläge</div>
                        <div class="font-semibold">{{ $round->proposals->count() }}</div>
                    </div>
                </div>

                @if ($round->description)
                    <div class="mt-4 text-sm text-gray-700 dark:text-gray-200">
                        <div class="text-gray-500 mb-1">Beschreibung des Leads</div>
                        <p>{{ $round->description }}</p>
                    </div>
                @endif

                @if ($round->availableProducts->isNotEmpty())
                    <div class="mt-4 text-sm">
                        <div class="text-gray-500 mb-2">Sortiment dieser Runde ({{ $round->availableProducts->count() }} Produkte)</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($round->availableProducts as $p)
                                <span class="inline-block rounded bg-gray-100 dark:bg-white/5 px-2 py-0.5 text-xs">{{ $p->name }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- TAB: Warenkörbe --}}
        <div x-show="tab === 'carts'" x-cloak>
            <x-filament::section heading="Warenkörbe der Teilnehmer">
                <x-slot name="description">
                    Alle Mitglieder sehen alle Warenkörbe — volle Transparenz innerhalb der Gruppe.
                </x-slot>

                @if ($cartItemsByProduct->isEmpty())
                    <p class="text-sm text-gray-500">Noch keine Artikel im Warenkorb dieser Runde.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="border-b border-gray-200 dark:border-white/10">
                                <tr class="text-left">
                                    <th class="py-2 pr-4 font-semibold">Produkt</th>
                                    @foreach ($participants as $p)
                                        <th class="py-2 px-3 font-semibold text-center">{{ $p->user?->first_name ?? $p->user?->name ?? '—' }}</th>
                                    @endforeach
                                    <th class="py-2 px-3 font-semibold text-right">∑ Min</th>
                                    <th class="py-2 px-3 font-semibold text-right">∑ Max</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($cartItemsByProduct as $productId => $items)
                                @php
                                    $product = $items->first()->product;
                                    $sumMin = $items->sum(fn ($i) => $i->effectiveMin());
                                    $sumMax = $items->sum(fn ($i) => $i->effectiveMax());
                                @endphp
                                <tr>
                                    <td class="py-2 pr-4">
                                        <div class="font-medium">{{ $product?->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $product?->manufacturer?->name }} · {{ $product?->packagingSummary() }}</div>
                                    </td>
                                    @foreach ($participants as $p)
                                        @php $item = $items->firstWhere('user_id', $p->user_id); @endphp
                                        <td class="py-2 px-3 text-center">
                                            @if ($item)
                                                <span @class([
                                                    'inline-block rounded px-2 py-0.5 text-xs',
                                                    'bg-sky-500/10 text-sky-700 dark:text-sky-300' => $item->quantity_mode->value === 'exact',
                                                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $item->quantity_mode->value === 'flexible',
                                                ])>{{ $item->summary() }}</span>
                                            @else
                                                <span class="text-gray-300">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="py-2 px-3 text-right tabular-nums">{{ number_format($sumMin, 2, ',', '.') }} {{ $product?->unit }}</td>
                                    <td class="py-2 px-3 text-right tabular-nums">{{ number_format($sumMax, 2, ',', '.') }} {{ $product?->unit }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- TAB: Vorschläge --}}
        <div x-show="tab === 'proposals'" x-cloak>
            @if ($round->proposals->isEmpty())
                <x-filament::section heading="Bestellvorschläge">
                    <p class="text-sm text-gray-500">Es liegen noch keine Bestellvorschläge vor. Der Lead kann in der Verhandlungs- oder Bestätigungsphase einen Vorschlag anlegen.</p>
                </x-filament::section>
            @else
                <x-filament::section heading="Bestellvorschläge">
                    <x-slot name="description">
                        Mehrere Vorschläge können parallel zur Abstimmung stehen. Daumen hoch/runter pro Position.
                        Erst ein einstimmig zugestimmter Vorschlag kann als Bestellung markiert werden.
                    </x-slot>

                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ($round->proposals as $proposal)
                            @php
                                $totals = $this->getCalculator()->calculate($proposal);
                                $itemCount = $proposal->items->count();
                                $myVotes = $proposal->items->mapWithKeys(fn ($i) => [
                                    $i->id => $i->votes->firstWhere('user_id', auth()->id())?->value?->value,
                                ]);
                                $isUnanimous = $itemCount > 0 && $proposal->items->every(function ($item) use ($participants) {
                                    $upVotes = $item->votes->where('value', VoteValue::Up)->pluck('user_id');
                                    return $upVotes->count() >= $participants->count();
                                });
                            @endphp

                            <div @class([
                                'rounded-xl border p-4 space-y-3',
                                'border-emerald-500/40 bg-emerald-500/5' => $proposal->status === ProposalStatus::Chosen,
                                'border-gray-200 dark:border-white/10' => $proposal->status !== ProposalStatus::Chosen,
                            ])>
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="font-semibold">{{ $proposal->title }}</h3>
                                            <span class="text-xs px-2 py-0.5 rounded-full
                                                @switch($proposal->status)
                                                  @case(ProposalStatus::Draft) bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300 @break
                                                  @case(ProposalStatus::Published) bg-sky-500/10 text-sky-700 dark:text-sky-300 @break
                                                  @case(ProposalStatus::Chosen) bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 @break
                                                  @default bg-rose-500/10 text-rose-700 dark:text-rose-300
                                                @endswitch
                                            ">{{ $proposal->status->getLabel() }}</span>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-0.5">
                                            Vorgeschlagen von {{ $proposal->proposedBy?->fullName() }}
                                            @if ($proposal->published_at)
                                                · {{ $proposal->published_at->diffForHumans() }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-semibold">{{ \App\Services\Money\Money::format($totals->grandTotalCents) }}</div>
                                        <div class="text-xs text-gray-500">{{ $itemCount }} Positionen</div>
                                    </div>
                                </div>

                                @if ($proposal->description)
                                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $proposal->description }}</p>
                                @endif

                                <div class="space-y-2">
                                    @foreach ($proposal->items as $item)
                                        @php
                                            $ups = $item->votes->where('value', VoteValue::Up)->count();
                                            $downs = $item->votes->where('value', VoteValue::Down)->count();
                                            $mine = $myVotes[$item->id] ?? null;
                                        @endphp
                                        <div class="rounded border border-gray-200 dark:border-white/10 p-3 text-sm">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <span class="font-medium">{{ $item->product?->name }}</span>
                                                    <span class="text-gray-500">— {{ $item->packages_ordered }}× {{ $item->priceTier?->label }}</span>
                                                </div>
                                                <div class="text-right text-xs text-gray-500">
                                                    {{ \App\Services\Money\Money::format($item->total_price_cents) }}
                                                </div>
                                            </div>

                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach ($item->allocations as $alloc)
                                                    <span class="inline-flex items-center gap-1 rounded bg-gray-100 dark:bg-white/5 px-2 py-0.5 text-xs">
                                                        {{ $alloc->user?->first_name ?? '—' }}:
                                                        {{ rtrim(rtrim(number_format((float) $alloc->quantity, 3, ',', '.'), '0'), ',') }} {{ $item->product?->unit }}
                                                    </span>
                                                @endforeach
                                            </div>

                                            <div class="mt-2 flex items-center gap-3">
                                                <button
                                                    wire:click="castVote({{ $item->id }}, 'up')"
                                                    @class([
                                                        'inline-flex items-center gap-1 rounded px-2 py-1 text-xs ring-1',
                                                        'ring-emerald-500 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $mine === 'up',
                                                        'ring-gray-200 dark:ring-white/10 hover:bg-emerald-500/5' => $mine !== 'up',
                                                    ])>
                                                    👍 <span class="tabular-nums">{{ $ups }}</span>
                                                </button>
                                                <button
                                                    wire:click="castVote({{ $item->id }}, 'down')"
                                                    @class([
                                                        'inline-flex items-center gap-1 rounded px-2 py-1 text-xs ring-1',
                                                        'ring-rose-500 bg-rose-500/10 text-rose-700 dark:text-rose-300' => $mine === 'down',
                                                        'ring-gray-200 dark:ring-white/10 hover:bg-rose-500/5' => $mine !== 'down',
                                                    ])>
                                                    👎 <span class="tabular-nums">{{ $downs }}</span>
                                                </button>

                                                @foreach ($item->votes->where('value', VoteValue::Down) as $down)
                                                    @if ($down->reason)
                                                        <span class="text-xs italic text-gray-500 truncate" title="{{ $down->reason }}">
                                                            {{ $down->user?->first_name }}: „{{ \Illuminate\Support\Str::limit($down->reason, 40) }}"
                                                        </span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500 pt-2 border-t border-gray-100 dark:border-white/5">
                                    <div>Versand: {{ \App\Services\Money\Money::format($totals->shippingCents) }}</div>
                                    <div>Honorar Lead: {{ \App\Services\Money\Money::format($totals->leadFeeCents) }}</div>
                                    <div>Vereinsbeitrag: {{ \App\Services\Money\Money::format($totals->platformFeeCents) }}</div>
                                    <div class="text-right font-medium text-gray-700 dark:text-gray-200">∑ {{ \App\Services\Money\Money::format($totals->grandTotalCents) }}</div>
                                </div>

                                @if ($isLeadOrOwner)
                                    <div class="flex gap-2 pt-1">
                                        @if ($proposal->status === ProposalStatus::Draft)
                                            <button wire:click="publishProposal({{ $proposal->id }})"
                                                    class="rounded bg-sky-500/10 hover:bg-sky-500/20 px-3 py-1 text-xs font-medium text-sky-700 dark:text-sky-300">
                                                Zur Abstimmung freigeben
                                            </button>
                                        @endif
                                        @if ($proposal->status === ProposalStatus::Published)
                                            <button wire:click="chooseProposal({{ $proposal->id }})"
                                                    class="rounded bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                                Als finale Bestellung wählen
                                            </button>
                                        @endif
                                        @if ($isUnanimous && $proposal->status === ProposalStatus::Published)
                                            <span class="rounded bg-emerald-500/10 px-2 py-1 text-xs text-emerald-700 dark:text-emerald-300">
                                                ✅ Einstimmig
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>

        {{-- TAB: Zahlungen & Abholungen --}}
        <div x-show="tab === 'payments'" x-cloak class="space-y-4">
            <x-filament::section heading="Zahlungen">
                <x-slot name="description">Der Lead hakt eingegangene Zahlungen hier ab.</x-slot>

                @if ($round->payments->isEmpty())
                    <p class="text-sm text-gray-500">Noch keine Zahlungen — diese werden automatisch erzeugt, sobald ein Vorschlag als finale Bestellung gewählt wurde.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="border-b border-gray-200 dark:border-white/10">
                                <tr class="text-left">
                                    <th class="py-2 pr-4 font-semibold">Teilnehmer</th>
                                    <th class="py-2 px-3 font-semibold text-right">Betrag</th>
                                    <th class="py-2 px-3 font-semibold text-right">Spende</th>
                                    <th class="py-2 px-3 font-semibold text-right">∑</th>
                                    <th class="py-2 px-3 font-semibold">Status</th>
                                    <th class="py-2 px-3 font-semibold">Aktion</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($round->payments->sortBy('user.first_name') as $payment)
                                <tr>
                                    <td class="py-2 pr-4">{{ $payment->user?->fullName() }}</td>
                                    <td class="py-2 px-3 text-right tabular-nums">{{ \App\Services\Money\Money::format($payment->amount_cents) }}</td>
                                    <td class="py-2 px-3 text-right tabular-nums text-amber-700 dark:text-amber-300">
                                        {{ $payment->round_up_donation_cents > 0 ? '+ ' . \App\Services\Money\Money::format($payment->round_up_donation_cents) : '—' }}
                                    </td>
                                    <td class="py-2 px-3 text-right font-semibold tabular-nums">
                                        {{ \App\Services\Money\Money::format($payment->totalCents()) }}
                                    </td>
                                    <td class="py-2 px-3">
                                        <span @class([
                                            'inline-flex rounded px-2 py-0.5 text-xs',
                                            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $payment->status === PaymentStatus::Paid,
                                            'bg-rose-500/10 text-rose-700 dark:text-rose-300' => $payment->status === PaymentStatus::Pending,
                                            'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' => $payment->status === PaymentStatus::Waived,
                                        ])>{{ $payment->status->getLabel() }}</span>
                                        @if ($payment->paid_at)
                                            <span class="text-xs text-gray-500"> · {{ $payment->paid_at->format('d.m.') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3">
                                        @if ($isLeadOrOwner)
                                            <button wire:click="togglePayment({{ $payment->id }})" class="text-xs underline text-gray-600 dark:text-gray-300 hover:text-emerald-600">
                                                umschalten
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Abholungen">
                @if ($round->pickups->isEmpty())
                    <p class="text-sm text-gray-500">Noch keine Abholungen — werden zusammen mit den Zahlungen erzeugt.</p>
                @else
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($round->pickups->sortBy('user.first_name') as $pickup)
                            <label @class([
                                'flex items-center gap-3 rounded-lg border p-3 cursor-pointer',
                                'border-emerald-500/40 bg-emerald-500/5' => $pickup->isPickedUp(),
                                'border-gray-200 dark:border-white/10' => ! $pickup->isPickedUp(),
                            ])>
                                <input type="checkbox" {{ $pickup->isPickedUp() ? 'checked' : '' }}
                                       wire:click="togglePickup({{ $pickup->id }})"
                                       class="h-4 w-4 rounded text-emerald-600 focus:ring-emerald-500" />
                                <div class="flex-1">
                                    <div class="font-medium text-sm">{{ $pickup->user?->fullName() }}</div>
                                    @if ($pickup->isPickedUp())
                                        <div class="text-xs text-emerald-700 dark:text-emerald-300">abgeholt {{ $pickup->picked_up_at?->diffForHumans() }}</div>
                                    @else
                                        <div class="text-xs text-gray-500">offen</div>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- TAB: Aktivitäten & Benachrichtigungen --}}
        <div x-show="tab === 'activity'" x-cloak class="space-y-4">
            <x-filament::section heading="Benachrichtigungs-Entwürfe">
                <x-slot name="description">
                    Der Lead generiert Entwürfe, kann sie hier editieren und dann an alle Teilnehmer verschicken.
                </x-slot>

                @if ($round->notificationDrafts->isEmpty())
                    <p class="text-sm text-gray-500">Noch keine Entwürfe. Über das Menü „Weitere Aktionen → Benachrichtigung vorbereiten" kann der Lead einen erzeugen.</p>
                @else
                    <div class="space-y-3">
                        @foreach ($round->notificationDrafts as $draft)
                            <div @class([
                                'rounded-lg border p-3',
                                'border-emerald-500/30 bg-emerald-500/5' => $draft->sent_at,
                                'border-amber-500/30 bg-amber-500/5'     => ! $draft->sent_at,
                            ])>
                                <div class="flex items-center justify-between gap-2">
                                    <h4 class="font-medium text-sm">{{ $draft->subject }}</h4>
                                    <div class="flex items-center gap-2">
                                        @if ($draft->sent_at)
                                            <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-300">
                                                versendet {{ $draft->sent_at->format('d.m. H:i') }}
                                            </span>
                                        @else
                                            @if ($isLeadOrOwner)
                                                {{ ($this->editDraftAction)(['draft_id' => $draft->id]) }}
                                                <button wire:click="sendDraft({{ $draft->id }})"
                                                        class="rounded bg-sky-500/10 hover:bg-sky-500/20 px-3 py-1 text-xs font-medium text-sky-700 dark:text-sky-300">
                                                    Jetzt senden
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-1 text-xs text-gray-500">
                                    Vorbereitet von {{ $draft->preparedBy?->fullName() ?? '—' }}
                                    @if ($draft->generated_at)
                                        · generiert {{ $draft->generated_at->diffForHumans() }}
                                    @endif
                                </div>
                                <pre class="mt-2 whitespace-pre-wrap text-xs text-gray-700 dark:text-gray-300 max-h-64 overflow-auto p-2 bg-white/50 dark:bg-black/20 rounded border border-gray-200 dark:border-white/10">{{ $draft->body }}</pre>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Aktivitäten-Stream">
                <x-slot name="description">Wer hat wann was geändert? Die letzten 50 Einträge.</x-slot>

                @if ($activitiesCount === 0)
                    <p class="text-sm text-gray-500">Noch keine Aktivitäten.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($round->activities()->limit(50)->get() as $a)
                            <li class="flex items-baseline gap-3">
                                <span class="text-xs text-gray-400 tabular-nums whitespace-nowrap">{{ $a->created_at->format('d.m. H:i') }}</span>
                                <span class="text-gray-700 dark:text-gray-200">
                                    <strong>{{ $a->user?->first_name ?? 'System' }}</strong> · {{ $a->describe() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
