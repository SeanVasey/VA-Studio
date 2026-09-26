<?php

namespace App\Domain\Delivery;

use LogicException;

/** Owns one read-only unlinked local snapshot and its disk-budget lease. Never owns the purchased path. */
final class PreparedDeliveryStream
{
    public function __construct(private mixed $input, public readonly string $sha256, public readonly int $sizeBytes,
        private mixed $lease = null)
    {
        if (! is_resource($input) || get_resource_type($input) !== 'stream' || ! preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
            || $sizeBytes < 1 || $sizeBytes > DeliveryAssetFiles::MAX_BYTES) { throw new LogicException('Invalid prepared stream.'); }
    }

    /** @return resource Caller must close this DTO in finally; no path-based response or reopen is permitted. */
    public function stream()
    {
        ActivationPolicy::outsideTransactions();
        if (! is_resource($this->input)) { throw new DeliveryException('target_unavailable'); }
        return $this->input;
    }

    /** Consumer must accept each complete bounded chunk or throw. A committed attempt is not a receipt guarantee. */
    public function writeTo(callable $consumer): void
    {
        try {
            ActivationPolicy::outsideTransactions();
            $input = $this->stream();
            if (fseek($input, 0) !== 0) { throw new DeliveryException('target_unavailable'); }
            $bytes = 0;
            while (! feof($input)) {
                $chunk = @fread($input, min(1048576, $this->sizeBytes - $bytes + 1));
                if (! is_string($chunk) || ($chunk === '' && ! feof($input))) { throw new DeliveryException('target_unavailable'); }
                $bytes += strlen($chunk);
                if ($bytes > $this->sizeBytes) { throw new DeliveryException('target_unavailable'); }
                if ($chunk !== '') { $consumer($chunk); }
            }
            if ($bytes !== $this->sizeBytes) { throw new DeliveryException('target_unavailable'); }
        } finally { $this->close(); }
    }

    public function close(): void
    {
        if (is_resource($this->input)) { fclose($this->input); }
        $this->input = null;
        // Closing releases flock even when cleanup follows an unrelated failure.
        if (is_resource($this->lease)) { fclose($this->lease); }
        $this->lease = null;
    }

    public function __destruct() { $this->close(); }
    public function __serialize(): array { throw new LogicException('Prepared private streams cannot be serialized.'); }
    public function __debugInfo(): array { return ['prepared' => is_resource($this->input), 'sizeBytes' => $this->sizeBytes]; }
    private function __clone() {}
}
