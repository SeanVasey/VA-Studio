<?php

namespace App\Domain\Operations;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Plain-text scheduler dead-man's-switch notice (FP-028). Ages, UTC times and the site host only: no data, secret or link. */
final class SchedulerWatchMail extends Mailable
{
    /** @param 'stale'|'recovered' $kind */
    public function __construct(
        public readonly string $kind,
        public readonly string $siteHost,
        public readonly ?string $lastHeartbeat,
        public readonly ?int $ageSeconds,
        public readonly string $episodeStart,
        private readonly string $fromAddress,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->kind === 'stale'
            ? 'VASEY.AUDIO scheduler has stopped ('.$this->siteHost.')'
            : 'VASEY.AUDIO scheduler is running again ('.$this->siteHost.')';

        return new Envelope(from: new Address($this->fromAddress, 'VASEY.AUDIO'), subject: $subject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.scheduler-watch-text');
    }
}
