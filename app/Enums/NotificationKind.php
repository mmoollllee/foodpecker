<?php

namespace App\Enums;

use App\Models\Round;
use Filament\Support\Contracts\HasLabel;

/**
 * Occasion of a manually sent round notification. Determines the generated
 * subject and intro text of a draft.
 */
enum NotificationKind: string implements HasLabel
{
    case ShoppingOpen = 'shopping_open';
    case NegotiationStarted = 'negotiation_started';
    case ProposalReady = 'proposal_ready';
    case PaymentDue = 'payment_due';
    case PaymentsComplete = 'payments_complete';
    case OrderPlaced = 'order_placed';
    case PickupReady = 'pickup_dates';
    case RoundCompleted = 'round_completed';
    case RoundCancelled = 'round_cancelled';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::ShoppingOpen => '🛒 Einkaufsphase eröffnet',
            self::NegotiationStarted => '📞 Anpassung läuft',
            self::ProposalReady => '🤝 Vorschlag zur Abstimmung',
            self::PaymentDue => '💶 Zahlung fällig',
            self::PaymentsComplete => '✅ Alle haben bezahlt',
            self::OrderPlaced => '📦 Bestellung ist raus',
            self::PickupReady => '📅 Ware ist da – Abholung',
            self::RoundCompleted => '🎉 Runde abgeschlossen',
            self::RoundCancelled => '🛑 Runde abgebrochen',
            self::Custom => '✍️ Freier Text',
        };
    }

    /**
     * The kind that fits the phase a round has just entered.
     */
    public static function forPhase(RoundPhase $phase): self
    {
        return match ($phase) {
            RoundPhase::Shopping => self::ShoppingOpen,
            RoundPhase::Negotiating => self::NegotiationStarted,
            RoundPhase::Finalizing => self::ProposalReady,
            RoundPhase::Payment => self::PaymentDue,
            RoundPhase::Ordering => self::PaymentsComplete,
            RoundPhase::Delivery => self::OrderPlaced,
            RoundPhase::Pickup => self::PickupReady,
            RoundPhase::Completed => self::RoundCompleted,
            RoundPhase::Cancelled => self::RoundCancelled,
            RoundPhase::Draft => self::Custom,
        };
    }

    public function subject(Round $round): string
    {
        $prefix = match ($this) {
            self::ShoppingOpen => '🛒 Einkaufsphase eröffnet',
            self::NegotiationStarted => '📞 Preise werden angefragt',
            self::ProposalReady => '🤝 Bestellvorschlag bereit zur Abstimmung',
            self::PaymentDue => '💶 Zahlung fällig',
            self::PaymentsComplete => '✅ Alle Zahlungen sind da',
            self::OrderPlaced => '📦 Bestellung ist raus',
            self::PickupReady => '📅 Die Ware ist da',
            self::RoundCompleted => '🎉 Runde abgeschlossen',
            self::RoundCancelled => '🛑 Runde abgebrochen',
            self::Custom => 'Update zur Bestellrunde',
        };

        return $prefix.' — '.$round->title;
    }

    public function intro(Round $round): string
    {
        return match ($this) {
            self::ShoppingOpen => sprintf(
                'die Einkaufsphase der Runde **%s** ist offen — ihr könnt eure Warenkörbe füllen. Deadline: %s.',
                $round->title,
                $round->shopping_deadline?->format('d.m.Y') ?? 'noch offen',
            ),
            self::NegotiationStarted => 'die Einkaufsphase ist vorbei, ich hole jetzt die aktuellen Preise und Versandkosten bei den Lieferanten ein und melde mich mit einem Bestellvorschlag.',
            self::ProposalReady => 'es gibt einen Bestellvorschlag mit den Preisen der Lieferanten. Bitte stimmt zeitnah ab — pro Position Daumen hoch oder runter (mit Begründung), oder mit „Allem zustimmen“ auf einmal.',
            self::PaymentDue => sprintf(
                'die Bestellung steht. Bitte überweist eure Anteile bis spätestens %s — die Beträge seht ihr im Panel unter „Zahlung & Abholung“.',
                $round->payment_deadline?->format('d.m.Y') ?? 'baldmöglichst',
            ),
            self::PaymentsComplete => 'alle Zahlungen sind eingegangen — danke! Ich gebe die Bestellung jetzt bei den Lieferanten auf.',
            self::OrderPlaced => 'die Bestellung ist bei den Lieferanten raus. Voraussichtliche Lieferung: '
                .($round->expected_delivery?->format('d.m.Y') ?? 'noch offen').'.',
            self::PickupReady => 'die Ware ist da! Bitte wählt im Panel euren Abholtermin und holt eure Sachen ab.',
            self::RoundCompleted => 'die Runde ist abgeschlossen — danke fürs Mitmachen! Wenn euch etwas aufgefallen ist, schreibt gern eine Notiz an die Runde.',
            self::RoundCancelled => 'die Runde wurde leider abgebrochen.',
            self::Custom => 'kurzes Update zur Bestellrunde.',
        };
    }
}
