<?php

namespace App\Domain\Media;

/** The malware-scan engines whose results count as evidence. */
final class ScanEngines
{
    /** ClamAV always counts; the synthetic test engine only under testing, or when reading retained history. */
    public static function accepted(mixed $engine, bool $historical = false): bool
    {
        return $engine === 'clamav' || ($engine === 'test-only' && ($historical || app()->environment('testing')));
    }
}
