<?php

namespace App\Domain\Catalog;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Internal point-in-time identity, not a signature, publication authority or future eligibility fence. */
final readonly class TrackPublicationManifest
{
    public const SCHEMA_VERSION = 1;

    private array $content;

    private string $contentHash;

    private string $captureTimestamp;

    public function __construct(array $payload, private int $actor, CarbonImmutable $capturedAt)
    {
        if ($actor < 1 || ($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION ||
            ($payload['canonicalization_version'] ?? null) !== CanonicalJson::VERSION) {
            throw new InvalidArgumentException('Invalid publication manifest identity.');
        }

        // Rebuild the tree: neither mutable objects nor caller-owned array references may survive.
        $this->content = self::copy($payload);
        $this->contentHash = CanonicalJson::hash($this->content);
        $this->captureTimestamp = $capturedAt->utc()->toISOString();
    }

    public function payload(): array
    {
        return $this->content;
    }

    public function hash(): string
    {
        return $this->contentHash;
    }

    public function actorId(): int
    {
        return $this->actor;
    }

    public function capturedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->captureTimestamp);
    }

    private static function copy(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = self::copy($item);
            }

            return $copy;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Publication manifests contain only arrays and immutable JSON scalars.');
    }
}
