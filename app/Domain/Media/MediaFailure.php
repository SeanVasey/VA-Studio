<?php

namespace App\Domain\Media;

use RuntimeException;

class MediaFailure extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $safeMessage)
    {
        parent::__construct($safeMessage);
    }
}
