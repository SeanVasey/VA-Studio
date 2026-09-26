<?php

namespace App\Domain\Commerce\Finalization;

use RuntimeException;

final class FinalizationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Test order finalization could not complete.');
    }
}
