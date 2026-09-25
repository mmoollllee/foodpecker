@php
    use App\Enums\RoundPhase;

    /** @var \App\Models\Round $round */
    $round = $this->getRound();
    $selected = $this->getSelectedPhase();
@endphp

<x-filament::section heading="Ablauf">
    <x-slot name="description">Klick auf eine Phase: Dort findest du alles, was in ihr passiert.</x-slot>

    @if ($round->phase === RoundPhase::Draft)
        <div class="mb-3 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm text-amber-700 dark:text-amber-300">
            <strong>Entwurf:</strong> Diese Bestellrunde ist noch nicht für die Gruppe sichtbar. Sobald du sie startest, sehen alle Mitglieder die Runde und können Warenkörbe füllen.
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

    <x-foodpecker.phase-steps :round="$round" :selected="$selected" :selectable="true" :with-dates="true" />

    @if ($selected)
        @include('filament.rounds.phases.'.$selected->value, ['round' => $round, 'phase' => $selected])
    @endif
</x-filament::section>
