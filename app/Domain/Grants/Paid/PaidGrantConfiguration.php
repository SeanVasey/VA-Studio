<?php

namespace App\Domain\Grants\Paid;

use Closure;
use Illuminate\Config\Repository;

/** Exact core repository storage only; ArrayAccess parents and object leaves are never evaluated. */
final class PaidGrantConfiguration
{
    public static function read(Repository $configuration, string $key): mixed
    {
        PaidGrantException::require($configuration::class === Repository::class);
        $raw = Closure::bind(fn (): array => $this->items, $configuration, Repository::class);
        PaidGrantException::require($raw instanceof Closure);
        $items = $raw();
        if (array_key_exists($key, $items)) {
            $value = $items[$key];
        } else {
            $value = $items;
            foreach (explode('.', $key) as $part) {
                PaidGrantException::require(is_array($value));
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
        PaidGrantException::require($depth <= 16 && ++$nodes <= 2048);
        if (is_array($value)) {
            foreach ($value as $item) {
                self::plain($item, $depth + 1, $nodes);
            }

            return;
        }
        PaidGrantException::require($value === null || is_bool($value) || is_int($value) || is_string($value));
    }
}
