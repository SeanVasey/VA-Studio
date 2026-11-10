<?php

namespace App\Domain\Contracts\RenderFit;

use RuntimeException;

/**
 * Text that would not print completely inside its column, or that a finished PDF does not fully contain. The reason
 * is stable (`unrenderable_text`); the detail names the field, never its content, so it is safe to log.
 */
final class RenderFitException extends RuntimeException
{
    public const REASON = 'unrenderable_text';

    public function __construct(public readonly string $field, public readonly string $why)
    {
        parent::__construct('Contract text would not print completely: '.$field.' ('.$why.').');
    }

    public function reason(): string
    {
        return self::REASON;
    }
}
