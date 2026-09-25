@php
    use App\Enums\RoundPhase;

    /** @var \App\Models\Round $round */
    $round = $this->getRound();
@endphp

<x-filament::section :heading="$round->phase === RoundPhase::Draft ? 'Entwurf' : 'Phase: '.$round->phase->getLabel()">
    @if ($round->phase === RoundPhase::Draft)
        <div class="mb-3 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm text-amber-700 dark:text-amber-300">
            Diese Bestellrunde ist noch nicht für die Gruppe sichtbar. Sobald du sie startest, sehen alle Mitglieder die Runde und können Warenkörbe füllen.
        </div>
    @endif

    @if ($round->pendingLead)
        <div class="mb-3 rounded-lg border border-sky-500/30 bg-sky-500/5 p-3 text-sm text-sky-800 dark:text-sky-200">
            @if ($round->pending_lead_user_id === auth()->id())
                {{ $round->lead?->fullName() }} möchte dir die Lead-Rolle für diese Runde übergeben. Oben kannst du annehmen oder ablehnen.
            @else
                Lead-Übergabe an {{ $round->pendingLead->fullName() }} angefragt{{ $round->lead_handover_requested_at ? ' am '.$round->lead_handover_requested_at->format('d.m.Y') : '' }} — wartet auf Zustimmung.
            @endif
        </div>
    @endif

    @if ($round->phase === RoundPhase::Cancelled)
        <div class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/5 p-3 text-sm text-rose-700 dark:text-rose-300">
            Diese Runde wurde abgebrochen.
        </div>
    @endif

    <x-foodpecker.phase-steps :round="$round" />

    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-4">
        @foreach ([
            'Einkauf bis' => $round->shopping_deadline,
            'Verhandlung bis' => $round->negotiation_deadline,
            'Bestätigung bis' => $round->finalization_deadline,
            'Zahlung bis' => $round->payment_deadline,
            'Lieferung' => $round->expected_delivery,
        ] as $label => $date)
            <div>
                <dt class="text-gray-500">{{ $label }}</dt>
                <dd class="font-medium">{{ $date?->format('d.m.Y') ?? '—' }}</dd>
            </div>
        @endforeach
        <div>
            <dt class="text-gray-500">Aufwandsentschädigung Lead</dt>
            <dd class="font-medium">{{ number_format((float) $round->lead_fee_percent, 1, ',', '.') }} %</dd>
        </div>
        <div>
            <dt class="text-gray-500">Vereinsbeitrag</dt>
            <dd class="font-medium">{{ number_format((float) $round->platform_fee_percent, 1, ',', '.') }} %</dd>
        </div>
        @if ($round->max_participants)
            <div>
                <dt class="text-gray-500">Teilnehmer</dt>
                <dd class="font-medium">{{ $round->activeParticipantCount() }} / {{ $round->max_participants }}</dd>
            </div>
        @endif
    </dl>

    @if ($round->pickup_location)
        <div class="mt-4 text-sm">
            <span class="text-gray-500">Abholort:</span>
            <span class="font-medium whitespace-pre-line">{{ $round->pickup_location }}</span>
        </div>
    @endif

    @if ($round->pickupDates->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($round->pickupDates as $pickupDate)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                    📅 {{ $pickupDate->scheduled_at->format('d.m.Y H:i') }}{{ $pickupDate->location ? ' · '.$pickupDate->location : '' }}
                </span>
            @endforeach
        </div>
    @endif
</x-filament::section>
