<?php

namespace App\Domain\Memberships\Billing;

/**
 * Private minimized refusal. Provider bodies, URLs, headers and keys are never carried or chained. The message names the reason
 * code so a stored failure (`failed_jobs`) says why; only a code-shaped reason enters it, anything else reads as `unavailable`.
 */
final class BillingException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('Production membership billing unavailable ('.(preg_match('/\A[a-z0-9_]{1,80}\z/D', $reason) === 1 ? $reason : 'unavailable').').');
    }

    public static function require(bool $condition, string $reason = 'unavailable'): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
