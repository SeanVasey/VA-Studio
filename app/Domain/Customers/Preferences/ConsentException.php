<?php

namespace App\Domain\Customers\Preferences;

final class ConsentException extends \RuntimeException
{
    public function __construct(public readonly int $status = 422)
    {
        parent::__construct('Communication preferences could not be confirmed. Reload before another choice.');
    }
}
