<?php

namespace App\Http\Tax;

use App\Domain\Grants\PaidTax\PaidTaxConfiguration;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use ReflectionProperty;
use Throwable;

/** Read loaded core storage only: admission must not resolve callback-producing services. */
final class TaxHttpMount
{
    public static function admitted(string $key): bool
    {
        try {
            $app = Container::getInstance();
            if (! $app instanceof Application) {
                return false;
            }
            $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($app);
            $config = $instances['config'] ?? null;

            return is_array($instances) && $config instanceof Repository && $config::class === Repository::class
                && in_array($instances['env'] ?? null, ['local', 'testing'], true)
                && PaidTaxConfiguration::read($config, $key) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
