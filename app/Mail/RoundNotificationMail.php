<?php

namespace App\Mail;

use App\Models\NotificationDraft;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A notification the lead prepared and sent manually. Replies go to the lead.
 */
class RoundNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public NotificationDraft $draft,
        public string $roundUrl,
        public User $sender,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->sender->email, $this->sender->fullName())],
            subject: $this->draft->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.round-notification',
            with: [
                'body' => $this->draft->body,
                'roundUrl' => $this->roundUrl,
                'roundTitle' => $this->draft->round->title,
            ],
        );
    }
}
