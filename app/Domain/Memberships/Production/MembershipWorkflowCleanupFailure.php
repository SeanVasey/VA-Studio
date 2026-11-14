<?php

namespace App\Domain\Memberships\Production;

use RuntimeException;
use Throwable;

/** Preserve both real failures internally; callers still receive a minimized domain refusal. */
final class MembershipWorkflowCleanupFailure extends RuntimeException
{
    public function __construct(Throwable $operation, private readonly Throwable $cleanup)
    {
        parent::__construct('Membership workflow cleanup refused.', previous: $operation);
    }

    public function cleanupFailure(): Throwable
    {
        return $this->cleanup;
    }
}
