<?php

namespace App\Services\Invitations;

use App\Enums\GroupRole;
use App\Mail\GroupInvitationMail;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Throwable;

class GroupInvitationService
{
    public function invite(Group $group, string $email, GroupRole $role, ?User $invitedBy = null): GroupInvitation
    {
        $email = mb_strtolower(trim($email));

        try {
            $invitation = $group->invitations()->create([
                'email' => $email,
                'role' => $role->value,
                'invited_by_user_id' => $invitedBy?->id,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // schon vorhanden → reissue
            $invitation = $group->invitations()->where('email', $email)->firstOrFail();
            $invitation->update([
                'role' => $role->value,
                'invited_by_user_id' => $invitedBy?->id,
            ]);
            $invitation->reissueToken();
        }

        $this->send($invitation);

        $group->logActivity('invited', [
            'email' => $email,
            'role' => $role->value,
        ], $invitedBy);

        return $invitation;
    }

    /**
     * Invites every address that does not belong to a member yet. A mail
     * that fails to go out does not stop the others; its invitation stays
     * and can be sent again.
     *
     * @param  array<int, string>  $emails
     * @return array{invited: array<int, string>, members: array<int, string>, failed: array<int, string>}
     */
    public function inviteMany(Group $group, array $emails, GroupRole $role, ?User $invitedBy = null): array
    {
        $memberEmails = $group->members()->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->all();
        $result = ['invited' => [], 'members' => [], 'failed' => []];

        foreach ($emails as $email) {
            if (in_array($email, $memberEmails, true)) {
                $result['members'][] = $email;

                continue;
            }

            try {
                $this->invite($group, $email, $role, $invitedBy);
                $result['invited'][] = $email;
            } catch (Throwable $exception) {
                report($exception);
                $result['failed'][] = $email;
            }
        }

        return $result;
    }

    /**
     * Pulls the addresses out of a pasted list — separated by commas,
     * semicolons, spaces or line breaks, also as "Name <address>" the way
     * mail programs copy them.
     *
     * @return array{valid: array<int, string>, invalid: array<int, string>}
     */
    public static function parseAddresses(string $input): array
    {
        $withoutNames = (string) preg_replace('/[^<>,;\n]*<([^<>]+)>/', ' $1 ', $input);
        $valid = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', $withoutNames, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $address = mb_strtolower(trim($entry));

            if (mb_strlen($address) <= 255 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = $address;
            } else {
                $invalid[] = $entry;
            }
        }

        return ['valid' => array_values(array_unique($valid)), 'invalid' => array_values(array_unique($invalid))];
    }

    public function resend(GroupInvitation $invitation): void
    {
        if ($invitation->isExpired()) {
            $invitation->reissueToken();
        }

        $this->send($invitation);
    }

    public function send(GroupInvitation $invitation): void
    {
        Mail::to($invitation->email)->send(new GroupInvitationMail($invitation));
    }

    public function withdraw(GroupInvitation $invitation): void
    {
        $invitation->delete();

        $invitation->group->logActivity('invitation_withdrawn', ['email' => $invitation->email]);
    }
}
