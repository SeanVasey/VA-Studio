<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Delivery\PreparedDeliveryStream;
use Closure;
use LogicException;

/**
 * Owns only an exact unlinked descriptor; its original closed read cannot mint a new entitlement.
 * The transfer deadline (derived from the snapshot size and the policy's minimum rate, counted from the redemption commit,
 * not the authorization lifetime) bounds the first byte and every chunk; an overrun stops the stream with 410.
 */
final class PaidGrantTransfer
{
    public readonly int $sizeBytes;

    public readonly string $sha256;

    public function __construct(private readonly PreparedDeliveryStream $stream, public readonly string $filename,
        public readonly string $mimeType, private readonly PaidGrantProjectionRead $closedRead, private readonly int $deadline,
        private readonly ?Closure $clock = null)
    {
        $this->sizeBytes = $stream->sizeBytes;
        $this->sha256 = $stream->sha256;
    }

    public function writeTo(callable $consumer): void
    {
        try {
            $this->closedRead->proveBeforeBytes();
            $this->within();
            $this->stream->writeTo(function (string $bytes) use ($consumer): void {
                $this->within();
                $consumer($bytes);
            });
        } finally {
            $this->stream->close();
        }
    }

    private function within(): void
    {
        PaidGrantException::require(($this->clock === null ? hrtime(true) : ($this->clock)()) <= $this->deadline, 410);
    }

    public function __serialize(): array
    {
        throw new LogicException('Paid private transfers cannot be serialized.');
    }

    private function __clone() {}
}
