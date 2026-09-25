<?php

namespace App\Mail;

use App\Models\Round;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks a member to take over as lead of a round.
 */
class LeadHandoverRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Round $round,
        public User $requestedBy,
        public string $roundUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->requestedBy->email, $this->requestedBy->fullName())],
            subject: '🪶 Magst du die Lead-Rolle für „'.$this->round->title.'“ übernehmen?',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.lead-handover-requested',
            with: [
                'round' => $this->round,
                'requestedBy' => $this->requestedBy,
                'roundUrl' => $this->roundUrl,
            ],
        );
    }
}
