<?php

namespace App\Http\Tax;

use App\Domain\Grants\PaidTax\PaidTaxConfiguration;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/** Read loaded core storage only: admission must not resolve callback-producing services. */
final class TaxHttpMount
{
    public static function admitted(string $key): bool
    {
        try {
            $app = Container::getInstance();
            if ($app::class !== Application::class) {
                return false;
            }
            $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($app);
            if (! is_array($instances)) {
                return false;
            }
            $config = $instances['config'] ?? null;

            return $config instanceof Repository && $config::class === Repository::class
                && self::environment($app, $instances)
                && PaidTaxConfiguration::read($config, $key) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function environment(Application $app, array $instances): bool
    {
        $environment = $instances['env'] ?? null;
        if ($environment === null) {
            $bindings = (new ReflectionProperty(Container::class, 'bindings'))->getValue($app);
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
