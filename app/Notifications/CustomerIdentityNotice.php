<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Rendering only. The dedicated private capture transport never invokes a mail channel. */
final class CustomerIdentityNotice extends Notification
{
    public function __construct(private readonly string $url, private readonly string $purpose) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your VASEY.AUDIO test account request')
            ->line('This local test request expires after ten minutes. Ignore it if you did not request it.')
            ->action($this->purpose === 'enroll' ? 'Create test account' : 'Reset test password', $this->url)
            ->line('This request cannot claim previous guest orders or change staff access.');
    }

    public function via(object $notifiable): array
    {
        return []; // No external delivery, including when the application's default mailer is SMTP or log.
    }
}
