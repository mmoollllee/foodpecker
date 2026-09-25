<?php

namespace App\Services\Notifications;

use App\Enums\NotificationKind;
use App\Models\Activity;
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
    public function buildDraft(Round $round, NotificationKind $kind, ?User $preparedBy = null): NotificationDraft
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

        return NotificationDraft::create([
            'round_id' => $round->id,
            'prepared_by_user_id' => $preparedBy?->id,
            'kind' => $kind,
            'subject' => $kind->subject($round),
            'body' => implode("\n", $lines),
            'generated_at' => now(),
        ]);
    }
}
