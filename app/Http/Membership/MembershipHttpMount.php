<?php

namespace App\Http\Membership;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Default-off registration and stale-route runtime admission for the member HTTP mount (C4e). A copy of the reviewed
 * `ProductionFreeHttpMount` on its own parent: plain loaded configuration storage (no repository method, no provider
 * resolution) and a literal cached environment of local or testing. Only a literal boolean `true` at
 * `production-memberships.http_mounted` admits; the shipped value is the literal `false`. Staging and production never
 * mount. Mounting approves nothing: membership, member-grant and capability gates stay closed as shipped.
 */
final class MembershipHttpMount
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
            if (! is_array($items) || ! is_array($items['production-memberships'] ?? null)) {
                return false;
            }
            $parent = $items['production-memberships'];
            $remaining = 128;

            return self::plain($parent, $remaining) && ($parent['http_mounted'] ?? null) === true
                && self::environment($app, $instances);
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

    /** The cached environment, or the literal value captured by the container's own `offsetSet` closure, never a resolver. */
    private static function environment(Application $app, array $instances): bool
    {
        $environment = $instances['env'] ?? null;
        if ($environment === null) {
            $bindings = (new ReflectionProperty(Container::class, 'bindings'))->getRawValue($app);
            if (! is_array($bindings)) {
                return false;
            }
            $binding = $bindings['env'] ?? null;
            if (! is_array($binding)) {
                return false;
            }
            $concrete = $binding['concrete'] ?? null;
            if (! $concrete instanceof Closure) {
                return false;
            }
            $closure = new ReflectionFunction($concrete);
            $method = new ReflectionMethod(Container::class, 'offsetSet');
            if ($closure->getFileName() !== $method->getFileName()
                || $closure->getStartLine() < $method->getStartLine() || $closure->getEndLine() > $method->getEndLine()) {
                return false;
            }
            $environment = $closure->getStaticVariables()['value'] ?? null;
        }

        return in_array($environment, ['local', 'testing'], true);
    }
}
