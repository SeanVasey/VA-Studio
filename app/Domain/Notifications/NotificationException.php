<?php

namespace App\Domain\Notifications;

final class NotificationException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('The private test notification is unavailable or its outcome cannot be confirmed.');
    }
}
