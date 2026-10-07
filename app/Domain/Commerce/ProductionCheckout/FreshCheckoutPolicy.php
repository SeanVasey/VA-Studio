<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ReflectionProperty;

/** Pure terminal admission for NEW checkout writes, captured before extensible media checks. */
final readonly class FreshCheckoutPolicy
{
    private function __construct(private Container $container, private Repository $configuration, private array $aliases) {}

    public static function capture(): self
    {
        $configuration = config();
        $container = Container::getInstance();
        CheckoutException::require($configuration instanceof Repository && $configuration::class === Repository::class, 'disabled', 503);
        $aliases = (new ReflectionProperty(Container::class, 'aliases'))->getValue($container);
        $policy = new self($container, $configuration, $aliases);
        $policy->prove();

        return $policy;
    }

    /** Direct retained binding/items only: no config resolver, service, model or provider callback. */
    public function prove(): void
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        $aliases = (new ReflectionProperty(Container::class, 'aliases'))->getValue($this->container);
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->configuration);
        $policy = is_array($items) ? ($items['production_checkout'] ?? null) : null;
        CheckoutException::require(is_array($instances) && is_array($items) && is_array($policy)
            && Container::getInstance() === $this->container
            && ($instances['config'] ?? null) === $this->configuration && $aliases === $this->aliases
            && ($policy['fresh_checkout_enabled'] ?? null) === true, 'disabled', 503);
    }
}
