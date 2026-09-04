<?php

namespace App\Support;

use InvalidArgumentException;
use stdClass;

/** Project JSON v1: byte-sorted object keys, array order retained, UTF-8 unchanged, integer numbers only. */
final class CanonicalJson
{
    public const VERSION = 'vasey-json-v1';

    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $object = new stdClass;
            foreach ($properties as $key => $item) {
                $object->{(string) $key} = self::normalize($item);
            }

            return $object;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }
            ksort($value, SORT_STRING);
            $object = new stdClass;
            foreach ($value as $key => $item) {
                $object->{(string) $key} = self::normalize($item);
            }

            return $object;
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        throw new InvalidArgumentException('Canonical JSON v1 accepts only JSON objects, lists, UTF-8 strings, integers, booleans and null.');
    }
}
