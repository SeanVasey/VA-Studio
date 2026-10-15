<?php

namespace App\Domain\Commerce\ProductionFinancialInspection;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

/** Loaded literal policy only; never resolves config, env, a gateway or another service. */
final readonly class FinancialInspectionPolicy
{
    public const PURPOSE = 'paid-financial-candidate-v1';

    private const PINS = [
        'enabled' => true,
        'purpose' => self::PURPOSE,
        'schema_version' => 1,
        'stripe_sdk_version' => '21.3.2',
        'stripe_api_version' => '2026-08-26.dahlia',
    ];

    private function __construct(private Application $app, private Repository $config, private array $snapshot) {}

    public static function capture(): self
    {
        $app = Container::getInstance();
        FinancialInspectionException::require($app::class === Application::class && PHP_INT_SIZE === 8, 'disabled');
        $instances = self::map($app, Container::class, 'instances');
        $config = $instances['config'] ?? null;
        FinancialInspectionException::require($config instanceof Repository && $config::class === Repository::class, 'disabled');

        return new self($app, $config, self::read($app, $config));
    }

    public function prove(): void
    {
        FinancialInspectionException::require(Container::getInstance() === $this->app
            && self::read($this->app, $this->config) === $this->snapshot, 'disabled');
    }

    private static function read(Application $app, Repository $config): array
    {
        $instances = self::map($app, Container::class, 'instances');
        $bindings = self::map($app, Container::class, 'bindings');
        $aliases = self::map($app, Container::class, 'aliases');
        FinancialInspectionException::require(($instances['config'] ?? null) === $config
            && ! array_key_exists('config', $aliases) && ! array_key_exists('env', $aliases), 'disabled');
        $items = self::map($config, Repository::class, 'items');
        $policy = $items['production-financial-inspection'] ?? null;
        FinancialInspectionException::require(is_array($policy) && count($policy) === count(self::PINS), 'disabled');
        foreach (self::PINS as $key => $value) {
            FinancialInspectionException::require(($policy[$key] ?? null) === $value, 'disabled');
        }
        $descriptor = [$instances['env'] ?? null, $bindings['env'] ?? null];
        $environment = $descriptor[0];
        if ($environment === null) {
            FinancialInspectionException::require(is_array($descriptor[1]), 'disabled');
            $closure = $descriptor[1]['concrete'] ?? null;
            FinancialInspectionException::require($closure instanceof Closure, 'disabled');
            $reflection = new ReflectionFunction($closure);
            $method = new ReflectionMethod(Container::class, 'offsetSet');
            FinancialInspectionException::require($reflection->getFileName() === $method->getFileName()
                && $reflection->getStartLine() >= $method->getStartLine()
                && $reflection->getEndLine() <= $method->getEndLine(), 'disabled');
            $environment = $reflection->getStaticVariables()['value'] ?? null;
        }
        FinancialInspectionException::require(is_string($environment) && in_array($environment, ['local', 'testing'], true), 'disabled');

        return [$policy, $descriptor, $environment];
    }

    private static function map(object $object, string $class, string $property): array
    {
        $value = (new ReflectionProperty($class, $property))->getRawValue($object);
        FinancialInspectionException::require(is_array($value), 'disabled');

        return $value;
    }

    public function __serialize(): never
    {
        throw new LogicException('Internal financial configuration cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Internal financial configuration cannot be reconstructed.');
    }
}
