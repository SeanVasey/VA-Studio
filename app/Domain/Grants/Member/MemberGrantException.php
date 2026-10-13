<?php

namespace App\Domain\Grants\Member;

final class MemberGrantException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable', ?\Throwable $previous = null)
    {
        parent::__construct('Member original unavailable.', 0, $previous);
    }

    public static function require(bool $condition, string $reason = 'unavailable'): void
    {
        if (! $condition) {
            throw new self($reason);
        }
    }
}
