<?php

namespace App\Domain\SupportAttachments;

use RuntimeException;

final class AttachmentException extends RuntimeException
{
    public function __construct(public readonly int $status = 503, public readonly string $reason = 'unavailable')
    {
        parent::__construct('Private attachment operation refused.');
    }

    public static function require(bool $condition, int $status = 503, string $reason = 'unavailable'): void
    {
        if (! $condition) {
            throw new self($status, $reason);
        }
    }
}
