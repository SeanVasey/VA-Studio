<?php

namespace App\Domain\Inquiries\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** Plain-text operator alert. It carries an inquiry reference only: no inquiry content, customer name, address or link. */
final class InquiryAlertMail extends Mailable
{
    public function __construct(public readonly string $inquiryReference, private readonly string $alertSubject, private readonly string $fromAddress) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromAddress, 'VASEY.AUDIO'), subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.inquiry-alert-text', with: ['inquiryReference' => $this->inquiryReference]);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Vasey-Inquiry-Alert' => $this->inquiryReference]);
    }
}
