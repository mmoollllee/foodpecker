<?php

namespace App\Services\Notifications;

use App\Models\NotificationDraft;
use App\Models\Round;
use App\Models\User;

class DraftBuilder
{
    /**
     * Erzeugt einen Benachrichtigungs-Entwurf, der die Änderungen seit dem
     * letzten gesendeten Draft zusammenfasst. Der Lead kann den Entwurf
     * anschließend frei editieren.
     */
    public function buildDraft(Round $round, string $kind, ?User $preparedBy = null): NotificationDraft
    {
        $lastSent = $round->notificationDrafts()->whereNotNull('sent_at')->latest('sent_at')->first();
        $since = $lastSent?->sent_at ?? $round->created_at;

        $activities = $round->activities()
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(50)
            ->get();

        $lines = [];
        $lines[] = '# '.$this->subjectFor($kind, $round);
        $lines[] = '';
        $lines[] = 'Hallo zusammen,';
        $lines[] = '';
        $lines[] = $this->introFor($kind, $round);
        $lines[] = '';

        if ($activities->isNotEmpty()) {
            $lines[] = '## Was sich seit der letzten Nachricht geändert hat';
            $lines[] = '';
            foreach ($activities as $a) {
                $when = $a->created_at->format('d.m. H:i');
                $lines[] = '- '.$when.' — '.$a->describe();
            }
            $lines[] = '';
        }

        $lines[] = $this->outroFor($kind, $round);
        $lines[] = '';
        $lines[] = 'Viele Grüße';
        $lines[] = $preparedBy?->fullName() ?? 'Dein Foodpecker';

        return NotificationDraft::create([
            'round_id' => $round->id,
            'prepared_by_user_id' => $preparedBy?->id,
            'kind' => $kind,
            'subject' => $this->subjectFor($kind, $round),
            'body' => implode("\n", $lines),
            'generated_at' => now(),
        ]);
    }

    public function markSent(NotificationDraft $draft): void
    {
        $draft->forceFill(['sent_at' => now()])->save();
    }

    private function subjectFor(string $kind, Round $round): string
    {
        return match ($kind) {
            'shopping_open' => '🛒 Einkaufsphase eröffnet — '.$round->title,
            'negotiation_started' => '📞 Verhandlungen laufen — '.$round->title,
            'proposal_ready' => '🤝 Bestellvorschlag bereit zur Abstimmung — '.$round->title,
            'payment_due' => '💶 Zahlung fällig — '.$round->title,
            'order_placed' => '📦 Bestellung ist raus — '.$round->title,
            'pickup_dates' => '📅 Abholtermine — '.$round->title,
            default => 'Update zur Bestellrunde — '.$round->title,
        };
    }

    private function introFor(string $kind, Round $round): string
    {
        return match ($kind) {
            'shopping_open' => sprintf(
                'die Einkaufsphase der Runde **%s** ist offen — ihr könnt eure Warenkörbe füllen. Deadline: %s.',
                $round->title,
                $round->shopping_deadline?->format('d.m.Y') ?? 'tbd',
            ),
            'negotiation_started' => 'wir gehen jetzt in die Verhandlungsphase über. Ich melde mich, sobald die Hersteller-Preise bestätigt sind.',
            'proposal_ready' => 'es gibt einen finalen Bestellvorschlag, bitte stimmt zeitnah ab — Daumen hoch oder runter (mit Begründung) pro Position.',
            'payment_due' => sprintf(
                'die Bestellung steht. Bitte überweist eure Anteile bis spätestens %s.',
                $round->payment_deadline?->format('d.m.Y') ?? 'baldmöglichst',
            ),
            'order_placed' => 'die Bestellung ist beim Hersteller raus. Voraussichtliche Lieferung: '
                .($round->expected_delivery?->format('d.m.Y') ?? 'noch offen').'.',
            'pickup_dates' => 'die Ware ist da. Bitte tragt euch in einen der Abholtermine ein.',
            default => 'kurzes Update zur Bestellrunde.',
        };
    }

    private function outroFor(string $kind, Round $round): string
    {
        return 'Den aktuellen Stand seht ihr jederzeit im Foodpecker-Panel.';
    }
}
