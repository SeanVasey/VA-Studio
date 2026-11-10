<?php

namespace App\Domain\Merch\Fulfillment\Printful;

use RuntimeException;

/**
 * A Printful call did not produce a usable answer. `$outcomeUnknown` is true when the request may have reached Printful
 * (timeout, connection loss, 5xx, 429): a caller must reconcile by external id before it ever creates again.
 */
class PrintfulException extends RuntimeException
{
    public function __construct(string $code, public readonly bool $outcomeUnknown = false, public readonly ?int $status = null)
    {
        parent::__construct($code);
    }

    /** Machine code only (never a response body, which can carry the recipient's address). */
    public function errorCode(): string
    {
        return $this->getMessage();
    }
}
