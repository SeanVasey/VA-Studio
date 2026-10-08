<?php

namespace App\Domain\Memberships\Production;

/** Private minimized refusal; technical/source details are never an HTTP disclosure. */
final class MembershipException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('Production membership preparation unavailable.');
    }

    public static function require(bool $condition, string $reason = 'unavailable'): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
