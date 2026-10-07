<?php

namespace App\Domain\Customers\Listening;

use RuntimeException;

final class ListeningException extends RuntimeException
{
    public function __construct(public readonly int $status = 422)
    {
        parent::__construct('Saved listening library is unavailable.');
    }
}
