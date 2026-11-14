<?php

namespace App\Domain\Services\Invoicing;

use RuntimeException;

/** A refused or unavailable invoicing command. The status is the HTTP-style meaning; the message never carries provider detail. */
final class ServiceInvoiceException extends RuntimeException
{
    public function __construct(public readonly int $status = 409, string $message = 'This service invoice is unavailable or changed. Refresh before trying again.')
    {
        parent::__construct($message);
    }
}
