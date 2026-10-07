<?php

namespace App\Domain\Customers\ProductionFeatures;

use RuntimeException;

final class ProductionFeatureException extends RuntimeException
{
    public function __construct(public readonly int $status = 503)
    {
        parent::__construct('Reload your account before trying again.');
    }
}
