<?php

namespace App\Domain\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** Plain text, test-labelled order-ready notice. It carries an order reference only: no link, token, price or legal text. */
final class OrderReadyMail extends Mailable
{
    public function __construct(public readonly string $orderReference, private readonly string $fromAddress, public readonly string $deliveryId) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromAddress, 'VASEY.AUDIO'), subject: 'Your VASEY.AUDIO test order is ready');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.order-ready-text', with: ['orderReference' => $this->orderReference]);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Vasey-Delivery' => $this->deliveryId]);
    }
}
