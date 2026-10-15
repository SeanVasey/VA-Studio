<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Delivery\PreparedDeliveryStream;
use Closure;
use LogicException;

/** One exact private descriptor and the original one-use before-first-byte authority. */
final class PaidTaxTransfer
{
    public readonly int $sizeBytes;

    public readonly string $sha256;

    private bool $used = false;

    public function __construct(private readonly PreparedDeliveryStream $stream, public readonly string $filename,
        public readonly string $mimeType, private readonly PaidTaxProjectionRead $closedRead, private readonly int $deadline,
        private readonly ?Closure $clock = null, private readonly ?PaidTaxRequestBudget $requestBudget = null)
    {
        $this->sizeBytes = $stream->sizeBytes;
        $this->sha256 = $stream->sha256;
    }

    public function writeTo(callable $consumer): void
    {
        try {
            PaidTaxException::require(! $this->used, 409);
            $this->used = true;
            $first = true;
            $this->stream->writeTo(function (string $bytes) use ($consumer, &$first): void {
                // Generic stream/seek/read callbacks have already run. Sample injectable code now.
                $system = hrtime(true);
                $tick = $this->clock === null ? $system : ($this->clock)();
                PaidTaxException::require(is_int($tick) && $tick >= 0);
                PaidTaxException::require($tick <= $this->deadline, 410);
                if ($first) {
                    $this->requestBudget?->proveCurrent();
                    $this->closedRead->proveBeforeBytes();
                    // Original timing values only after the sibling's final source/owner closure.
                    $this->requestBudget?->proveCurrent();
                    $first = false;
                }
                PaidTaxException::require($tick + (hrtime(true) - $system) <= $this->deadline, 410);
                $consumer($bytes);
            });
            PaidTaxException::require(! $first, 503);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->used = true;
        $this->closedRead->abandon();
        $this->stream->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __serialize(): array
    {
        throw new LogicException('Tax private transfers cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax private transfers cannot be deserialized.');
    }

    private function __clone() {}
}
