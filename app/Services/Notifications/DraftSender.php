<?php

namespace App\Services\Notifications;

use App\Enums\RoundPhase;
use App\Mail\RoundNotificationMail;
use App\Models\NotificationDraft;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends a prepared notification draft by mail — one mail per recipient,
 * with the lead as reply-to address.
 */
class DraftSender
{
    /**
     * While the round is open for shopping everybody in the group may still
     * join, afterwards only participants are affected.
     */
    public function defaultsToAllMembers(Round $round): bool
    {
        return in_array($round->phase, [RoundPhase::Draft, RoundPhase::Shopping, RoundPhase::Cancelled], true);
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(Round $round, bool $allMembers): Collection
    {
        if ($allMembers) {
            return $round->group->members()->orderBy('first_name')->get();
        }

        return User::query()
            ->whereIn('id', $round->activeParticipants()->select('user_id'))
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Sends one mail per recipient. A failing address does not stop the
     * others; the draft is marked as sent as soon as one mail went out, so
     * sending again can't deliver it twice.
     *
     * @param  Collection<int, User>  $recipients
     * @return array{sent: int, failed: array<int, string>}
     */
    public function send(NotificationDraft $draft, Collection $recipients, User $sender, string $roundUrl): array
    {
        if ($draft->isSent()) {
            throw ValidationException::withMessages(['draft' => 'Diese Benachrichtigung wurde bereits verschickt.']);
        }

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['draft' => 'Es gibt keine Empfänger für diese Benachrichtigung.']);
        }

        $sent = 0;
        $failed = [];

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new RoundNotificationMail($draft, $roundUrl, $sender));
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $recipient->fullName();
            }
        }

        if ($sent === 0) {
            throw ValidationException::withMessages(['draft' => 'Die Mails konnten nicht verschickt werden. Bitte prüfe die Mail-Einstellungen und versuche es erneut.']);
        }

        $draft->forceFill([
            'sent_at' => now(),
            'sent_by_user_id' => $sender->id,
            'recipient_count' => $sent,
        ])->save();

        return ['sent' => $sent, 'failed' => $failed];
    }
}
