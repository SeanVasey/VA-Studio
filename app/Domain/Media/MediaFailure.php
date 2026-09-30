<?php

namespace App\Domain\Media;

use RuntimeException;

class MediaFailure extends RuntimeException
{
    /** @param  ?int  $exitCode  a failed tool's exit status, so a caller can tell the tool's own verdict from a crash */
    public function __construct(public readonly string $failureCode, string $safeMessage, public readonly ?int $exitCode = null)
    {
        parent::__construct($safeMessage);
    }
}
