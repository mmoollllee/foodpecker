<?php

namespace App\Mail;

use App\Models\Group;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks a member to take the group over as its owner.
 */
class OwnerTransferRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Group $group,
        public User $requestedBy,
        public string $membersUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->requestedBy->email, $this->requestedBy->fullName())],
            subject: '🪶 Magst du die Gruppe „'.$this->group->name.'“ als Owner übernehmen?',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.owner-transfer-requested',
            with: [
                'group' => $this->group,
                'requestedBy' => $this->requestedBy,
                'membersUrl' => $this->membersUrl,
            ],
        );
    }
}
