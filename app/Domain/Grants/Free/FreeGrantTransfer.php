<?php

namespace App\Domain\Grants\Free;

use App\Domain\Delivery\PreparedDeliveryStream;

/** Metadata is derived from the re-proved immutable authorization, never browser input. */
final class FreeGrantTransfer
{
    public function __construct(public readonly PreparedDeliveryStream $stream, public readonly string $filename, public readonly string $mimeType) {}

    public function __serialize(): array
    {
        throw new \LogicException('Private transfers cannot be serialized.');
    }

    private function __clone() {}
}
