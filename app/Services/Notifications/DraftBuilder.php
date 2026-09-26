<?php

namespace App\Services\Notifications;

use App\Enums\NotificationKind;
use App\Models\Activity;
use App\Models\NotificationDraft;
use App\Models\Round;
use App\Models\User;
use App\Services\Money\Money;

class DraftBuilder
{
    /**
     * Erzeugt einen Benachrichtigungs-Entwurf, der die Änderungen seit dem
     * letzten gesendeten Draft zusammenfasst. Der Lead kann den Entwurf
     * anschließend frei editieren.
     */
    public function buildDraft(Round $round, NotificationKind $kind, ?User $preparedBy = null): NotificationDraft
    {
        return NotificationDraft::create([
            'round_id' => $round->id,
            'prepared_by_user_id' => $preparedBy?->id,
            'kind' => $kind,
            ...$this->compose($round, $kind, $preparedBy),
            'generated_at' => now(),
        ]);
    }

    /**
     * Subject and text of a notification — without saving a draft, e.g. to
     * show it right in the dialog of a phase change.
     *
     * @return array{subject: string, body: string}
     */
    public function compose(Round $round, NotificationKind $kind, ?User $preparedBy = null): array
    {
        $lastSent = $round->notificationDrafts()->whereNotNull('sent_at')->reorder()->latest('sent_at')->first();
        $since = $lastSent?->sent_at ?? $round->created_at;

        $activities = $round->activities()
            ->with('user')
            ->where('created_at', '>=', $since)
            ->limit(50)
            ->get()
            ->reverse();

        $lines = [];
        $lines[] = 'Hallo zusammen,';
        $lines[] = '';
        $lines[] = $kind->intro($round);
        $lines[] = '';

        if ($kind === NotificationKind::ProposalReady && ($changes = $this->priceChanges($round)) !== []) {
            $lines[] = '**Was die Lieferanten geändert haben:**';
            $lines[] = '';
            array_push($lines, ...$changes);
            $lines[] = '';
        }

        if ($kind === NotificationKind::PaymentDue && $round->lead?->hasBankDetails()) {
            array_push($lines, ...$this->bankDetails($round));
        }

        if ($activities->isNotEmpty()) {
            $lines[] = '**Was sich seit der letzten Nachricht getan hat:**';
            $lines[] = '';
            foreach ($activities as $activity) {
                /** @var Activity $activity */
                $lines[] = '- '.$activity->created_at->format('d.m.').' — '.$activity->describe();
            }
            $lines[] = '';
        }

        $lines[] = 'Den aktuellen Stand seht ihr jederzeit in Foodpecker.';
        $lines[] = '';
        $lines[] = 'Viele Grüße';
        $lines[] = $preparedBy?->fullName() ?? 'Dein Foodpecker';

        return [
            'subject' => $kind->subject($round),
            'body' => implode("\n", $lines),
        ];
    }

    /**
     * Where the money goes — the amounts differ per person, the round shows
     * each their own with a GiroCode.
     *
     * @return array<int, string>
     */
    private function bankDetails(Round $round): array
    {
        $lead = $round->lead;

        return [
            '**Überweisung an:**',
            '',
            '- Empfänger: '.$lead->bankAccountHolderName(),
            '- IBAN: '.$lead->formattedIban(),
            ...($lead->bic ? ['- BIC: '.$lead->bic] : []),
            '- Verwendungszweck: '.$round->title.' – euer Name',
            '',
            'Euren Betrag und einen GiroCode für die Banking-App findet ihr in der Runde.',
            '',
        ];
    }

    /**
     * Confirmed prices that differ from the list, packages that can't be
     * delivered and shipping per supplier.
     *
     * @return array<int, string>
     */
    private function priceChanges(Round $round): array
    {
        $lines = [];

        // Archived products still belong to the round.
        $prices = $round->packagePrices()
            ->with(['priceTier.product' => fn ($query) => $query->withTrashed()])
            ->get();

        foreach ($prices as $price) {
            $tier = $price->priceTier;
            $listPrice = $price->list_price_cents ?? $tier->price_cents;

            if (! $price->is_available) {
                $lines[] = '- '.$tier->product?->name.', '.$tier->label.': nicht lieferbar';
            } elseif ($price->price_cents !== null && $price->price_cents !== $listPrice) {
                $difference = $listPrice > 0 ? round(($price->price_cents - $listPrice) / $listPrice * 100) : 0;
                $lines[] = sprintf(
                    '- %s, %s: %s → %s (%s%d %%)',
                    $tier->product?->name,
                    $tier->label,
                    Money::format($listPrice),
                    Money::format($price->price_cents),
                    $difference > 0 ? '+' : '',
                    $difference,
                );
            }
        }

        foreach ($round->roundSuppliers()->with('supplier')->whereNotNull('shipping_cents')->get() as $record) {
            $lines[] = '- Versand '.$record->supplier?->name.': '.Money::format((int) $record->shipping_cents);
        }

        return $lines;
    }
}
