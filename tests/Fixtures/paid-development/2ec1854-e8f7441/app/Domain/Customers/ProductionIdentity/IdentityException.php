<?php

namespace App\Domain\Customers\ProductionIdentity;

use RuntimeException;

/** No recipient, proof, credential or chained transport diagnostic is a public error. */
final class IdentityException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('This customer identity request could not be confirmed.');
    }
}
