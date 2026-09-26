<?php

namespace App\Domain\Contracts;

use RuntimeException;

final class ContractIssuanceException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Test contract issuance is unavailable.');
    }
}
