<?php

namespace App\Domain\Services\Projects;

use RuntimeException;

final class ServiceProjectException extends RuntimeException
{
    public function __construct(public readonly int $status = 409)
    {
        parent::__construct('This service project is unavailable or changed. Refresh before trying again.');
    }
}
