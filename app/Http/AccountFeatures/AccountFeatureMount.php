<?php

namespace App\Http\AccountFeatures;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use ReflectionProperty;
use Throwable;

/** Default-off registration and stale-cache runtime admission from plain loaded storage. */
final class AccountFeatureMount
{
    public static function admitted(): bool
    {
        try {
            $app = Container::getInstance();
            if ($app::class !== Application::class) {
                return false;
            }
            $instances = (new ReflectionProperty(Container::class, 'instances'))->getRawValue($app);
            if (! is_array($instances)) {
                return false;
            }
            $repository = $instances['config'] ?? null;
            if (! $repository instanceof Repository || $repository::class !== Repository::class) {
                return false;
            }
            $items = (new ReflectionProperty(Repository::class, 'items'))->getRawValue($repository);
            if (! is_array($items) || ! is_array($items['production-account-features'] ?? null)) {
                return false;
            }
            $parent = $items['production-account-features'];
            $remaining = 64;

            return self::plain($parent, $remaining) && ($parent['http_mounted'] ?? null) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function plain(mixed $value, int &$remaining, int $depth = 0): bool
    {
        if (--$remaining < 0 || $depth > 4) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (! self::plain($child, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return is_null($value) || is_string($value) || is_int($value) || is_bool($value);
    }
}
