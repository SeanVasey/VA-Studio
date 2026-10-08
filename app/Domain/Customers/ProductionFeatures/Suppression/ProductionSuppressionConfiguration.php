<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Support\CanonicalJson;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ReflectionProperty;

/** Raw parent/leaf admission of the default-off production-suppression parent; never traverses ArrayAccess. */
final class ProductionSuppressionConfiguration
{
    public const PARENT = 'production-suppression';

    public static function capture(ProductionFeatureConfiguration $features): array
    {
        $raw = self::raw($features);
        if (! ConsentPolicy::keys($raw, ['enabled', 'provider']) || ! is_bool($raw['enabled'])
            || ($raw['provider'] !== null && ! self::provider($raw['provider']))) {
            throw new ProductionFeatureException;
        }

        return ['configuration' => $raw, 'enabled' => $raw['enabled'], 'provider' => $raw['provider'],
            'hash' => $raw['provider'] === null ? null : CanonicalJson::hash($raw['provider'])];
    }

    /** Callback-free guard: a fresh detached raw snapshot must equal the captured one. */
    public static function requireCurrent(array $captured, ProductionFeatureConfiguration $features): void
    {
        if (self::raw($features) !== $captured['configuration']) {
            throw new ProductionFeatureException;
        }
    }

    public static function provider(mixed $provider): bool
    {
        return is_array($provider) && ConsentPolicy::keys($provider, ['adapter', 'version', 'scope', 'reviewReference'])
            && ConsentPolicy::version($provider['adapter']) && ConsentPolicy::version($provider['version'])
            && self::text($provider['scope']) && self::text($provider['reviewReference']);
    }

    private static function text(mixed $value): bool
    {
        return ConsentPolicy::text($value, 200, 800) && trim($value) === $value && preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 0;
    }

    private static function raw(ProductionFeatureConfiguration $features): array
    {
        // 253 admission first: the container's config instance is the captured plain Repository,
        // no resolver hooks exist, and every 253 parent is plain. Then read this parent raw.
        $features->admit();
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue(Container::getInstance());
        $repository = is_array($instances) ? ($instances['config'] ?? null) : null;
        if (! $repository instanceof Repository || get_class($repository) !== Repository::class) {
            throw new ProductionFeatureException;
        }
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($repository);
        if (! is_array($items) || ! isset($items[self::PARENT]) || ! is_array($items[self::PARENT])) {
            throw new ProductionFeatureException;
        }
        $remaining = 64;

        return self::copy($items[self::PARENT], $remaining, 0);
    }

    /** Detached plain copy: PHP references, objects, closures and non-finite floats refuse. */
    private static function copy(mixed $value, int &$remaining, int $depth): mixed
    {
        if (--$remaining < 0 || $depth > 4) {
            throw new ProductionFeatureException;
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::copy($child, $remaining, $depth + 1);
            }

            return $copy;
        }
        if (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
            throw new ProductionFeatureException;
        }

        return $value;
    }
}
