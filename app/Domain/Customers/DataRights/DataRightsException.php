<?php

namespace App\Domain\Customers\DataRights;

use RuntimeException;

/** A fixed reason code and HTTP status; never carries customer data. */
final class DataRightsException extends RuntimeException
{
    public function __construct(public readonly int $status = 404, public readonly string $reason = 'unavailable')
    {
        parent::__construct($reason);
    }
}
