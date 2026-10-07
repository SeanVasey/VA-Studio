<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Delivery\PreparedDeliveryStream;
use LogicException;

/** Owns only an exact unlinked descriptor; its original closed read cannot mint a new entitlement. */
final class PaidGrantTransfer
{
    public readonly int $sizeBytes;

    public readonly string $sha256;

    public function __construct(private readonly PreparedDeliveryStream $stream, public readonly string $filename,
        public readonly string $mimeType, private readonly PaidGrantProjectionRead $closedRead, private readonly int $deadline)
    {
        $this->sizeBytes = $stream->sizeBytes;
        $this->sha256 = $stream->sha256;
    }

    public function writeTo(callable $consumer): void
    {
        try {
            $this->closedRead->proveBeforeBytes();
            PaidGrantException::require(hrtime(true) <= $this->deadline, 410);
            $this->stream->writeTo(function (string $bytes) use ($consumer): void {
                PaidGrantException::require(hrtime(true) <= $this->deadline, 410);
                $consumer($bytes);
            });
        } finally {
            $this->stream->close();
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('Paid private transfers cannot be serialized.');
    }

    private function __clone() {}
}
