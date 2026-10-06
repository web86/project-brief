<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ProjectActivityMail extends Mailable
{
    public function __construct(public array $payload)
    {
        $this->locale($payload['locale']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->payload['subject']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.project-activity', text: 'mail.project-activity-text');
    }
}
