<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a former member that the owner dissolved their group.
 */
class GroupDissolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $groupName,
        public User $dissolvedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->dissolvedBy->email, $this->dissolvedBy->fullName())],
            subject: '🪶 Die Gruppe „'.$this->groupName.'“ wurde aufgelöst',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.group-dissolved',
            with: [
                'groupName' => $this->groupName,
                'dissolvedBy' => $this->dissolvedBy,
                'loginUrl' => route('filament.global.auth.login'),
            ],
        );
    }
}
