<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountStatusMail extends Mailable
{
    public function __construct(public string $accountName, public string $accountRole, public string $title, public string $messageBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'BagooPH: '.$this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-status');
    }
}
