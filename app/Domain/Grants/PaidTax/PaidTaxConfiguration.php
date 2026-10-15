<?php

namespace App\Domain\Grants\PaidTax;

use Illuminate\Config\Repository;
use ReflectionProperty;

/** Exact core repository storage only; ArrayAccess parents and object leaves are never evaluated. */
final class PaidTaxConfiguration
{
    public static function read(Repository $configuration, string $key): mixed
    {
        PaidTaxException::require($configuration::class === Repository::class);
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($configuration);
        PaidTaxException::require(is_array($items));
        if (array_key_exists($key, $items)) {
            $value = $items[$key];
        } else {
            $value = $items;
            foreach (explode('.', $key) as $part) {
                PaidTaxException::require(is_array($value));
                if (! array_key_exists($part, $value)) {
                    return null;
                }
                $value = $value[$part];
            }
        }
        $nodes = 0;
        self::plain($value, 0, $nodes);

        return $value;
    }

    private static function plain(mixed $value, int $depth, int &$nodes): void
    {
        PaidTaxException::require($depth <= 16 && ++$nodes <= 2048);
        if (is_array($value)) {
            foreach ($value as $item) {
                self::plain($item, $depth + 1, $nodes);
            }

            return;
        }
        PaidTaxException::require($value === null || is_bool($value) || is_int($value) || is_string($value));
    }
}
