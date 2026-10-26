<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountActionMail extends Mailable
{
    public function __construct(public string $heading, public string $messageText, public ?string $actionUrl = null, public string $language = 'en')
    {
        $this->locale($language);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading.' — Project Brief');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-action', text: 'mail.account-action-text');
    }
}
