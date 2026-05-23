<?php

namespace App\Mail;

use App\Models\GroupInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GroupInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GroupInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🪶 Einladung zur Gruppe "'.$this->invitation->group->name.'" bei Foodpecker',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.group-invitation',
            with: [
                'invitation' => $this->invitation,
                'group' => $this->invitation->group,
                'acceptUrl' => $this->invitation->acceptUrl(),
                'invitedBy' => $this->invitation->invitedBy?->fullName(),
            ],
        );
    }
}
