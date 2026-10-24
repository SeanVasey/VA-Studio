<?php

namespace App\Domain\Reporting;

use RuntimeException;

/** A refusal with a stable reason code; the message never carries data. */
final class ReportingException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Reporting request refused: '.$reason);
    }

    public static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
