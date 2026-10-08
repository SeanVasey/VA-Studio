<?php

namespace App\Domain\Memberships\Billing;

/** Private minimized refusal. Provider bodies, URLs, headers and keys are never carried or chained. */
final class BillingException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('Production membership billing unavailable.');
    }

    public static function require(bool $condition, string $reason = 'unavailable'): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
