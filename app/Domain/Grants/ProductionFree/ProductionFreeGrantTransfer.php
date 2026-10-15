<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Delivery\PreparedDeliveryStream;
use Closure;
use LogicException;

/**
 * One verified private snapshot with metadata derived from the re-proved authorization, never browser input.
 * The transfer deadline (derived from the asset size and the policy's minimum rate, not the authorization TTL) bounds every
 * chunk; an expired stream stops and reports it.
 */
final class ProductionFreeGrantTransfer
{
    private int $lastTick = 0;

    public function __construct(public readonly PreparedDeliveryStream $stream, public readonly string $filename,
        public readonly string $mimeType, private readonly int $deadline, private readonly ?Closure $clock = null,
        private readonly ?int $startedNs = null, private readonly ?int $minimumRate = null,
        private readonly ?int $baseSeconds = null)
    {
        $progress = [$startedNs, $minimumRate, $baseSeconds];
        if (count(array_filter($progress, fn ($value) => $value !== null)) !== 0
            && ($startedNs === null || $minimumRate === null || $baseSeconds === null
                || $startedNs < 0 || $startedNs >= $deadline || $minimumRate < 16384 || $minimumRate > 1073741824
                || $baseSeconds < 0 || $baseSeconds > 600)) {
            throw new LogicException('Invalid transfer progress policy.');
        }
        $this->lastTick = $startedNs ?? 0;
    }

    /** The consumer must accept each complete chunk or throw; the stream is closed in every outcome. */
    public function writeTo(callable $consumer): void
    {
        try {
            $this->within(0);
            $input = $this->stream->stream();
            if (fseek($input, 0) !== 0) {
                throw new ProductionFreeGrantException('artifact_unavailable');
            }
            $bytes = 0;
            while (! feof($input)) {
                $this->within($bytes);
                $chunk = @fread($input, 1048576);
                // The local snapshot never stalls: '' before EOF is a failure, not something to spin on until the deadline.
                ProductionFreeGrantException::require(is_string($chunk) && ($chunk !== '' || feof($input)), 'artifact_unavailable');
                ProductionFreeGrantException::require($bytes + strlen($chunk) <= $this->stream->sizeBytes, 'artifact_unavailable');
                if ($chunk !== '') {
                    $this->within($bytes);
                    $consumer($chunk);
                    // Only a complete returning acceptance earns progress; even the final callback must fit.
                    $bytes += strlen($chunk);
                    $this->within($bytes);
                }
            }
            ProductionFreeGrantException::require($bytes === $this->stream->sizeBytes, 'artifact_unavailable');
            $this->within($bytes);
        } finally {
            $this->stream->close();
        }
    }

    private function within(int $acceptedBytes): void
    {
        $now = $this->clock === null ? hrtime(true) : ($this->clock)();
        ProductionFreeGrantException::require(is_int($now) && $now >= 0, 'expired');
        ProductionFreeGrantException::require($now < $this->deadline, 'expired');
        if ($this->startedNs === null) {
            return;
        }
        ProductionFreeGrantException::require($now >= $this->lastTick, 'expired');
        $this->lastTick = $now;
        $allowance = $this->baseSeconds * 1000000000 + intdiv($acceptedBytes * 1000000000, $this->minimumRate);
        if ($acceptedBytes === 0 && $this->baseSeconds === 0) {
            // One fixed startup chunk from the original start, never a renewed grace period.
            $allowance = intdiv(min($this->stream->sizeBytes, 1048576) * 1000000000, $this->minimumRate);
        }
        ProductionFreeGrantException::require($now - $this->startedNs <= $allowance, 'expired');
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function __serialize(): array
    {
        throw new LogicException('Private transfers cannot be serialized.');
    }

    private function __clone() {}
}
