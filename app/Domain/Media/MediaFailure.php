<?php

namespace App\Domain\Media;

use RuntimeException;

class MediaFailure extends RuntimeException
{
    /**
     * @param  ?int  $exitCode  a failed tool's exit status, so a caller can tell the tool's own verdict from a crash
     * @param  ?string  $errorLine  the first line the tool wrote to standard error, raw
     * @param  ?string  $outputLine  the first line the tool wrote to standard output, raw; a tool told to report on standard output
     *                               says why it failed there
     *
     * Both lines are for a server-side log only: they can name files and directories, so they never belong in a message shown to a user.
     */
    public function __construct(public readonly string $failureCode, string $safeMessage, public readonly ?int $exitCode = null, public readonly ?string $errorLine = null, public readonly ?string $outputLine = null)
    {
        parent::__construct($safeMessage);
    }
}
