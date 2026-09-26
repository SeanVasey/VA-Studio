<?php

namespace App\Domain\Delivery;

use App\Support\CanonicalJson;
use InvalidArgumentException;

/** Preserve measured media JSON values without putting floats into the integer-only commerce canonical format. */
final class MediaEvidenceValues
{
    public const VERSION = 'media-values-binary64-v1';

    /** A compact reference; callers still validate and reconstruct the complete immutable media row on every read. */
    public static function reference(mixed $value): array
    {
        return ['encoding' => self::VERSION, 'sha256' => CanonicalJson::hash(self::encode($value))];
    }

    public static function encode(mixed $value): array
    {
        if (is_array($value)) {
            if (array_is_list($value)) { return ['list', array_map(self::encode(...), $value)]; }
            ksort($value, SORT_STRING); $entries = [];
            foreach ($value as $key => $item) { $entries[] = [(string) $key, self::encode($item)]; }
            return ['object', $entries];
        }
        if ($value === null) { return ['null']; }
        if (is_bool($value)) { return ['boolean', $value]; }
        if (is_int($value)) { return ['integer', $value]; }
        if (is_string($value)) { return ['string', $value]; }
        if (is_float($value) && is_finite($value)) { return ['binary64', bin2hex(pack('E', $value))]; }
        throw new InvalidArgumentException('Media evidence must contain finite JSON values.');
    }
}
