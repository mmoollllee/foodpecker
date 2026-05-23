<?php

namespace App\Services\Invitations;

use App\Enums\GroupRole;
use App\Mail\GroupInvitationMail;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;

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
        ]);

        return $invitation;
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
    }
}
