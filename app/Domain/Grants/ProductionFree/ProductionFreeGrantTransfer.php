<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Delivery\PreparedDeliveryStream;
use LogicException;

/**
 * One verified private snapshot with metadata derived from the re-proved authorization, never browser input.
 * The original authorization deadline still bounds every chunk; an expired stream stops and reports it.
 */
final class ProductionFreeGrantTransfer
{
    public function __construct(public readonly PreparedDeliveryStream $stream, public readonly string $filename,
        public readonly string $mimeType, private readonly int $deadline) {}

    /** The consumer must accept each complete chunk or throw; the stream is closed in every outcome. */
    public function writeTo(callable $consumer): void
    {
        try {
            $input = $this->stream->stream();
            if (fseek($input, 0) !== 0) {
                throw new ProductionFreeGrantException('artifact_unavailable');
            }
            $bytes = 0;
            while (! feof($input)) {
                ProductionFreeGrantException::require(hrtime(true) < $this->deadline, 'expired');
                $chunk = @fread($input, 1048576);
                // The local snapshot never stalls: '' before EOF is a failure, not something to spin on until the deadline.
                ProductionFreeGrantException::require(is_string($chunk) && ($chunk !== '' || feof($input)), 'artifact_unavailable');
                $bytes += strlen($chunk);
                ProductionFreeGrantException::require($bytes <= $this->stream->sizeBytes, 'artifact_unavailable');
                if ($chunk !== '') {
                    $consumer($chunk);
                }
            }
            ProductionFreeGrantException::require($bytes === $this->stream->sizeBytes, 'artifact_unavailable');
        } finally {
            $this->stream->close();
        }
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
