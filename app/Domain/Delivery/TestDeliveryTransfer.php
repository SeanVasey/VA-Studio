<?php

namespace App\Domain\Delivery;

use LogicException;

/**
 * One admitted v2 response: a whole-file-verified snapshot plus the selection to serve from it. Writing records exactly the
 * bytes handed to the consumer as coverage, so an interrupted transfer covers only its served prefix and never counts.
 */
final class TestDeliveryTransfer
{
    public function __construct(private readonly PreparedDeliveryStream $prepared, public readonly DeliveryByteRange $range,
        private readonly int $authorizationId, private readonly int $admissionId, private readonly ?int $actorId = null)
    {
        if (! $range->partial() && $range->outcome !== DeliveryByteRange::FULL
            || $range->sizeBytes !== $prepared->sizeBytes || ! hash_equals($range->sha256, $prepared->sha256)) {
            $prepared->close();
            throw new LogicException('Invalid delivery transfer.');
        }
    }

    public function sizeBytes(): int
    {
        return $this->prepared->sizeBytes;
    }

    public function sha256(): string
    {
        return $this->prepared->sha256;
    }

    /** Consumer accepts each chunk or throws; the served prefix is recorded either way, then the snapshot is closed. */
    public function writeTo(callable $consumer): void
    {
        $served = 0;
        $count = function (string $chunk) use ($consumer, &$served): void {
            $consumer($chunk);
            $served += strlen($chunk);
        };
        try {
            $this->range->partial()
                ? $this->prepared->writeRangeTo($count, $this->range->start, $this->range->end)
                : $this->prepared->writeTo($count);
        } finally {
            $this->prepared->close();
            if ($served > 0) {
                app(TestDeliveryCoverageLedger::class)->recordServed($this->authorizationId, $this->admissionId,
                    $this->range->start, $this->range->start + $served - 1, $this->actorId);
            }
        }
    }

    public function close(): void
    {
        $this->prepared->close();
    }

    public function __serialize(): array
    {
        throw new LogicException('Delivery transfers cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['range' => $this->range->outcome, 'sizeBytes' => $this->prepared->sizeBytes];
    }
}
