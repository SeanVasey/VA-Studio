<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

/** Original Tax255 configuration purpose; commit proof reads retained metadata without a resolver. */
final readonly class FreshTaxCheckoutPolicy
{
    private function __construct(
        private Container $container,
        private Repository $configuration,
        private array $aliases,
        private array $environmentBinding,
        private string $environment,
        private array $policy,
    ) {}

    public static function capture(): self
    {
        TaxCheckoutPolicy::requireEnabled();
        $container = Container::getInstance();
        $configuration = config();
        CheckoutException::require($configuration instanceof Repository && $configuration::class === Repository::class, 'disabled', 503);
        $instances = self::property($container, 'instances');
        $bindings = self::property($container, 'bindings');
        $items = self::property($configuration, 'items');
        $policy = $items['production-tax-checkout'] ?? null;
        CheckoutException::require(is_array($policy), 'disabled', 503);
        self::requirePlain($policy);
        $environmentBinding = [$instances['env'] ?? null, $bindings['env'] ?? null];
        $environment = self::environment($environmentBinding);
        $fresh = new self($container, $configuration, self::property($container, 'aliases'), $environmentBinding, $environment, $policy);
        $fresh->prove();

        return $fresh;
    }

    /** Pure original binding/configuration comparison; no provider identity or historical-context renewal. */
    public function prove(): void
    {
        $instances = self::property($this->container, 'instances');
        $bindings = self::property($this->container, 'bindings');
        $items = self::property($this->configuration, 'items');
        $policy = $items['production-tax-checkout'] ?? null;
        $environmentBinding = [$instances['env'] ?? null, $bindings['env'] ?? null];
        CheckoutException::require(Container::getInstance() === $this->container
            && ($instances['config'] ?? null) === $this->configuration
            && self::property($this->container, 'aliases') === $this->aliases
            && $environmentBinding === $this->environmentBinding
            && self::environment($environmentBinding) === $this->environment
            && $policy === $this->policy && ($policy['enabled'] ?? null) === true
            && ($policy['stripe_sdk_version'] ?? null) === TaxCheckoutPolicy::STRIPE_SDK_VERSION
            && ($policy['stripe_api_version'] ?? null) === TaxCheckoutPolicy::STRIPE_API_VERSION, 'disabled', 503);
    }

    private static function property(object $object, string $name): array
    {
        $value = (new ReflectionProperty($object instanceof Repository ? Repository::class : Container::class, $name))->getValue($object);
        CheckoutException::require(is_array($value), 'disabled', 503);

        return $value;
    }

    /** Laravel's literal env instance or its standard offsetSet value closure; never invoke a closure at commit. */
    private static function environment(array $binding): string
    {
        $environment = $binding[0];
        if ($environment === null) {
            $concrete = $binding[1]['concrete'] ?? null;
            CheckoutException::require($concrete instanceof Closure, 'disabled', 503);
            $reflection = new ReflectionFunction($concrete);
            $method = new ReflectionMethod(Container::class, 'offsetSet');
            CheckoutException::require($reflection->getFileName() === $method->getFileName()
                && $reflection->getStartLine() >= $method->getStartLine()
                && $reflection->getEndLine() <= $method->getEndLine(), 'disabled', 503);
            $environment = $reflection->getStaticVariables()['value'] ?? null;
        }
        CheckoutException::require(in_array($environment, ['local', 'testing'], true), 'disabled', 503);

        return $environment;
    }

    private static function requirePlain(array $values): void
    {
        foreach ($values as $value) {
            CheckoutException::require(is_array($value) || is_scalar($value) || $value === null, 'disabled', 503);
            if (is_array($value)) {
                self::requirePlain($value);
            }
        }
    }

    public function __serialize(): never
    {
        throw new LogicException('Tax checkout configuration is an internal server capability.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax checkout configuration cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_tax_checkout_configuration'];
    }
}
