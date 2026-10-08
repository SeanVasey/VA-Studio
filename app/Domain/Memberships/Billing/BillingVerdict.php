<?php

namespace App\Domain\Memberships\Billing;

/** A named evaluation outcome. Only `settled` carries period/amount facts, and even it awards nothing. */
final readonly class BillingVerdict
{
    public function __construct(public string $outcome, public ?string $reason, public array $facts)
    {
        BillingException::require(in_array($outcome, BillingSchema::OUTCOMES, true)
            && ($outcome === 'settled') === ($reason === null)
            && ($reason === null || preg_match('/\A[a-z0-9_]{1,64}\z/D', $reason) === 1), 'invalid_verdict');
        if ($outcome === 'settled') {
            BillingException::require(is_string($facts['line_period_start'] ?? null) && is_string($facts['line_period_end'] ?? null)
                && $facts['line_period_start'] < $facts['line_period_end'] && is_int($facts['amount_minor'] ?? null) && $facts['amount_minor'] > 0
                && is_string($facts['currency'] ?? null) && preg_match('/\A[A-Z]{3}\z/D', $facts['currency']) === 1, 'invalid_verdict');
        }
    }

    public static function unknown(string $reason, array $facts = []): self
    {
        return new self('unknown', $reason, $facts);
    }
}
