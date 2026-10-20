<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifyNotificationEmail extends Mailable
{
    public function __construct(public string $verificationUrl, public string $language)
    {
        $this->locale($language);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->language === 'ru' ? 'Подтвердите Email уведомлений — Project Brief' : 'Verify notification email — Project Brief');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.verify-notification-email', text: 'mail.verify-notification-email-text');
    }
}
