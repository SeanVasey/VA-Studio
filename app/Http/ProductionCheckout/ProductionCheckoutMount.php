<?php

namespace App\Http\ProductionCheckout;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Default-off composition gate for production checkout (lane PCC): provider registration (AppServiceProvider), the route mount
 * (routes/web.php), stale-route runtime admission (ProductionCheckoutPrivacy) and the operator reconciliation command. A copy of
 * the reviewed `MembershipHttpMount` / `ProductionFreeHttpMount` on its own key: plain loaded configuration storage (no
 * repository method, no provider resolution) and the cached environment, never a resolver. Only a literal boolean `true` at
 * `production_checkout.mounted` admits; the shipped value is the literal `false`, so turning it on is a reviewed code change, and
 * ops/production/validate-runtime.php also holds it false.
 *
 * One deliberate difference from the membership and family 256 mounts: with the literal `true` it admits `local`, `testing` and
 * `production`, because production is this chain's target (ExecutionContextV1 admits live funds there). Every other environment
 * never mounts: ExecutionContextV1 refuses test funds outside local/testing and live funds on a staging host, so a mount there
 * could only fail. Mounting approves nothing: every PRODUCTION_CHECKOUT_* runtime switch, the machine policy and the
 * provider configuration stay as shipped.
 */
final class ProductionCheckoutMount
{
    public const ENVIRONMENTS = ['local', 'testing', 'production'];

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
            if (! is_array($items) || ! is_array($items['production_checkout'] ?? null)) {
                return false;
            }
            $parent = $items['production_checkout'];
            $remaining = 128;

            return self::plain($parent, $remaining) && ($parent['mounted'] ?? null) === true
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

        return in_array($environment, self::ENVIRONMENTS, true);
    }
}
