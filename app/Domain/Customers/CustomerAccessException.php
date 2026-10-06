<?php

namespace App\Domain\Customers;

use RuntimeException;

final class CustomerAccessException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Customer access is unavailable.');
    }
}
