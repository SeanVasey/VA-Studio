<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use RuntimeException;

/** A refusal with a stable reason code that carries no source value, path or personal data. */
final class LegacyArchiveRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
