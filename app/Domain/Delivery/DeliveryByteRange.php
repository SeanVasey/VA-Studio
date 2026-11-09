<?php

namespace App\Domain\Delivery;

use LogicException;

/**
 * One evaluated RFC 9110 byte-range selection for a single private delivery representation.
 *
 * Only one satisfiable `bytes` range that is smaller than the whole file is a partial selection. Everything the server may
 * ignore under RFC 9110 section 14.2 (no Range, an unknown unit, a malformed or multi-range set, an `If-Range` validator that
 * is not this file's strong ETag, or a range covering every byte) is a full selection, so a resumed request can never turn a
 * full download into one that skips the single-redemption rule. The selection carries the size and hash it was evaluated
 * against so the redeeming command can refuse it if the retained target differs.
 */
final class DeliveryByteRange
{
    public const FULL = 'full';

    public const PARTIAL = 'partial';

    public const UNSATISFIABLE = 'unsatisfiable';

    /** Longer headers are ignored rather than parsed: a single resume range is far shorter. */
    private const MAX_HEADER_BYTES = 128;

    private function __construct(public readonly string $outcome, public readonly int $sizeBytes, public readonly string $sha256,
        public readonly int $start, public readonly int $end)
    {
        if ($sizeBytes < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1 || $start < 0 || $end < $start || $end >= $sizeBytes
            || ($outcome === self::PARTIAL && $end - $start + 1 === $sizeBytes)
            || ($outcome !== self::PARTIAL && ($start !== 0 || $end !== $sizeBytes - 1))) {
            throw new LogicException('Invalid delivery byte range.');
        }
    }

    public static function full(int $sizeBytes, string $sha256): self
    {
        return new self(self::FULL, $sizeBytes, $sha256, 0, $sizeBytes - 1);
    }

    /**
     * Strong entity tag for the exact retained bytes, derived from the immutable content hash. It is a domain-separated
     * digest rather than the hash itself, so delivery responses keep not exposing retained evidence hashes (D-20).
     */
    public static function etag(string $sha256): string
    {
        return '"'.hash('sha256', 'vasey-test-delivery-etag-v1:'.$sha256).'"';
    }

    public static function evaluate(?string $range, ?string $ifRange, int $sizeBytes, string $sha256): self
    {
        $full = self::full($sizeBytes, $sha256);
        if ($range === null || strlen($range) > self::MAX_HEADER_BYTES) {
            return $full;
        }
        // If-Range is evaluated first; a weak, date or different validator means "send the whole current representation".
        if ($ifRange !== null && trim($ifRange, " \t") !== self::etag($sha256)) {
            return $full;
        }
        if (preg_match('/\A[ \t]*bytes=([0-9]*)-([0-9]*)[ \t]*\z/iD', $range, $match) !== 1
            || ($match[1] === '' && $match[2] === '')) {
            return $full; // Unknown unit, a list of ranges (any comma), or malformed syntax: ignored, never an error.
        }
        if ($match[1] === '') {
            $suffix = self::number($match[2]);
            if ($suffix === 0) {
                return new self(self::UNSATISFIABLE, $sizeBytes, $sha256, 0, $sizeBytes - 1);
            }
            $start = max(0, $sizeBytes - $suffix);
            $end = $sizeBytes - 1;
        } else {
            $start = self::number($match[1]);
            if ($match[2] !== '' && self::number($match[2]) < $start) {
                return $full; // last-pos before first-pos makes the range-spec invalid, so the header is ignored.
            }
            if ($start >= $sizeBytes) {
                return new self(self::UNSATISFIABLE, $sizeBytes, $sha256, 0, $sizeBytes - 1);
            }
            $end = $match[2] === '' ? $sizeBytes - 1 : min(self::number($match[2]), $sizeBytes - 1);
        }

        return $end - $start + 1 === $sizeBytes ? $full : new self(self::PARTIAL, $sizeBytes, $sha256, $start, $end);
    }

    public function partial(): bool
    {
        return $this->outcome === self::PARTIAL;
    }

    public function unsatisfiable(): bool
    {
        return $this->outcome === self::UNSATISFIABLE;
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    /** Digits beyond the integer range saturate; every such position is past any deliverable file. */
    private static function number(string $digits): int
    {
        $digits = ltrim($digits, '0');

        return $digits === '' ? 0 : (strlen($digits) > 18 ? PHP_INT_MAX : (int) $digits);
    }
}
