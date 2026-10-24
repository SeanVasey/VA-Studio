<?php

namespace App\Domain\Commerce\RefundDispute;

use RuntimeException;

/** A bounded refusal reason. Messages never contain provider identifiers, buyer data or policy text. */
final class RefundDisputeException extends RuntimeException
{
    public const REASONS = ['disabled', 'policy_unset', 'policy_unknown', 'not_found', 'invalid', 'stale', 'busy',
        'conflict', 'not_observed', 'not_qualified', 'already_applied', 'reconciling', 'policy_forbids', 'unavailable'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Refund and dispute operation refused: '.(in_array($reason, self::REASONS, true) ? $reason : 'unavailable').'.');
    }

    public static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
