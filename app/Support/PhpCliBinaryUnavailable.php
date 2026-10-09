<?php

namespace App\Support;

use RuntimeException;

/**
 * No validated same-version CLI PHP is available for a child process. Callers translate this into their
 * own existing failure mode; the reason is a fixed code and never carries a path or child output.
 */
final class PhpCliBinaryUnavailable extends RuntimeException
{
    /** @param 'unconfigured'|'invalid_path'|'not_executable'|'probe_failed'|'not_cli'|'version_mismatch'|'build_mismatch'|'unexpected_command' $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('A validated CLI PHP binary is unavailable.');
    }
}
